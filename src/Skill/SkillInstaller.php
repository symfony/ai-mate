<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Skill;

use Psr\Log\LoggerInterface;
use Symfony\AI\Mate\Skill\Model\DiscoveredSkill;
use Symfony\AI\Mate\Skill\Model\SkillInstallResult;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Idempotent reconciler that rebuilds the generated skill folders from source + intent.
 *
 * A managed skill is a real copy, never a symlink into vendor/: the point is that you can read and
 * diff exactly what your agent will load. Only the .claude/skills/ mirror is a relative symlink to
 * the canonical .agents/skills/ copy, so the two can never drift apart; on filesystems without
 * symlink support it falls back to a second copy.
 *
 * Every outcome is recorded back into mate/extensions.php, next to the intent it derives from.
 * Skills whose source vanished are pruned immediately, together with their entry.
 *
 * The generated folders are deliberately not git-ignored: because they are plain copies, committing
 * them makes an upstream skill change visible in review instead of arriving silently.
 *
 * @phpstan-import-type SkillState from SkillStateRepository
 * @phpstan-import-type ExtensionConfigMap from SkillStateRepository
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class SkillInstaller
{
    public const AGENTS_SKILLS_DIR = '.agents/skills';
    public const CLAUDE_SKILLS_DIR = '.claude/skills';
    public const OVERRIDE_SKILLS_DIR = 'mate/skills';

    public function __construct(
        private string $rootDir,
        private SkillStateRepository $repository,
        private SkillFrontmatter $frontmatter,
        private SkillContentHasher $hasher,
        private LinkerInterface $linker,
        private Filesystem $filesystem,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<DiscoveredSkill> $skills
     */
    public function install(array $skills): SkillInstallResult
    {
        $config = $this->repository->read();

        $discovered = [];
        foreach ($skills as $skill) {
            $discovered[$skill->package][$skill->originalName] = $skill;
        }

        $vanished = $this->dropVanished($config, $discovered);
        $config = $vanished['config'];
        $removed = $vanished['removed'];

        $installed = [];
        $skipped = [];
        $notices = [];
        $states = [];
        $active = [];

        foreach ($skills as $skill) {
            $config = $this->ensureEntry($config, $skill);

            $state = $config[$skill->package]['skills'][$skill->originalName];
            $enabled = $config[$skill->package]['enabled'] && $state['enabled'];

            if (!$enabled) {
                if ($this->removeTargets($skill->installedName, $state['targets'] ?? [])) {
                    $removed[] = $skill->installedName;
                }

                $config[$skill->package]['skills'][$skill->originalName] = array_merge($state, [
                    'state' => 'disabled',
                    'source' => $skill->source,
                    'source_hash' => null,
                    'hash' => null,
                    'targets' => [],
                ]);
                $states[$skill->installedName] = 'disabled';

                continue;
            }

            $override = 'override' === $state['mode'];
            $sourceDir = $override ? $this->overrideSourceDir($skill) : $skill->absolutePath;

            if (!is_dir($sourceDir) || !is_file($sourceDir.'/SKILL.md')) {
                $this->logger->warning('Skipping skill with missing source', [
                    'skill' => $skill->installedName,
                    'source' => $sourceDir,
                    'mode' => $state['mode'],
                ]);
                $skipped[$skill->installedName] = $override
                    ? \sprintf('override source missing in %s/', self::OVERRIDE_SKILLS_DIR)
                    : 'source directory missing';

                continue;
            }

            $wasInstalled = isset($state['state']) && 'disabled' !== $state['state'];

            $build = $this->buildSkill($skill, $sourceDir, $override, $state);
            if (null !== $build['notice']) {
                $notices[] = $build['notice'];
            }

            $config[$skill->package]['skills'][$skill->originalName] = array_merge($state, $build['facts']);

            $states[$skill->installedName] = $override ? 'override' : 'managed';
            $active[] = $skill->installedName;

            if (!$wasInstalled) {
                $installed[] = $skill->installedName;
            }
        }

        $this->repository->write($config);

        $removed = array_merge($removed, $this->pruneStrays(false));
        $removed = array_values(array_unique($removed));
        sort($removed);
        sort($active);

        return new SkillInstallResult($installed, $removed, $skipped, $active, $notices, $states);
    }

    /**
     * Removes generated mate-* folders that no longer belong to an active skill.
     *
     * @return list<string>
     */
    public function pruneStrays(bool $dryRun): array
    {
        $expected = [];
        foreach ($this->repository->read() as $config) {
            foreach ($config['skills'] ?? [] as $state) {
                foreach ($state['targets'] ?? [] as $target) {
                    $expected[$target] = true;
                }
            }
        }

        $strays = [];
        foreach ([self::AGENTS_SKILLS_DIR, self::CLAUDE_SKILLS_DIR] as $baseDir) {
            $absoluteBase = $this->rootDir.'/'.$baseDir;
            if (!is_dir($absoluteBase)) {
                continue;
            }

            $entries = scandir($absoluteBase);
            if (false === $entries) {
                continue;
            }

            foreach ($entries as $entry) {
                if ('.' === $entry || '..' === $entry || !str_starts_with($entry, 'mate-')) {
                    continue;
                }

                if (isset($expected[$baseDir.'/'.$entry])) {
                    continue;
                }

                $strays[$entry] = true;
                if (!$dryRun) {
                    $this->filesystem->remove($absoluteBase.'/'.$entry);
                }
            }
        }

        $names = array_keys($strays);
        sort($names);

        return $names;
    }

    /**
     * Classifies the .claude/skills mirror against the .agents copy it has to shadow.
     *
     * A link that resolves somewhere else is "mispointed" rather than merely present: it has to count
     * as out of date, otherwise install would skip the skill and leave the mirror wrong forever.
     *
     * @return 'linked'|'mispointed'|'copied'|'missing'
     */
    public function mirrorState(string $installedName): string
    {
        $mirrorPath = $this->rootDir.'/'.self::CLAUDE_SKILLS_DIR.'/'.$installedName;

        if (!is_link($mirrorPath)) {
            return is_dir($mirrorPath) ? 'copied' : 'missing';
        }

        $expected = $this->mirrorLinkTarget($installedName);
        if (readlink($mirrorPath) === $expected) {
            return 'linked';
        }

        // An absolute or otherwise differently spelled link still shadows the right folder.
        $resolved = realpath($mirrorPath);
        if (false !== $resolved && $resolved === realpath(\dirname($mirrorPath).'/'.$expected)) {
            return 'linked';
        }

        return 'mispointed';
    }

    /**
     * @param ExtensionConfigMap                            $config
     * @param array<string, array<string, DiscoveredSkill>> $discovered
     *
     * @return array{config: ExtensionConfigMap, removed: list<string>}
     */
    private function dropVanished(array $config, array $discovered): array
    {
        $removed = [];
        foreach ($config as $package => $entry) {
            foreach ($entry['skills'] ?? [] as $name => $state) {
                if (isset($discovered[$package][$name])) {
                    continue;
                }

                if ($this->removeTargets('mate-'.$name, $state['targets'] ?? [])) {
                    $removed[] = 'mate-'.$name;
                }

                unset($config[$package]['skills'][$name]);
            }

            if ([] === ($config[$package]['skills'] ?? null)) {
                unset($config[$package]['skills']);
            }
        }

        return ['config' => $config, 'removed' => $removed];
    }

    /**
     * @param ExtensionConfigMap $config
     *
     * @return ExtensionConfigMap
     */
    private function ensureEntry(array $config, DiscoveredSkill $skill): array
    {
        if (!isset($config[$skill->package])) {
            $config[$skill->package] = ['enabled' => true];
        }

        if (!isset($config[$skill->package]['skills'][$skill->originalName])) {
            $config[$skill->package]['skills'][$skill->originalName] = ['enabled' => true, 'mode' => 'managed'];
        }

        return $config;
    }

    /**
     * @param SkillState $previous
     *
     * @return array{
     *     facts: array{state: 'managed'|'override', source: string, source_hash: string|null, hash: string|null, targets: list<string>},
     *     notice: string|null,
     * }
     */
    private function buildSkill(DiscoveredSkill $skill, string $sourceDir, bool $override, array $previous): array
    {
        $agentsTarget = $this->rootDir.'/'.self::AGENTS_SKILLS_DIR.'/'.$skill->installedName;
        $claudeTarget = $this->rootDir.'/'.self::CLAUDE_SKILLS_DIR.'/'.$skill->installedName;

        $source = $override ? self::OVERRIDE_SKILLS_DIR.'/'.$skill->originalName : $skill->source;
        $sourceHash = $this->hasher->hash($sourceDir);

        $facts = [
            'state' => $override ? 'override' : 'managed',
            'source' => $source,
            'source_hash' => $sourceHash,
            'targets' => [
                self::AGENTS_SKILLS_DIR.'/'.$skill->installedName,
                self::CLAUDE_SKILLS_DIR.'/'.$skill->installedName,
            ],
        ];

        if ($this->isUpToDate($previous, $sourceHash, $agentsTarget, $skill->installedName)) {
            $facts['hash'] = $previous['hash'] ?? null;

            return ['facts' => $facts, 'notice' => null];
        }

        $this->filesystem->remove($agentsTarget);
        $this->copyDirectory($sourceDir, $agentsTarget);
        $this->rewriteSkillName($agentsTarget.'/SKILL.md', $skill->installedName);

        $notice = null;
        if (!$this->linkMirror($claudeTarget, $skill->installedName, $agentsTarget)) {
            $notice = \sprintf('Skill "%s" was mirrored into .claude/skills/ as a copy because symlinks are unavailable.', $skill->installedName);
        }

        $facts['hash'] = $this->hasher->hash($agentsTarget);

        return ['facts' => $facts, 'notice' => $notice];
    }

    /**
     * @param SkillState $previous
     */
    private function isUpToDate(array $previous, ?string $sourceHash, string $agentsTarget, string $installedName): bool
    {
        if (null === $sourceHash || ($previous['source_hash'] ?? null) !== $sourceHash) {
            return false;
        }

        $installedHash = $previous['hash'] ?? null;
        if (null === $installedHash || $this->hasher->hash($agentsTarget) !== $installedHash) {
            return false;
        }

        return \in_array($this->mirrorState($installedName), ['linked', 'copied'], true);
    }

    /**
     * The relative target every .claude/skills mirror points at.
     */
    private function mirrorLinkTarget(string $installedName): string
    {
        return '../../'.self::AGENTS_SKILLS_DIR.'/'.$installedName;
    }

    private function overrideSourceDir(DiscoveredSkill $skill): string
    {
        return $this->rootDir.'/'.self::OVERRIDE_SKILLS_DIR.'/'.$skill->originalName;
    }

    /**
     * @param list<string> $recordedTargets
     */
    private function removeTargets(string $installedName, array $recordedTargets): bool
    {
        $targets = $recordedTargets;
        $targets[] = self::AGENTS_SKILLS_DIR.'/'.$installedName;
        $targets[] = self::CLAUDE_SKILLS_DIR.'/'.$installedName;

        $anyRemoved = false;
        foreach (array_unique($targets) as $target) {
            $path = $this->rootDir.'/'.$target;
            if (is_link($path) || file_exists($path)) {
                $anyRemoved = true;
            }

            $this->filesystem->remove($path);
        }

        return $anyRemoved;
    }

    private function rewriteSkillName(string $skillFile, string $installedName): void
    {
        $content = file_get_contents($skillFile);
        if (false === $content) {
            return;
        }

        $rewritten = $this->frontmatter->rewriteName($content, $installedName);
        if ($rewritten !== $content) {
            $this->filesystem->dumpFile($skillFile, $rewritten);
        }
    }

    private function linkMirror(string $mirrorPath, string $installedName, string $agentsTarget): bool
    {
        $this->filesystem->remove($mirrorPath);
        $this->filesystem->mkdir(\dirname($mirrorPath));

        $relativeTarget = $this->mirrorLinkTarget($installedName);

        if ($this->linker->link($relativeTarget, $mirrorPath)) {
            return true;
        }

        $this->logger->warning('Failed to create skill mirror symlink; copying the skill into .claude/skills/ instead', [
            'mirror' => $mirrorPath,
            'target' => $relativeTarget,
        ]);

        $this->copyDirectory($agentsTarget, $mirrorPath);

        return false;
    }

    private function copyDirectory(string $source, string $destination): void
    {
        // A link inside the source is skipped instead of recreated: it would either dangle in the copy
        // or point back out of it, and a skill folder is meant to be self-contained.
        $withoutLinks = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $file): bool => !$file->isLink(),
        );

        $this->filesystem->mirror(
            $source,
            $destination,
            new \RecursiveIteratorIterator($withoutLinks, \RecursiveIteratorIterator::SELF_FIRST),
        );
    }
}
