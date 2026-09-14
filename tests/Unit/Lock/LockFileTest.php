<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Lock;

use Lockrot\Exception\ConfigException;
use Lockrot\Lock\LockFile;
use PHPUnit\Framework\TestCase;

final class LockFileTest extends TestCase
{
    private const MINI = __DIR__.'/../../fixtures/mini/composer.lock';

    public function testReadsProdAndDevPackages(): void
    {
        $lock = LockFile::fromFile(self::MINI);
        self::assertCount(4, $lock->packages(false));
        self::assertCount(5, $lock->packages(true));
        self::assertSame('abc123', $lock->contentHash());
    }

    public function testPackageFields(): void
    {
        $pkg = LockFile::fromFile(self::MINI)->find('vendor/direct');
        self::assertNotNull($pkg);
        self::assertSame('1.2.3', $pkg->version());
        $time = $pkg->time();
        self::assertNotNull($time);
        self::assertSame('2024-01-10', $time->format('Y-m-d'));
        self::assertSame('>=7.4', $pkg->requirePhp());
        self::assertSame(['vendor/transitive'], $pkg->requires());
        self::assertSame('https://github.com/vendor/direct.git', $pkg->sourceUrl());
        self::assertTrue($pkg->isFromComposerRepository());
        self::assertFalse($pkg->isDev());
        self::assertFalse($pkg->isBranchSnapshot());
        self::assertFalse($pkg->abandonedInLock());
    }

    public function testSnapshotAndPrivateDetection(): void
    {
        $lock = LockFile::fromFile(self::MINI);

        $snapshot = $lock->find('vendor/snapshot');
        self::assertNotNull($snapshot);
        self::assertTrue($snapshot->isBranchSnapshot());

        $private = $lock->find('private/thing');
        self::assertNotNull($private);
        self::assertFalse($private->isFromComposerRepository());
        self::assertNull($private->time());

        $transitive = $lock->find('vendor/transitive');
        self::assertNotNull($transitive);
        self::assertTrue($transitive->abandonedInLock());

        $devtool = $lock->find('vendor/devtool');
        self::assertNotNull($devtool);
        self::assertTrue($devtool->isDev());
    }

    public function testEmptyNotificationUrlIsNotFromComposerRepository(): void
    {
        $lock = LockFile::fromArray([
            'packages' => [
                ['name' => 'a/b', 'version' => '1.0.0', 'notification-url' => ''],
            ],
        ]);
        $pkg = $lock->find('a/b');
        self::assertNotNull($pkg);
        self::assertFalse($pkg->isFromComposerRepository());
    }

    public function testRequiresExcludePlatformPackages(): void
    {
        $lock = LockFile::fromArray([
            'packages' => [
                [
                    'name' => 'a/b',
                    'version' => '1.0.0',
                    'require' => [
                        'php' => '>=7.4',
                        'ext-json' => '*',
                        'lib-curl' => '*',
                        'composer-plugin-api' => '^2.0',
                        'composer-runtime-api' => '^2.0',
                        'vendor/dep' => '^1.0',
                    ],
                ],
            ],
        ]);
        $pkg = $lock->find('a/b');
        self::assertNotNull($pkg);
        self::assertSame(['vendor/dep'], $pkg->requires());
    }

    public function testBranchAliasUnwrapPreservesLockedVersion(): void
    {
        $lock = LockFile::fromArray([
            'packages' => [
                [
                    'name' => 'a/b',
                    'version' => 'dev-main',
                    'require' => [
                        'php' => '>=7.4',
                        'vendor/dep' => '^1.0',
                    ],
                    'extra' => [
                        'branch-alias' => [
                            'dev-main' => '2.x-dev',
                        ],
                    ],
                ],
            ],
        ]);
        $pkg = $lock->find('a/b');
        self::assertNotNull($pkg);
        self::assertSame('dev-main', $pkg->version());
        self::assertSame(['vendor/dep'], $pkg->requires());
        self::assertTrue($pkg->isBranchSnapshot());
        self::assertSame('>=7.4', $pkg->requirePhp());
    }

    public function testAbandonedWithReplacementReportsReplacementPackage(): void
    {
        $lock = LockFile::fromArray([
            'packages' => [
                [
                    'name' => 'a/b',
                    'version' => '1.0.0',
                    'abandoned' => 'vendor/replacement',
                ],
            ],
        ]);
        $pkg = $lock->find('a/b');
        self::assertNotNull($pkg);
        self::assertSame('vendor/replacement', $pkg->abandonedInLock());
    }

    public function testInvalidLockEntryThrowsConfigExceptionWithIndex(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('composer.lock entry #0 in packages cannot be loaded: Package a/b has no version defined.');
        LockFile::fromArray(['packages' => [['name' => 'a/b']]]);
    }

    public function testNonArrayLockEntryThrowsConfigExceptionWithIndex(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('composer.lock entry #1 in packages-dev cannot be loaded: entry must be a JSON object');
        LockFile::fromArray(['packages-dev' => [['name' => 'a/b', 'version' => '1.0.0'], 'not-an-object']]);
    }

    public function testMissingFileThrows(): void
    {
        $this->expectException(ConfigException::class);
        LockFile::fromFile('/nonexistent/composer.lock');
    }

    public function testInvalidJsonThrows(): void
    {
        $path = sys_get_temp_dir().'/lockrot-bad-'.uniqid().'.lock';
        file_put_contents($path, '{not json');
        try {
            $this->expectException(ConfigException::class);
            LockFile::fromFile($path);
        } finally {
            unlink($path);
        }
    }

    public function testRealFixtureCounts(): void
    {
        $lock = LockFile::fromFile(__DIR__.'/../../fixtures/apps/wallabag_wallabag/composer.lock');
        self::assertCount(200, $lock->packages(false));
        $rulerz = $lock->find('wallabag/rulerz');
        self::assertNotNull($rulerz);
        self::assertTrue($rulerz->isBranchSnapshot());
    }
}
