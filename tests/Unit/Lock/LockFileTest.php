<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Lock;

use Composer\Package\BasePackage;
use Composer\Package\CompletePackage;
use Composer\Package\Loader\ArrayLoader;
use Lockrot\Exception\ConfigException;
use Lockrot\Lock\LockedPackage;
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

    public function testEmptyWithPackagesBuildsALockWithoutAContentHash(): void
    {
        $loader = new ArrayLoader();
        $a = LockedPackage::fromPackage(self::complete($loader->load(['name' => 'vendor/a', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/'])), false);
        $b = LockedPackage::fromPackage(self::complete($loader->load(['name' => 'vendor/b', 'version' => '2.0.0', 'notification-url' => 'https://packagist.org/downloads/'])), false);

        $lock = LockFile::empty()->withPackages([$a, $b]);

        self::assertCount(2, $lock->packages(true));
        self::assertNull($lock->contentHash());
        $found = $lock->find('vendor/b');
        self::assertNotNull($found);
        self::assertSame('2.0.0', $found->version());
    }

    public function testEmptyBuildsALockWithNoPackagesAndNoContentHash(): void
    {
        $lock = LockFile::empty();

        self::assertSame([], $lock->packages(true));
        self::assertNull($lock->contentHash());
    }

    public function testWithPackagesOverridesByNamePreservingDevFlagAndLeavesTheOriginalUntouched(): void
    {
        $lock = LockFile::fromFile(self::MINI);
        $loader = new ArrayLoader();
        $updatedDirect = LockedPackage::fromPackage(self::complete($loader->load([
            'name' => 'vendor/direct', 'version' => '9.9.9', 'notification-url' => 'https://packagist.org/downloads/',
        ])), false);
        $newDevPackage = LockedPackage::fromPackage(self::complete($loader->load([
            'name' => 'vendor/brand-new-dev', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/',
        ])), true);

        $updated = $lock->withPackages([$updatedDirect, $newDevPackage]);

        $foundDirect = $updated->find('vendor/direct');
        self::assertNotNull($foundDirect);
        self::assertSame('9.9.9', $foundDirect->version());
        $foundNew = $updated->find('vendor/brand-new-dev');
        self::assertNotNull($foundNew);
        self::assertTrue($foundNew->isDev());
        self::assertNotNull($updated->find('vendor/transitive'), 'every other entry is carried over unchanged');
        self::assertSame('abc123', $updated->contentHash(), 'the content hash is carried over unchanged');

        $originalDirect = $lock->find('vendor/direct');
        self::assertNotNull($originalDirect);
        self::assertSame('1.2.3', $originalDirect->version(), 'the original instance must not be mutated');
        self::assertNull($lock->find('vendor/brand-new-dev'));
    }

    /**
     * TransactionPackages::fromTransaction() cannot know a package's dev-ness and always says
     * false; an entry the lock already lists under packages-dev must stay dev when overlaid, or
     * `--no-dev` chain building would start seeing it.
     */
    public function testWithPackagesKeepsTheExistingEntrysDevFlagWhenOverridden(): void
    {
        $lock = LockFile::fromFile(self::MINI);
        $loader = new ArrayLoader();
        $updatedDevtool = LockedPackage::fromPackage(self::complete($loader->load([
            'name' => 'vendor/devtool', 'version' => '5.0.0', 'notification-url' => 'https://packagist.org/downloads/',
        ])), false);

        $updated = $lock->withPackages([$updatedDevtool]);

        $found = $updated->find('vendor/devtool');
        self::assertNotNull($found);
        self::assertSame('5.0.0', $found->version(), 'the transaction version wins');
        self::assertTrue($found->isDev(), 'the lock knows it is a packages-dev entry; the transaction does not');
        self::assertCount(4, $updated->packages(false), 'still excluded from a no-dev listing');
    }

    private static function complete(BasePackage $package): CompletePackage
    {
        self::assertInstanceOf(CompletePackage::class, $package);

        return $package;
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
