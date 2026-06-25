<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Discovery;

/**
 * Guards package-relative paths declared in composer.json against directory traversal.
 *
 * Extension paths (scan-dirs, includes, instructions) are declared by third-party
 * Composer packages and joined onto the project's vendor directory. A path that
 * escapes its base directory via a parent (..) segment must be rejected so a
 * malicious package cannot reach files outside its own directory.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class PathGuard
{
    /**
     * Returns true when the relative path attempts to traverse out of its base
     * directory via a parent (..) segment or contains a null byte.
     *
     * The check is segment-based on purpose: a substring match on ".." would also
     * reject legitimate names such as "my..notes.md".
     */
    public static function hasTraversal(string $path): bool
    {
        if (str_contains($path, "\0")) {
            return true;
        }

        $segments = preg_split('#[/\\\\]+#', $path);
        if (false === $segments) {
            return true;
        }

        foreach ($segments as $segment) {
            if ('..' === $segment) {
                return true;
            }
        }

        return false;
    }
}
