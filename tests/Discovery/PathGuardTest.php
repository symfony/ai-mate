<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Tests\Discovery;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Mate\Discovery\PathGuard;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class PathGuardTest extends TestCase
{
    #[DataProvider('provideTraversalPaths')]
    public function testRejectsTraversal(string $path)
    {
        $this->assertTrue(PathGuard::hasTraversal($path));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideTraversalPaths(): iterable
    {
        yield 'parent segment' => ['../config.php'];
        yield 'nested parent segment' => ['config/../../secret.php'];
        yield 'trailing parent segment' => ['config/..'];
        yield 'leading parent segment' => ['..'];
        yield 'backslash parent segment' => ['config\\..\\secret.php'];
        yield 'null byte' => ["config.php\0.md"];
    }

    #[DataProvider('provideSafePaths')]
    public function testAcceptsSafePaths(string $path)
    {
        $this->assertFalse(PathGuard::hasTraversal($path));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideSafePaths(): iterable
    {
        yield 'simple file' => ['INSTRUCTIONS.md'];
        yield 'nested file' => ['config/config.php'];
        yield 'dot in filename' => ['my..notes.md'];
        yield 'double dot in filename' => ['archive..2024/file.php'];
        yield 'literal percent-encoded sequence' => ['%2e%2e/config.php'];
        yield 'hidden file' => ['.env.dist'];
        yield 'empty string' => [''];
    }
}
