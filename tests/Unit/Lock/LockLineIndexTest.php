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
     * A package entry is not the only object in a lock with a "name" member: authors have one, and
     * so does `extra.thanks`, whose value is a real package name. Only the member of the entry
     * itself counts.
     */
    public function testNestedNameMembersAreNotIndexed(): void
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

    /**
     * `extra.thanks.name` names another package entirely, and it is spelled exactly like a package
     * name, so "first occurrence wins" alone would map that package to the wrong line — here to a
     * line inside a different entry, 6 lines above its own.
     */
    public function testThanksTargetDoesNotShadowTheRealEntry(): void
    {
        $json = <<<'JSON'
            {
                "packages": [
                    {
                        "name": "acme/first",
                        "extra": {
                            "thanks": {
                                "name": "acme/later",
                                "url": "https://example.test/acme/later"
                            }
                        }
                    },
                    {
                        "name": "acme/later",
                        "version": "2.0.0"
                    }
                ],
                "packages-dev": []
            }
            JSON;
        $index = LockLineIndex::fromString($json);
        self::assertSame(4, $index->lineOf('acme/first'));
        self::assertSame(13, $index->lineOf('acme/later'));
    }

    /** A "name" outside packages/packages-dev is not a package entry either. */
    public function testTopLevelSectionsOtherThanPackagesAreIgnored(): void
    {
        $json = <<<'JSON'
            {
                "aliases": [
                    {
                        "name": "acme/aliased",
                        "alias": "1.0.0"
                    }
                ],
                "packages": [
                    {
                        "name": "acme/real",
                        "version": "1.0.0"
                    }
                ]
            }
            JSON;
        $index = LockLineIndex::fromString($json);
        self::assertNull($index->lineOf('acme/aliased'));
        self::assertSame(10, $index->lineOf('acme/real'));
    }

    /**
     * Braces inside a string value must not move the nesting depth the scan tracks; a description
     * full of them would otherwise push every later entry out of reach.
     */
    public function testBracesInsideStringValuesDoNotConfuseTheScan(): void
    {
        $json = <<<'JSON'
            {
                "packages": [
                    {
                        "description": "a {{ templating }} engine [with] brackets",
                        "name": "acme/braces",
                        "version": "1.0.0"
                    }
                ]
            }
            JSON;
        self::assertSame(5, LockLineIndex::fromString($json)->lineOf('acme/braces'));
    }

    /**
     * A lock written on one line carries no line to point at; the index is empty rather than wrong,
     * and the formatters simply omit the line from their annotations.
     */
    public function testMinifiedLockYieldsNoLines(): void
    {
        self::assertNull(LockLineIndex::fromString('{"packages":[{"name":"acme/pkg"}]}')->lineOf('acme/pkg'));
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
