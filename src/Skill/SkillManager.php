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

use Symfony\AI\Mate\Discovery\ComposerExtensionDiscovery;
use Symfony\AI\Mate\Discovery\PathGuard;
use Symfony\AI\Mate\Exception\AmbiguousSkillException;
use Symfony\AI\Mate\Exception\RuntimeException;
use Symfony\AI\Mate\Exception\SkillNotFoundException;
use Symfony\AI\Mate\Skill\Model\DiscoveredSkill;
use Symfony\AI\Mate\Skill\Model\SkillInstallResult;
use Symfony\AI\Mate\Skill\Model\SkillStatus;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Entry point the skills:* commands work against.
 *
 * Wraps discovery, state and installation so a command depends on one collaborator instead of five,
 * and owns the cross-referencing of intent, recorded facts and filesystem reality.
 *
 * @phpstan-import-type SkillState from SkillStateRepository
 * @phpstan-import-type SkillIssue from SkillStatus
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class SkillManager
{
    public function __construct(
        private string $rootDir,
        private ComposerExtensionDiscovery $extensionDiscovery,
        private SkillDiscovery $skillDiscovery,
        private SkillStateRepository $repository,
        private SkillInstaller $installer,
        private SkillContentHasher $hasher,
        private SkillFrontmatter $frontmatter,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * @return list<DiscoveredSkill>
     */
    public function discover(): array
    {
        $extensions = $this->extensionDiscovery->discover();
        $extensions['_custom'] = $this->extensionDiscovery->discoverRootProject();

        return $this->skillDiscovery->discover($extensions);
    }

    public function reinstall(): SkillInstallResult
    {
        return $this->installer->install($this->discover());
    }

    /**
     * @return list<string>
     */
    public function pruneStrays(bool $dryRun): array
    {
        return $this->installer->pruneStrays($dryRun);
    }

    /**
     * Resolves an installed ("mate-foo") or original ("foo") name to the skill that owns it.
     *
     * Resolution runs against the recorded state, not discovery, so a skill stays addressable while
     * its package is temporarily absent.
     *
     * @return array{package: string, name: string}
     *
     * @throws SkillNotFoundException  when no recorded skill matches
     * @throws AmbiguousSkillException when more than one package owns the name
     */
    public function resolve(string $input): array
    {
        $matches = $this->repository->findAll($input);

        if ([] === $matches) {
            throw new SkillNotFoundException(\sprintf('Unknown skill "%s". Run "mate skills:list" to see what is available.', $input));
        }

        if (\count($matches) > 1) {
            $packages = array_map(static fn (array $match): string => $match['package'], $matches);

            throw new AmbiguousSkillException(\sprintf('Skill "%s" is provided by more than one package (%s).', $input, implode(', ', $packages)));
        }

        $name = $matches[0]['name'];
        if (PathGuard::hasTraversal($name)) {
            throw new SkillNotFoundException(\sprintf('Skill name "%s" is not a valid directory name.', $name));
        }

        return ['package' => $matches[0]['package'], 'name' => $name];
    }

    /**
     * @param 'managed'|'override' $mode
     */
    public function setMode(string $package, string $name, string $mode): void
    {
        $this->repository->setMode($package, $name, $mode);
    }

    public function overrideCopyPath(string $name): string
    {
        return SkillInstaller::OVERRIDE_SKILLS_DIR.'/'.$name;
    }

    /**
     * Copies the package's version of a skill into mate/skills/<name>/ for the user to own.
     *
     * The copy is taken from the declared source rather than the generated folder, so it keeps the
     * original frontmatter name; the installed name is applied at build time as for any other skill.
     *
     * @return string the created path, relative to the project root
     */
    public function createOverrideCopy(string $package, string $name, bool $force): string
    {
        $target = $this->rootDir.'/'.$this->overrideCopyPath($name);

        if (is_dir($target) && !$force) {
            throw new RuntimeException(\sprintf('"%s" already exists. Pass --force to replace it.', $this->overrideCopyPath($name)));
        }

        $skill = $this->findDiscovered($package, $name);
        if (null === $skill) {
            throw new RuntimeException(\sprintf('Skill "%s" is not currently provided by "%s", so there is nothing to copy.', $name, $package));
        }

        $this->filesystem->remove($target);
        $this->filesystem->mirror($skill->absolutePath, $target);

        return $this->overrideCopyPath($name);
    }

    public function removeOverrideCopy(string $name): bool
    {
        $target = $this->rootDir.'/'.$this->overrideCopyPath($name);
        if (!is_dir($target)) {
            return false;
        }

        $this->filesystem->remove($target);

        return true;
    }

    public function hasOverrideCopy(string $name): bool
    {
        return is_dir($this->rootDir.'/'.$this->overrideCopyPath($name));
    }

    /**
     * @return list<SkillStatus>
     */
    public function statusFor(string $installedOrOriginalName): array
    {
        return array_values(array_filter(
            $this->status(),
            static fn (SkillStatus $status): bool => $status->installedName === $installedOrOriginalName
                || $status->originalName === $installedOrOriginalName,
        ));
    }

    /**
     * @return list<SkillStatus>
     */
    public function status(): array
    {
        $discovered = [];
        foreach ($this->discover() as $skill) {
            $discovered[$skill->package][$skill->originalName] = $skill;
        }

        $statuses = [];
        foreach ($this->repository->read() as $package => $config) {
            foreach ($config['skills'] ?? [] as $name => $state) {
                $statuses[] = $this->buildStatus(
                    $package,
                    $name,
                    $state,
                    $config['enabled'],
                    $discovered[$package][$name] ?? null,
                );
                unset($discovered[$package][$name]);
            }
        }

        // Declared but not yet recorded: discovery ran without an install (or the file was hand-edited).
        foreach ($discovered as $package => $skills) {
            foreach ($skills as $name => $skill) {
                $statuses[] = $this->buildStatus($package, $name, ['enabled' => true, 'mode' => 'managed'], true, $skill);
            }
        }

        usort($statuses, static fn (SkillStatus $a, SkillStatus $b): int => $a->installedName <=> $b->installedName);

        return $statuses;
    }

    private function findDiscovered(string $package, string $name): ?DiscoveredSkill
    {
        foreach ($this->discover() as $skill) {
            if ($skill->package === $package && $skill->originalName === $name) {
                return $skill;
            }
        }

        return null;
    }

    /**
     * @param SkillState $state
     */
    private function buildStatus(string $package, string $name, array $state, bool $ownerEnabled, ?DiscoveredSkill $skill): SkillStatus
    {
        $installedName = 'mate-'.$name;
        $enabled = $ownerEnabled && $state['enabled'];
        $recordedState = $state['state'] ?? 'none';

        $issues = $this->collectIssues($name, $installedName, $state, $enabled, $skill);

        $source = $state['source'] ?? null;
        if (null === $source) {
            $source = null !== $skill ? $skill->source : '-';
        }

        return new SkillStatus(
            installedName: $installedName,
            originalName: $name,
            package: $package,
            enabled: $enabled,
            mode: $state['mode'],
            state: $recordedState,
            source: $source,
            status: $this->resolveStatus($enabled, $recordedState, $issues),
            issues: $issues,
        );
    }

    /**
     * @param SkillState $state
     *
     * @return list<SkillIssue>
     */
    private function collectIssues(string $name, string $installedName, array $state, bool $enabled, ?DiscoveredSkill $skill): array
    {
        $issues = [];
        $recordedState = $state['state'] ?? null;

        if (null === $recordedState) {
            $issues[] = ['level' => 'warning', 'message' => 'Declared but never installed; run "mate skills:install".'];

            return $issues;
        }

        $expectedState = $enabled ? $state['mode'] : 'disabled';
        if ($recordedState !== $expectedState) {
            $issues[] = ['level' => 'error', 'message' => \sprintf('Recorded state "%s" does not match intent "%s"; run "mate skills:install".', $recordedState, $expectedState)];
        }

        $agentsTarget = $this->rootDir.'/'.SkillInstaller::AGENTS_SKILLS_DIR.'/'.$installedName;
        $claudeTarget = $this->rootDir.'/'.SkillInstaller::CLAUDE_SKILLS_DIR.'/'.$installedName;

        if ('disabled' === $recordedState) {
            if (is_link($agentsTarget) || file_exists($agentsTarget) || is_link($claudeTarget) || file_exists($claudeTarget)) {
                $issues[] = ['level' => 'error', 'message' => 'Skill is disabled but a generated folder still exists; run "mate skills:prune".'];
            }

            return $issues;
        }

        if ('override' === $recordedState && !is_file($this->rootDir.'/'.$this->overrideCopyPath($name).'/SKILL.md')) {
            $issues[] = ['level' => 'error', 'message' => \sprintf('Overridden skill has no copy at %s/SKILL.md.', $this->overrideCopyPath($name))];
        }

        foreach ($state['targets'] ?? [] as $target) {
            $path = $this->rootDir.'/'.$target;
            if (!is_link($path) && !is_dir($path)) {
                $issues[] = ['level' => 'error', 'message' => \sprintf('Missing generated folder "%s"; run "mate skills:install".', $target)];
            }
        }

        $issues = array_merge($issues, $this->collectMirrorIssues($installedName, $claudeTarget));
        $issues = array_merge($issues, $this->collectContentIssues($installedName, $state, $agentsTarget, $skill));

        return $issues;
    }

    /**
     * @return list<SkillIssue>
     */
    private function collectMirrorIssues(string $installedName, string $claudeTarget): array
    {
        $state = $this->installer->mirrorState($installedName);

        if ('mispointed' === $state) {
            $actual = readlink($claudeTarget);

            return [['level' => 'error', 'message' => \sprintf('Mirror ".claude/skills/%s" points at "%s" instead of the .agents copy; run "mate skills:install".', $installedName, false === $actual ? '?' : $actual)]];
        }

        if ('copied' === $state) {
            return [['level' => 'warning', 'message' => \sprintf('Mirror ".claude/skills/%s" is a copy because symlinks are unavailable on this filesystem.', $installedName)]];
        }

        return [];
    }

    /**
     * @param SkillState $state
     *
     * @return list<SkillIssue>
     */
    private function collectContentIssues(string $installedName, array $state, string $agentsTarget, ?DiscoveredSkill $skill): array
    {
        if (!is_dir($agentsTarget)) {
            return [];
        }

        $issues = [];

        $installedHash = $this->hasher->hash($agentsTarget);
        if (null !== ($state['hash'] ?? null) && $installedHash !== $state['hash']) {
            $issues[] = ['level' => 'error', 'message' => 'Generated content was modified by hand; run "mate skills:install" to restore it.'];
        }

        $sourceDir = 'override' === ($state['state'] ?? null)
            ? $this->rootDir.'/'.$this->overrideCopyPath(substr($installedName, 5))
            : $skill?->absolutePath;

        if (null !== $sourceDir && is_dir($sourceDir)) {
            $sourceHash = $this->hasher->hash($sourceDir);
            if (null !== ($state['source_hash'] ?? null) && $sourceHash !== $state['source_hash']) {
                $issues[] = ['level' => 'warning', 'message' => 'Source changed since the last install; run "mate skills:install".'];
            }
        }

        $skillFile = $agentsTarget.'/SKILL.md';
        $content = is_file($skillFile) ? file_get_contents($skillFile) : false;
        if (false === $content) {
            $issues[] = ['level' => 'error', 'message' => 'Installed skill has no readable SKILL.md.'];

            return $issues;
        }

        $frontmatter = $this->frontmatter->parse($content);
        if (null === $frontmatter || ($frontmatter['name'] ?? null) !== $installedName) {
            $issues[] = ['level' => 'error', 'message' => \sprintf('Installed SKILL.md does not declare "name: %s".', $installedName)];
        }

        return $issues;
    }

    /**
     * @param list<SkillIssue> $issues
     *
     * @return 'ok'|'disabled'|'not installed'|'stale'|'broken'
     */
    private function resolveStatus(bool $enabled, string $recordedState, array $issues): string
    {
        foreach ($issues as $issue) {
            if ('error' === $issue['level']) {
                return 'broken';
            }
        }

        if (!$enabled) {
            return 'disabled';
        }

        if ('none' === $recordedState) {
            return 'not installed';
        }

        if ([] !== $issues) {
            return 'stale';
        }

        return 'ok';
    }
}
