<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Lock;

use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Package\CompletePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Util\ErrorHandler;
use Lockrot\Exception\ConfigException;
use Lockrot\Lock\LockedPackage;
use Lockrot\Lock\LockFile;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LockFileTest extends TestCase
{
    private const MINI = __DIR__.'/../../fixtures/mini/composer.lock';

    /** A name that is no Composer package name never reaches a finding or a command. */
    public function testAnEntryWhoseNameIsNoComposerPackageNameIsSkippedAndNamed(): void
    {
        $lock = LockFile::fromArray(['packages' => [['name' => '--with-all-dependencies', 'version' => '1.0.0'], ['name' => 'acme/lib', 'version' => '1.0.0']], 'packages-dev' => [['name' => 'acme/x;calc', 'version' => '1.0.0']]]);

        self::assertSame(['acme/lib'], array_map(static fn ($package): string => $package->name(), $lock->packages(true)));
        self::assertSame(['--with-all-dependencies', 'acme/x;calc'], $lock->skippedNames());
    }

    public function testReadsProdAndDevPackages(): void
    {
        $lock = LockFile::fromFile(self::MINI);
        self::assertCount(4, $lock->packages(false));
        self::assertCount(5, $lock->packages(true));
        self::assertSame('aeb7ebbb156e3d3c5f9012d2cc9c8f04', $lock->contentHash(), 'Composer\'s content hash of the fixture\'s composer.json');
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
        self::assertSame('https://github.com/vendor/direct.git', $pkg->repositoryUrl());
        self::assertTrue($pkg->isFromComposerRepository());
        self::assertFalse($pkg->isDev());
        self::assertFalse($pkg->isBranchSnapshot());
        self::assertFalse($pkg->abandonedInLock());
    }

    /** The lock entry's `support.source` stands in for a missing `source`, and for nothing else. */
    public function testRepositoryUrlFallsBackToSupportSource(): void
    {
        $lock = LockFile::fromArray(['packages' => [
            ['name' => 'a/support', 'version' => '1.0.0', 'support' => ['source' => 'https://github.com/a/support-src', 'issues' => 'https://github.com/a/support/issues']],
            ['name' => 'a/both', 'version' => '1.0.0', 'source' => ['type' => 'git', 'url' => 'https://github.com/a/both.git', 'reference' => 'x'], 'support' => ['source' => 'https://github.com/a/other']],
            ['name' => 'a/empty', 'version' => '1.0.0', 'support' => ['source' => '']],
            ['name' => 'a/none', 'version' => '1.0.0'],
            ['name' => 'a/tree', 'version' => '1.0.0', 'support' => ['source' => 'https://github.com/a/tree/tree/1.0.0']],
        ]]);

        $urls = [];
        foreach ($lock->packages(false) as $package) {
            $urls[$package->name()] = $package->repositoryUrl();
        }
        self::assertSame([
            'a/support' => 'https://github.com/a/support-src',
            'a/both' => 'https://github.com/a/both.git',
            'a/empty' => null,
            'a/none' => null,
            'a/tree' => 'https://github.com/a/tree',
        ], $urls);
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
        $thrown = null;
        try {
            LockFile::fromArray(['packages' => [['name' => 'a/b']]]);
        } catch (ConfigException $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(ConfigException::class, $thrown);
        self::assertSame('composer.lock entry #0 in packages cannot be loaded: Package a/b has no version defined.', $thrown->getMessage());
        self::assertInstanceOf(\UnexpectedValueException::class, $thrown->getPrevious(), 'the loader failure is kept as the cause');
        self::assertSame(0, $thrown->getCode(), 'lockrot carries no exception codes; a run exits on its verdict, not on these');
    }

    public function testNonArrayLockEntryThrowsConfigExceptionWithIndex(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('composer.lock entry #1 in packages-dev cannot be loaded: entry must be a JSON object');
        LockFile::fromArray(['packages' => [], 'packages-dev' => [['name' => 'a/b', 'version' => '1.0.0'], 'not-an-object']]);
    }

    public function testMissingFileThrows(): void
    {
        $this->expectException(ConfigException::class);
        LockFile::fromFile('/nonexistent/composer.lock');
    }

    public function testInvalidJsonThrows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'lockrot-bad-');
        self::assertIsString($path);
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

    /** A transaction package that the lock path would skip as no Composer name is skipped here too. */
    public function testWithPackagesSkipsANameThatIsNoComposerName(): void
    {
        $loader = new ArrayLoader();
        $lock = LockFile::fromArray(['packages' => [['name' => 'acme/lib', 'version' => '1.0.0'], ['name' => '--bad', 'version' => '1.0.0']]]);
        $option = LockedPackage::fromPackage(self::complete($loader->load(['name' => '-x/y', 'version' => '1.0.0'])), false);
        $valid = LockedPackage::fromPackage(self::complete($loader->load(['name' => 'vendor/b', 'version' => '2.0.0'])), false);

        $merged = $lock->withPackages([$option, $valid]);
        $again = $merged->withPackages([$option]);

        self::assertSame(['acme/lib', 'vendor/b'], array_map(static fn ($package): string => $package->name(), $merged->packages(true)));
        self::assertSame(['--bad', '-x/y'], $merged->skippedNames());
        self::assertSame(['--bad', '-x/y'], $again->skippedNames(), 'a name is listed once');
    }

    public function testIsComposerNameFollowsComposersPattern(): void
    {
        self::assertTrue(LockFile::isComposerName('acme/lib'));
        self::assertTrue(LockFile::isComposerName('a.b_c-d/e--f'));
        self::assertFalse(LockFile::isComposerName('-x/y'));
        self::assertFalse(LockFile::isComposerName('Acme/Lib'));
        self::assertFalse(LockFile::isComposerName("acme/lib\n"));
    }

    public function testReadsThePlatformKeysAndWhetherPackagesDevExists(): void
    {
        $lock = LockFile::fromArray([
            'packages' => [['name' => 'acme/lib', 'version' => '1.0.0']],
            'packages-dev' => [['name' => 'acme/dev', 'version' => '1.0.0'], ['name' => '--bad', 'version' => '1.0.0']],
            'platform' => ['PHP' => '>=7.4', 'ext-json' => '*', 'broken' => 7, '0' => '*'],
            'platform-dev' => ['php' => '>=8.1'],
            'platform-overrides' => ['php' => '7.4.29', 'ext-intl' => false],
        ]);

        self::assertSame(['php' => '>=7.4', 'ext-json' => '*'], $lock->platform(), 'a name is lowercased, and a value or a name that is no string is skipped');
        self::assertSame(['php' => '>=8.1'], $lock->platformDev());
        self::assertSame('7.4.29', $lock->platformOverridePhp());
        self::assertTrue($lock->hasDevKey());
        self::assertTrue($lock->isDevEntry('acme/dev'));
        self::assertTrue($lock->isDevEntry('--bad'), 'an invalid name stays in the locked repository');
        self::assertFalse($lock->isDevEntry('acme/lib'));
    }

    public function testALockWithoutTheKeysHasNoPlatformAndNoPackagesDev(): void
    {
        $lock = LockFile::fromArray(['packages' => [], 'platform' => [], 'platform-overrides' => ['ext-intl' => false]]);

        self::assertSame([], $lock->platform());
        self::assertSame([], $lock->platformDev());
        self::assertNull($lock->platformOverridePhp());
        self::assertFalse($lock->hasDevKey());
        self::assertFalse(LockFile::empty()->hasDevKey());
    }

    public function testWithPackagesKeepsThePlatformKeys(): void
    {
        $lock = LockFile::fromArray([
            'packages' => [],
            'packages-dev' => [['name' => 'acme/dev', 'version' => '1.0.0']],
            'platform' => ['php' => '>=7.4'],
            'platform-dev' => ['php' => '>=8.1'],
            'platform-overrides' => ['php' => '7.4.29'],
        ])->withPackages([]);

        self::assertSame(['php' => '>=7.4'], $lock->platform());
        self::assertSame(['php' => '>=8.1'], $lock->platformDev());
        self::assertSame('7.4.29', $lock->platformOverridePhp());
        self::assertTrue($lock->hasDevKey());
        self::assertTrue($lock->isDevEntry('acme/dev'));
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
        self::assertSame('aeb7ebbb156e3d3c5f9012d2cc9c8f04', $updated->contentHash(), 'the content hash is carried over unchanged');

        $originalDirect = $lock->find('vendor/direct');
        self::assertNotNull($originalDirect);
        self::assertSame('1.2.3', $originalDirect->version(), 'the original instance must not be mutated');
        self::assertNull($lock->find('vendor/brand-new-dev'));
    }

    /**
     * TransactionPackages::fromTransaction() cannot know a package's dev-ness and always says
     * false. An entry that the lock lists under packages-dev must stay dev when overlaid, or
     * `--no-dev` chain building sees it.
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
        $path = __DIR__.'/../../fixtures/apps/wallabag_wallabag/composer.lock';
        $lock = LockFile::fromFile($path);
        self::assertCount(\count(JsonPath::arrayAt(JsonPath::decodeFile($path), ['packages'])), $lock->packages(false));
        $rulerz = $lock->find('wallabag/rulerz');
        self::assertNotNull($rulerz);
        self::assertTrue($rulerz->isBranchSnapshot());
    }

    public function testWithDevReturnsAFlaggedCopyAndLeavesTheOriginalAlone(): void
    {
        $lock = LockFile::fromArray(['packages' => [['name' => 'vendor/pkg', 'version' => '1.0.0']]]);
        $prod = $lock->packages(false)[0];

        $dev = $prod->withDev(true);

        self::assertTrue($dev->isDev());
        self::assertFalse($prod->isDev());
        self::assertNotSame($prod, $dev);
        self::assertSame('vendor/pkg', $dev->name());
    }

    /** `composer audit` matches an advisory against the aliases Composer's loader builds for the entry. */
    public function testAnEntryKeepsTheVersionsOfItsAliases(): void
    {
        $lock = LockFile::fromArray(['packages' => [
            ['name' => 'acme/aliased', 'version' => 'dev-main', 'extra' => ['branch-alias' => ['dev-main' => '4.3.x-dev']]],
            ['name' => 'acme/plain', 'version' => '1.0.0'],
            ['name' => 'acme/normalised', 'version' => 'v1.2', 'version_normalized' => '1.2.0.0'],
        ]]);
        $byName = [];
        foreach ($lock->packages(false) as $package) {
            $byName[$package->name()] = $package;
        }

        self::assertSame('dev-main', $byName['acme/aliased']->version());
        self::assertSame(['4.3.9999999.9999999-dev'], $byName['acme/aliased']->aliasVersions());
        self::assertSame([], $byName['acme/plain']->aliasVersions());
        self::assertSame('1.2.0.0', $byName['acme/normalised']->normalizedVersion(), 'Composer matches advisories on version_normalized as the lock gives it');
        self::assertSame('1.0.0.0', $byName['acme/plain']->normalizedVersion());
        $byName['acme/plain']->withAliasVersions(['9999999-dev']);
        self::assertSame([], $byName['acme/plain']->aliasVersions(), 'a copy, never the original');
    }

    public function testAnEntryKeepsTheConstraintsOfItsLinks(): void
    {
        $lock = LockFile::fromArray(['packages' => [[
            'name' => 'vendor/pkg',
            'version' => '1.0.0',
            'require' => ['php' => '>=7.4', 'ext-json' => '*', 'Vendor/Dep' => '^1.2|^2.0'],
            'conflict' => ['vendor/bad' => '<1.4'],
            'replace' => ['vendor/old' => 'self.version'],
            'provide' => ['psr/log-implementation' => '1.0|2.0'],
        ]]]);
        $pkg = $lock->find('vendor/pkg');
        self::assertNotNull($pkg);

        self::assertSame(['vendor/dep' => '^1.2|^2.0'], $pkg->requireConstraints());
        self::assertSame(['vendor/bad' => '<1.4'], $pkg->conflicts());
        self::assertSame(['vendor/old' => 'self.version'], $pkg->replaces());
        self::assertSame(['psr/log-implementation' => '1.0|2.0'], $pkg->provides());
    }

    /** The solver's lock: both sections, with each `aliases` entry as a root alias of its package. */
    public function testTheLockedRepositoryHoldsBothSectionsAndTheRootAliases(): void
    {
        $lock = LockFile::fromArray([
            'packages' => [['name' => 'vendor/a', 'version' => 'dev-main', 'source' => ['type' => 'git', 'url' => 'https://example.org/a.git', 'reference' => 'abc'], 'transport-options' => ['ssl' => ['verify_peer' => false]]]],
            'packages-dev' => [['name' => 'vendor/b', 'version' => '2.0.0']],
            'aliases' => [
                ['package' => 'vendor/a', 'version' => 'dev-main', 'alias' => '1.0.x-dev', 'alias_normalized' => '1.0.9999999.9999999-dev'],
                ['package' => 'vendor/missing', 'version' => 'dev-main', 'alias' => '1.0.x-dev', 'alias_normalized' => '1.0.9999999.9999999-dev'],
            ],
        ]);

        $versions = [];
        foreach ($lock->lockedRepository()->getPackages() as $package) {
            $versions[] = [$package->getName(), $package->getPrettyVersion(), $package instanceof AliasPackage && $package->isRootPackageAlias()];
            if ($package->getPrettyVersion() === 'dev-main') {
                self::assertSame(['ssl' => ['verify_peer' => false]], $package->getTransportOptions(), 'loaded as Composer\'s Locker loads the lock for an update');
            }
        }
        sort($versions);

        self::assertSame([['vendor/a', '1.0.x-dev', true], ['vendor/a', 'dev-main', false], ['vendor/b', '2.0.0', false]], $versions, 'an aliases entry that names no locked package adds nothing');
        self::assertCount(0, LockFile::empty()->lockedRepository()->getPackages());
    }

    /**
     * Each body is valid JSON that Composer's Locker cannot use, or that breaks a lockrot rule.
     *
     * @return iterable<string, array{0: array<string, mixed>, 1: non-empty-string}>
     */
    public static function refusedLocks(): iterable
    {
        $entry = ['name' => 'a/b', 'version' => '1.0.0'];

        yield 'no packages' => [[], 'alt.lock: packages must be a list of package entries'];
        yield 'null packages' => [['packages' => null], 'alt.lock: packages must be a list of package entries'];
        yield 'string packages' => [['packages' => 'oops'], 'alt.lock: packages must be a list of package entries'];
        yield 'only packages-dev' => [['packages-dev' => []], 'alt.lock: packages must be a list of package entries'];
        yield 'packages as an object' => [['packages' => ['a' => $entry]], 'alt.lock: packages must be a list of package entries'];
        yield 'string packages-dev' => [['packages' => [], 'packages-dev' => 'x'], 'alt.lock: packages-dev must be a list of package entries'];
        yield 'packages-dev as an object' => [['packages' => [], 'packages-dev' => ['a' => $entry]], 'alt.lock: packages-dev must be a list of package entries'];
        yield 'string platform' => [['packages' => [], 'platform' => 'x'], 'alt.lock: platform must be an object'];
        yield 'string platform-dev' => [['packages' => [], 'platform-dev' => 'x'], 'alt.lock: platform-dev must be an object'];
        yield 'string platform-overrides' => [['packages' => [], 'platform-overrides' => 'x'], 'alt.lock: platform-overrides must be an object'];
        yield 'number as platform override' => [['packages' => [], 'platform-overrides' => ['php' => 8.1]], 'alt.lock: platform-overrides cannot be loaded: '];
        yield 'platform constraint that Composer cannot parse' => [['packages' => [], 'platform' => ['php' => 'not a constraint !!']], 'alt.lock: platform cannot be loaded: '];
        yield 'platform-dev constraint that Composer cannot parse' => [['packages' => [], 'platform-dev' => ['ext-json' => '>= ~']], 'alt.lock: platform-dev cannot be loaded: '];
        yield 'override of a package that is not a platform package' => [['packages' => [], 'platform-overrides' => ['foo/bar' => '1.0']], 'alt.lock: platform-overrides cannot be loaded: foo/bar is no platform package'];
        yield 'self.version before a platform constraint that Composer cannot parse' => [['packages' => [], 'platform' => ['php' => 'self.version', 'ext-json' => '>= ~']], 'alt.lock: platform cannot be loaded: '];
        yield 'override that Composer cannot normalise' => [['packages' => [], 'platform-overrides' => ['php' => 'eight']], 'alt.lock: platform-overrides cannot be loaded: '];
        yield 'php override false' => [['packages' => [], 'platform-overrides' => ['php' => false]], 'alt.lock: platform-overrides cannot be loaded: '];
        yield 'string aliases' => [['packages' => [], 'aliases' => 'x'], 'alt.lock: aliases must hold objects with the string package'];
        yield 'alias of a locked package without alias_normalized' => [['packages' => [$entry], 'aliases' => [['package' => 'a/b', 'version' => '1.0.0', 'alias' => '1.0.x-dev']]], 'alt.lock: aliases entry for a/b must have the strings alias and alias_normalized'];
        yield 'alias of a locked package without alias' => [['packages' => [$entry], 'aliases' => [['package' => 'a/b', 'version' => '1.0.0', 'alias_normalized' => '1.0.9999999.9999999-dev']]], 'alt.lock: aliases entry for a/b must have the strings alias and alias_normalized'];
        yield 'alias that is a string' => [['packages' => [$entry], 'aliases' => ['a/b']], 'alt.lock: aliases must hold objects with the string package'];
        yield 'alias without package' => [['packages' => [$entry], 'aliases' => [['version' => '1.0.0', 'alias' => '1.0.x-dev', 'alias_normalized' => '1.0.9999999.9999999-dev']]], 'alt.lock: aliases must hold objects with the string package'];
        yield 'a broken alias before a whole one' => [['packages' => [$entry], 'aliases' => ['a/b', ['package' => 'a/b', 'version' => '1.0.0', 'alias' => '1.0.x-dev', 'alias_normalized' => '1.0.9999999.9999999-dev']]], 'alt.lock: aliases must hold objects with the string package'];
        yield 'number as name' => [['packages' => [['name' => 5, 'version' => '1.0.0']]], 'alt.lock entry #0 in packages cannot be loaded: "name" must be a string'];
        yield 'number as type' => [['packages' => [['name' => 'a/b', 'version' => '1.0.0', 'type' => 5]]], 'alt.lock entry #0 in packages cannot be loaded: "type" must be a string'];
        yield 'number as source url' => [['packages' => [['name' => 'a/b', 'version' => '1.0.0', 'source' => ['type' => 'git', 'url' => 5, 'reference' => 7]]]], 'alt.lock entry #0 in packages cannot be loaded: "source.url" must be a string'];
        yield 'number as dist url' => [['packages' => [['name' => 'a/b', 'version' => '1.0.0', 'dist' => ['type' => 'zip', 'url' => 5]]]], 'alt.lock entry #0 in packages cannot be loaded: "dist.url" must be a string'];
        yield 'number as notification url' => [['packages' => [['name' => 'a/b', 'version' => '1.0.0', 'notification-url' => 5]]], 'alt.lock entry #0 in packages cannot be loaded: "notification-url" must be a string'];
        yield 'number in bin' => [['packages' => [['name' => 'a/b', 'version' => '1.0.0', 'bin' => [5]]]], 'alt.lock entry #0 in packages cannot be loaded: "bin" must be a string or a list of strings'];
        yield 'autoload as a string' => [['packages' => [['name' => 'a/b', 'version' => '1.0.0', 'autoload' => 'x']]], 'alt.lock entry #0 in packages cannot be loaded: "autoload" must be a JSON object'];
        yield 'php-ext as a string' => [['packages' => [['name' => 'a/b', 'version' => '1.0.0', 'php-ext' => 'x']]], 'alt.lock entry #0 in packages cannot be loaded: "php-ext" must be a JSON object'];
        yield 'include-path as a string' => [['packages' => [['name' => 'a/b', 'version' => '1.0.0', 'include-path' => 'x']]], 'alt.lock entry #0 in packages cannot be loaded: "include-path" must be a list'];
        yield 'suggest value a number' => [['packages' => [['name' => 'a/b', 'version' => '1.0.0', 'suggest' => ['x/y' => 5]]]], 'alt.lock entry #0 in packages cannot be loaded: "suggest" must map names to strings'];
        yield 'source mirrors as a string' => [['packages' => [['name' => 'a/b', 'version' => '1.0.0', 'source' => ['type' => 'git', 'url' => 'u', 'reference' => 'r', 'mirrors' => 'x']]]], 'alt.lock entry #0 in packages cannot be loaded: "source.mirrors" must be a list'];
        yield 'dist mirrors as a string' => [['packages' => [['name' => 'a/b', 'version' => '1.0.0', 'dist' => ['type' => 'zip', 'url' => 'u', 'mirrors' => 'x']]]], 'alt.lock entry #0 in packages cannot be loaded: "dist.mirrors" must be a list'];
        yield 'archive name a number' => [['packages' => [['name' => 'a/b', 'version' => '1.0.0', 'archive' => ['name' => 5]]]], 'alt.lock entry #0 in packages cannot be loaded: "archive.name" must be a string'];
        yield 'archive exclude a string' => [['packages' => [['name' => 'a/b', 'version' => '1.0.0', 'archive' => ['exclude' => 'x']]]], 'alt.lock entry #0 in packages cannot be loaded: "archive.exclude" must be a list'];
        yield 'number in bin beside a shape that the loader reads' => [['packages' => [['name' => 'a/b', 'version' => '1.0.0', 'bin' => [5], 'archive' => ['exclude' => ''], 'autoload' => ['x'], 'source' => ['type' => 'git', 'url' => 'u', 'reference' => 'r', 'mirrors' => ['k' => 'v']]]]], 'alt.lock entry #0 in packages cannot be loaded: "bin" must be a string or a list of strings'];
        yield 'number in suggest beside a null keyword' => [['packages' => [['name' => 'a/b', 'version' => '1.0.0', 'keywords' => [null], 'suggest' => ['x/y' => 5]]]], 'alt.lock entry #0 in packages cannot be loaded: "suggest" must map names to strings'];
        yield 'number in suggest beside keywords that are a string' => [['packages' => [['name' => 'a/b', 'version' => '1.0.0', 'keywords' => 'x', 'suggest' => ['x/y' => 5]]]], 'alt.lock entry #0 in packages cannot be loaded: "suggest" must map names to strings'];
        yield 'number in suggest beside a bin that is a string' => [['packages' => [['name' => 'a/b', 'version' => '1.0.0', 'bin' => 'x', 'suggest' => ['x/y' => 5]]]], 'alt.lock entry #0 in packages cannot be loaded: "suggest" must map names to strings'];
        yield 'number in suggest beside a bin that is a list of strings' => [['packages' => [['name' => 'a/b', 'version' => '1.0.0', 'bin' => ['x'], 'suggest' => ['x/y' => 5]]]], 'alt.lock entry #0 in packages cannot be loaded: "suggest" must map names to strings'];
        yield 'number as bin' => [['packages' => [['name' => 'a/b', 'version' => '1.0.0', 'bin' => 5]]], 'alt.lock entry #0 in packages cannot be loaded: "bin" must be a string or a list of strings'];
        yield 'number as dist shasum beside a number as dist reference' => [['packages' => [['name' => 'a/b', 'version' => '1.0.0', 'dist' => ['type' => 'zip', 'url' => 'u', 'reference' => 7, 'shasum' => 5]]]], 'alt.lock entry #0 in packages cannot be loaded: "dist.shasum" must be a string'];
        yield 'number as dist url beside a false notification url' => [['packages' => [['name' => 'a/b', 'version' => '1.0.0', 'notification-url' => false, 'dist' => ['type' => 'zip', 'url' => 5]]]], 'alt.lock entry #0 in packages cannot be loaded: "dist.url" must be a string'];
        yield 'number as bin beside a false archive name' => [['packages' => [['name' => 'a/b', 'version' => '1.0.0', 'archive' => ['name' => false], 'bin' => 5]]], 'alt.lock entry #0 in packages cannot be loaded: "bin" must be a string or a list of strings'];
        yield 'source without a url beside a list in keywords' => [['packages' => [['name' => 'a/b', 'version' => '1.0.0', 'source' => ['type' => 'git', 'reference' => 'r'], 'keywords' => [['k']]]]], "alt.lock entry #0 in packages cannot be loaded: Package a/b's source key should be specified"];
        yield 'number as type beside a time and transport-options that the loader reads' => [['packages' => [['name' => 'a/b', 'version' => '1.0.0', 'type' => 5, 'time' => '2020-01-01', 'transport-options' => []]]], 'alt.lock entry #0 in packages cannot be loaded: "type" must be a string'];
        yield 'number as type beside a source that is a string' => [['packages' => [['name' => 'a/b', 'version' => '1.0.0', 'type' => 5, 'source' => 'x']]], 'alt.lock entry #0 in packages cannot be loaded: "type" must be a string'];
        foreach (['require', 'require-dev', 'conflict', 'provide', 'replace'] as $field) {
            yield 'number as a key of '.$field => [['packages' => [['name' => 'a/b', 'version' => '1.0.0', $field => ['5' => '1.0']]]], 'alt.lock entry #0 in packages cannot be loaded: "'.$field.'" of a/b has the key 5, which is no package name'];
            yield 'number as a key of '.$field.' in packages-dev' => [['packages' => [], 'packages-dev' => [['name' => 'a/b', 'version' => '1.0.0', $field => ['c/d' => '^1.0', '-7' => '1.0']]]], 'alt.lock entry #0 in packages-dev cannot be loaded: "'.$field.'" of a/b has the key -7, which is no package name'];
        }
        yield 'number as a platform override' => [['packages' => [], 'platform-overrides' => ['5' => '1.0']], 'alt.lock: platform-overrides cannot be loaded: 5 is no platform package'];
        yield 'name in both sections' => [['packages' => [$entry], 'packages-dev' => [['name' => 'a/b', 'version' => '2.0.0']]], 'alt.lock lists a/b twice: entry #0 in packages and entry #0 in packages-dev'];
        yield 'name twice ignoring case' => [['packages' => [['name' => 'other/x', 'version' => '1.0.0'], $entry, ['name' => 'A/B', 'version' => '1.0.0']]], 'alt.lock lists a/b twice: entry #1 in packages and entry #2 in packages'];
        yield 'invalid name twice' => [['packages' => [['name' => '--x', 'version' => '1.0.0'], ['name' => '--x', 'version' => '1.0.0']]], 'alt.lock lists --x twice: entry #0 in packages and entry #1 in packages'];
    }

    /**
     * @dataProvider refusedLocks
     *
     * @param array<string, mixed> $lock
     * @param non-empty-string     $message
     */
    #[DataProvider('refusedLocks')]
    public function testALockThatComposerOrLockrotRefusesIsAConfigErrorThatNamesTheFile(array $lock, string $message): void
    {
        $thrown = null;
        try {
            LockFile::fromArray($lock, 'alt.lock');
        } catch (ConfigException $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown, 'the lock loads');
        self::assertStringStartsWith($message, $thrown->getMessage());
    }

    public function testAWronglyTypedFieldNamesTheFieldAndNeverQuotesPhp(): void
    {
        $lock = ['packages' => [['name' => 'a/b', 'version' => '1.0.0', 'source' => ['type' => 'git', 'url' => 5, 'reference' => 7]]]];

        try {
            LockFile::fromArray($lock, 'alt.lock');
            self::fail('the lock loads');
        } catch (ConfigException $e) {
            self::assertStringNotContainsString('Argument', $e->getMessage());
            self::assertStringNotContainsString('vendor', $e->getMessage());
            self::assertStringNotContainsString('.php', $e->getMessage());
        }
    }

    /**
     * A number as a link key is a TypeError in Composer's typed code, whose message names a PHP
     * function and a path of the install.
     *
     * @dataProvider numberKeys
     *
     * @param array<string, mixed> $lock
     */
    #[DataProvider('numberKeys')]
    public function testANumberAsALinkKeyNamesTheFieldAndNeverQuotesPhp(array $lock): void
    {
        try {
            LockFile::fromArray($lock, 'alt.lock');
            self::fail('the lock loads');
        } catch (ConfigException $e) {
            self::assertStringNotContainsString('Argument', $e->getMessage());
            self::assertStringNotContainsString('.php', $e->getMessage());
        }
    }

    /**
     * JSON decoding turns a link map whose keys are 0 … n into a list. Composer's loader refuses a
     * list with a notice, which Composer's error handler turns into an error.
     *
     * @dataProvider linkMapsThatDecodeToAList
     *
     * @param array<int, string> $links
     */
    #[DataProvider('linkMapsThatDecodeToAList')]
    public function testALinkMapWhoseKeysStartAtZeroNamesTheFieldAndTheKey(string $field, array $links): void
    {
        $lock = ['packages' => [['name' => 'a/b', 'version' => '1.0.0', $field => $links]]];

        ErrorHandler::register();
        try {
            LockFile::fromArray($lock, 'alt.lock');
            self::fail('the lock loads');
        } catch (ConfigException $e) {
            self::assertSame('alt.lock entry #0 in packages cannot be loaded: "'.$field.'" of a/b has the key 0, which is no package name', $e->getMessage());
        } finally {
            restore_error_handler();
        }
    }

    /** @return iterable<string, array{string, array<int, string>}> */
    public static function linkMapsThatDecodeToAList(): iterable
    {
        foreach (['require', 'require-dev', 'conflict', 'provide', 'replace'] as $field) {
            yield 'the key 0 alone in '.$field => [$field, ['0' => '^1']];
            yield 'the keys 0 and 1 in '.$field => [$field, ['^1', '^2']];
        }
    }

    /**
     * One Composer's loader reads a number as `time` and skips a `transport-options` that is no
     * object, another fails on both with a TypeError.
     *
     * @dataProvider fieldsThatSomeLoadersCannotRead
     *
     * @param mixed $value
     */
    #[DataProvider('fieldsThatSomeLoadersCannotRead')]
    public function testAFieldThatTheRunningLoaderCannotReadIsNamed(string $field, $value, string $message): void
    {
        $entry = ['name' => 'a/b', 'version' => '1.0.0', $field => $value];
        try {
            (new ArrayLoader(null, true))->load($entry);
            self::markTestSkipped('this Composer reads the field');
        } catch (\TypeError $e) {
        }

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('alt.lock entry #0 in packages cannot be loaded: "'.$field.'" '.$message);

        LockFile::fromArray(['packages' => [$entry]], 'alt.lock');
    }

    /** @return iterable<string, array{string, mixed, string}> */
    public static function fieldsThatSomeLoadersCannotRead(): iterable
    {
        yield 'a number as time' => ['time', 5, 'must be a string'];
        yield 'a list as time' => ['time', ['x'], 'must be a string'];
        yield 'a string as transport-options' => ['transport-options', 'x', 'must be a JSON object'];
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function numberKeys(): iterable
    {
        yield 'require' => [['packages' => [['name' => 'a/b', 'version' => '1.0.0', 'require' => ['5' => '1.0']]]]];
        yield 'platform-overrides' => [['packages' => [], 'platform-overrides' => ['5' => '1.0']]];
    }

    /**
     * @dataProvider listsInKeywords
     *
     * @param array<string, mixed> $fields
     */
    #[DataProvider('listsInKeywords')]
    public function testAListInKeywordsIsRefusedWhenComposerTurnsTheWarningIntoAnError(array $fields): void
    {
        $lock = ['packages' => [['name' => 'a/b', 'version' => '1.0.0'] + $fields]];

        ErrorHandler::register();
        try {
            LockFile::fromArray($lock, 'alt.lock');
            self::fail('the lock loads');
        } catch (ConfigException $e) {
            self::assertSame('alt.lock entry #0 in packages cannot be loaded: "keywords" must be a list of strings', $e->getMessage());
        } finally {
            restore_error_handler();
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function listsInKeywords(): iterable
    {
        yield 'keywords alone' => [['keywords' => [['k']]]];
        // The loader skips a link field that is not an array, so only keywords can have failed.
        yield 'keywords after a string require' => [['require' => 'x', 'keywords' => [[1]]]];
    }

    /**
     * The field check names the cause of a loader failure only. A lock that Composer's loader reads
     * stays readable, whatever shape its optional fields have.
     *
     * @dataProvider loadableShapes
     *
     * @param array<string, mixed> $fields
     */
    #[DataProvider('loadableShapes')]
    public function testAnEntryThatTheLoaderReadsIsNotRefusedForAFieldShape(array $fields): void
    {
        $lock = LockFile::fromArray(['packages' => [['name' => 'a/b', 'version' => '1.0.0'] + $fields]], 'alt.lock');

        self::assertNotNull($lock->find('a/b'));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function loadableShapes(): iterable
    {
        yield 'empty archive exclude false' => [['archive' => ['exclude' => false]]];
        yield 'empty archive exclude string' => [['archive' => ['exclude' => '']]];
        yield 'archive exclude zero string' => [['archive' => ['exclude' => '0']]];
        yield 'list-shaped autoload' => [['autoload' => ['x']]];
        yield 'list-shaped php-ext' => [['php-ext' => ['x']]];
        yield 'map-shaped include-path' => [['include-path' => ['a' => 'x']]];
        yield 'keywords with null' => [['keywords' => [null]]];
        yield 'a top-level key that looks like a dotted field' => [['source.url' => 5, 'dist.type' => []]];
        // Composer's loader skips a link whose constraint is no string.
        yield 'a number as a link key without a string constraint' => [['require' => ['5' => ['1.0']], 'replace' => ['6' => null]]];
    }

    public function testAFileLockIsNamedByItsFileNameUnlessACallerNamesIt(): void
    {
        $dir = sys_get_temp_dir().'/lockrot-lockfile-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/alt.lock', '[]');
        try {
            foreach ([[null, 'alt.lock: '], ['sub/alt.lock', 'sub/alt.lock: ']] as [$name, $prefix]) {
                $thrown = null;
                try {
                    LockFile::fromFile($dir.'/alt.lock', $name);
                } catch (ConfigException $e) {
                    $thrown = $e;
                }
                self::assertNotNull($thrown);
                self::assertStringStartsWith($prefix.'packages must be a list', $thrown->getMessage());
            }
        } finally {
            unlink($dir.'/alt.lock');
            rmdir($dir);
        }
    }

    /**
     * Composer reads each of these, so lockrot does too.
     *
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function acceptedLocks(): iterable
    {
        yield 'empty packages' => [['packages' => []]];
        yield 'empty packages as an object' => [['packages' => [], 'packages-dev' => []]];
        yield 'null packages-dev' => [['packages' => [], 'packages-dev' => null]];
        yield 'empty platform' => [['packages' => [], 'platform' => [], 'platform-dev' => null]];
        yield 'empty string platform' => [['packages' => [], 'platform' => '', 'platform-dev' => false]];
        yield 'null platform-overrides and aliases' => [['packages' => [], 'platform-overrides' => null, 'aliases' => null]];
        yield 'extension override false' => [['packages' => [], 'platform-overrides' => ['ext-redis' => false]]];
        yield 'number inside platform' => [['packages' => [], 'platform' => ['php' => 8.1]]];
        yield 'list inside platform-dev' => [['packages' => [], 'platform-dev' => ['ext-json' => ['not a constraint']]]];
        yield 'platform constraints that Composer parses' => [['packages' => [], 'platform' => ['php' => '>=7.4 <8.5', 'ext-json' => '*'], 'platform-dev' => ['ext-intl' => '^1.0 || dev-main']]];
        yield 'empty aliases' => [['packages' => [], 'aliases' => []]];
        yield 'self.version in platform and platform-dev' => [['packages' => [], 'platform' => ['php' => 'self.version'], 'platform-dev' => ['ext-json' => 'self.version']]];
    }

    /**
     * @dataProvider acceptedLocks
     *
     * @param array<string, mixed> $lock
     */
    #[DataProvider('acceptedLocks')]
    public function testALockThatComposerReadsLoads(array $lock): void
    {
        self::assertSame([], LockFile::fromArray($lock)->packages(true));
    }

    public function testANumberInsidePlatformIsSkippedAsComposerSkipsIt(): void
    {
        self::assertSame(['ext-json' => '*'], LockFile::fromArray(['packages' => [], 'platform' => ['php' => 8.1, 'ext-json' => '*']])->platform());
    }

    public function testAPlatformOverrideIsReadWithoutCaseAndTheLaterKeyWins(): void
    {
        self::assertSame('8.1.5', LockFile::fromArray(['packages' => [], 'platform-overrides' => ['PHP' => '8.1.5']])->platformOverridePhp());
        self::assertSame('8.2.0', LockFile::fromArray(['packages' => [], 'platform-overrides' => ['php' => '8.1.5', 'PHP' => '8.2.0']])->platformOverridePhp());
        self::assertNull(LockFile::fromArray(['packages' => [], 'platform-overrides' => ['php' => '8.1.5', 'PHP' => false]])->platformOverridePhp(), 'a later false wins over the earlier string');
    }

    public function testEachAliasNamesItsLockedPackage(): void
    {
        $lock = LockFile::fromArray([
            'packages' => [['name' => 'vendor/a', 'version' => 'dev-main'], ['name' => 'vendor/b', 'version' => 'dev-main']],
            'aliases' => [
                ['package' => 'vendor/a', 'version' => 'dev-main', 'alias' => '1.0.x-dev', 'alias_normalized' => '1.0.9999999.9999999-dev'],
                ['package' => 'vendor/b', 'version' => 'dev-main', 'alias' => '2.0.x-dev', 'alias_normalized' => '2.0.9999999.9999999-dev'],
            ],
        ]);

        self::assertCount(4, $lock->lockedRepository()->getPackages(), 'two locked packages and one alias of each');
    }

    /** Composer's Locker matches an alias against the lower-case package names as written. */
    public function testAMixedCaseAliasNamesNoPackageAsInComposer(): void
    {
        $lock = LockFile::fromArray([
            'packages' => [['name' => 'vendor/a', 'version' => 'dev-main']],
            'aliases' => [['package' => 'Vendor/A', 'version' => 'dev-main', 'alias' => '1.0.x-dev', 'alias_normalized' => '1.0.9999999.9999999-dev']],
        ]);

        self::assertCount(1, $lock->lockedRepository()->getPackages());
    }

    /** Composer's Locker skips an `aliases` entry whose package is not locked, and reads no other field of it. */
    public function testAnAliasesObjectAndAnEntryForNoLockedPackageLoadAsInComposer(): void
    {
        $lock = LockFile::fromArray([
            'packages' => [['name' => 'vendor/a', 'version' => 'dev-main']],
            'aliases' => [
                'first' => ['package' => 'vendor/missing', 'alias' => '1.0.x-dev'],
                'second' => ['package' => 'vendor/a', 'version' => 'dev-main', 'alias' => '1.0.x-dev', 'alias_normalized' => '1.0.9999999.9999999-dev'],
            ],
        ]);

        self::assertCount(2, $lock->lockedRepository()->getPackages(), 'the locked package and the alias of it');
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function platformDataThatComposerRefuses(): iterable
    {
        yield 'a php override that is no version' => [['packages' => [], 'platform-overrides' => ['php' => 'eight']], 'alt.lock: platform-overrides cannot be loaded: '];
        yield 'a php override of false' => [['packages' => [], 'platform-overrides' => ['php' => false]], 'alt.lock: platform-overrides cannot be loaded: '];
        yield 'a platform constraint that Composer cannot parse' => [['packages' => [], 'platform' => ['php' => 'not a constraint !!']], 'alt.lock: platform cannot be loaded: '];
    }

    /**
     * @dataProvider platformDataThatComposerRefuses
     *
     * @param array<string, mixed> $lock
     */
    #[DataProvider('platformDataThatComposerRefuses')]
    public function testPlatformDataThatComposerRefusesKeepsComposersReason(array $lock, string $prefix): void
    {
        $thrown = null;
        try {
            LockFile::fromArray($lock, 'alt.lock');
        } catch (ConfigException $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown);
        $cause = $thrown->getPrevious();
        self::assertNotNull($cause, 'the Composer failure is kept as the cause');
        self::assertNotSame('', $cause->getMessage());
        self::assertSame($prefix.$cause->getMessage(), $thrown->getMessage());
        self::assertSame(0, $thrown->getCode(), 'lockrot carries no exception codes; a run exits on its verdict, not on these');
    }

    /**
     * The limits are set when a PHP starts, because a pattern compiled earlier can ignore them at
     * run time. A name that cannot match must end the scan without hitting the backtrack limit.
     */
    public function testACraftedNameIsRefusedWithoutBacktracking(): void
    {
        $script = 'require '.var_export(\dirname(__DIR__, 3).'/vendor/autoload.php', true).'; echo (int) '.LockFile::class.'::isComposerName("a/".str_repeat("a", 200)."!"), preg_last_error_msg();';
        $output = shell_exec(escapeshellarg(\PHP_BINARY).' -d pcre.jit=0 -d pcre.backtrack_limit=1000 -r '.escapeshellarg($script));

        self::assertSame('0No error', $output);
    }
}
