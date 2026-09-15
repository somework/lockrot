<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Lock;

use Lockrot\Exception\ConfigException;
use Lockrot\Lock\LockLineIndex;
use PHPUnit\Framework\TestCase;

final class LockLineIndexTest extends TestCase
{
    private const LARAVEL_LOCK = __DIR__.'/../../fixtures/skeletons/laravel/composer.lock';

    /**
     * Both numbers come from `grep -n '"name": "<package>"' composer.lock` against the committed
     * fixture; they change only if the fixture itself is re-recorded.
     */
    public function testLineOfAPackageInPackages(): void
    {
        self::assertSame(1063, LockLineIndex::fromFile(self::LARAVEL_LOCK)->lineOf('laravel/framework'));
    }

    public function testLineOfAPackageInPackagesDev(): void
    {
        self::assertSame(7232, LockLineIndex::fromFile(self::LARAVEL_LOCK)->lineOf('phpunit/phpunit'));
    }

    public function testUnknownPackageHasNoLine(): void
    {
        self::assertNull(LockLineIndex::fromFile(self::LARAVEL_LOCK)->lineOf('acme/not-in-this-lock'));
    }

    public function testEmptyIndexHasNoLines(): void
    {
        self::assertNull(LockLineIndex::empty()->lineOf('laravel/framework'));
    }

    public function testFirstOccurrencePerNameWins(): void
    {
        $json = <<<'JSON'
            {
                "packages": [
                    {
                        "name": "acme/first",
                        "version": "1.0.0"
                    }
                ],
                "packages-dev": [
                    {
                        "name": "acme/first",
                        "version": "2.0.0"
                    }
                ]
            }
            JSON;
        self::assertSame(4, LockLineIndex::fromString($json)->lineOf('acme/first'));
    }

    /**
     * Author blocks carry a "name" member too, but an author name is not a package name (no
     * vendor/ prefix), so it never lands in the index and can never shadow a real package.
     */
    public function testAuthorNamesAreNotIndexed(): void
    {
        $json = <<<'JSON'
            {
                "packages": [
                    {
                        "name": "acme/pkg",
                        "authors": [
                            {
                                "name": "KyleKatarn"
                            }
                        ]
                    }
                ]
            }
            JSON;
        $index = LockLineIndex::fromString($json);
        self::assertSame(4, $index->lineOf('acme/pkg'));
        self::assertNull($index->lineOf('KyleKatarn'));
    }

    public function testMissingFileThrows(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/not found/');
        LockLineIndex::fromFile(sys_get_temp_dir().'/lockrot-no-such-lock-'.uniqid().'.lock');
    }

    public function testDirectoryPathThrows(): void
    {
        $this->expectException(ConfigException::class);
        LockLineIndex::fromFile(sys_get_temp_dir());
    }

    public function testUnreadableFileThrows(): void
    {
        $path = sys_get_temp_dir().'/lockrot-unreadable-'.uniqid('', true).'.lock';
        file_put_contents($path, '{"packages":[]}');
        chmod($path, 0000);
        clearstatcache(true, $path);
        if (is_readable($path)) {
            chmod($path, 0644);
            unlink($path);
            self::markTestSkipped('this user can read a 0000 file (running as root?)');
        }

        try {
            $this->expectException(ConfigException::class);
            $this->expectExceptionMessageMatches('/Cannot read/');
            LockLineIndex::fromFile($path);
        } finally {
            chmod($path, 0644);
            unlink($path);
        }
    }
}
