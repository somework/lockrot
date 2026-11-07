<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Solver;

use Composer\Config;
use Composer\Package\BasePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\RootPackage;
use Composer\Package\RootPackageInterface;
use Composer\Repository\AdvisoryProviderInterface;
use Composer\Repository\ArrayRepository;
use Composer\Repository\PackageRepository;
use Composer\Repository\PlatformRepository;
use Composer\Util\Platform;
use Lockrot\Data\Advisory\AdvisoryCoverage;
use Lockrot\Deadline;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Solver\ComposerSolver;
use Lockrot\Solver\MoveCheck;
use Lockrot\Solver\MoveChecker;
use Lockrot\Solver\MoveCheckers;
use Lockrot\Solver\MoveQuery;
use Lockrot\Solver\SolverChecker;
use Lockrot\Tests\Support\ArrayVersionSource;
use Lockrot\Tests\Support\ConfiguredRepository;
use Lockrot\Tests\Support\RecordingComposerRepository;
use PHPUnit\Framework\TestCase;

final class MoveCheckersTest extends TestCase
{
    public function testThePlatformPhpIsTheConfiguredPatchInTheTargetMinor(): void
    {
        self::assertSame('7.4.29', MoveCheckers::platformPhp('7.4', '7.4.29'));
    }

    /** Composer reads a platform php with no patch as patch 0, so a release that needs a later patch does not install. */
    public function testAPlatformPhpWithNoPatchInTheTargetMinorIsKeptAsWritten(): void
    {
        $php = MoveCheckers::platformPhp('8.4', '8.4');
        $solver = new ComposerSolver(self::root(), LockFile::fromArray(['packages' => [['name' => 'acme/lib', 'version' => '1.0.0']]])->lockedRepository(), new PlatformRepository([], ['php' => $php]), new ArrayVersionSource(['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0', 'require' => ['php' => '>=8.4.1']]]]));

        $outcome = (new SolverChecker($solver, [], $php, false, Deadline::never()))->check(MoveQuery::update('acme/lib', '^1.1.0', '1.1.0'));

        self::assertSame('8.4', $php);
        self::assertSame(MoveCheck::REFUSED, $outcome->check()->result());
    }

    public function testThePlatformPhpIsTheNewestPatchOfTheTargetWithoutAConfiguredOneInIt(): void
    {
        self::assertSame('8.4.99', MoveCheckers::platformPhp('8.4', null));
        self::assertSame('8.4.99', MoveCheckers::platformPhp('8.4', '7.4.29'));
        self::assertSame('8.4.99', MoveCheckers::platformPhp('8.4', '8.41.0'));
        self::assertSame('8.4.99', MoveCheckers::platformPhp('8.4', '8.5.1'));
        self::assertSame('8.4.99', MoveCheckers::platformPhp('8.4', '8.4-dev'));
    }

    /** A pre-release of the target minor is no stable release in it: the solve runs at the newest patch. */
    public function testAPreReleasePlatformPhpInTheTargetMinorIsNotKept(): void
    {
        self::assertSame('8.4.99', MoveCheckers::platformPhp('8.4', '8.4.0-RC1'));
    }

    /** Composer normalises a bare major (`8`) to 8.0.0, so it lies in the target minor 8.0. */
    public function testAPlatformPhpThatComposerNormalisesIntoTheTargetMinorIsKept(): void
    {
        self::assertSame(['8', '7', '8.0.0.0'], [MoveCheckers::platformPhp('8.0', '8'), MoveCheckers::platformPhp('7.0', '7'), MoveCheckers::platformPhp('8.0', '8.0.0.0')]);
    }

    public function testAPlatformPhpThatComposerCannotNormaliseIsNotInTheTargetMinor(): void
    {
        self::assertSame('8.0.99', MoveCheckers::platformPhp('8.0', 'eight'));
    }

    /** Composer reads `202010` as a date version with no minor. */
    public function testAPlatformPhpThatComposerReadsAsADateIsNotInTheTargetMinor(): void
    {
        self::assertSame('8.0.99', MoveCheckers::platformPhp('8.0', '202010'));
    }

    /** The regular expression reads the target minor literally: its dot matches only a dot. */
    public function testAPlatformPhpThatMatchesTheTargetMinorOnlyAsAPatternIsNotInIt(): void
    {
        self::assertSame('8.1.99', MoveCheckers::platformPhp('8.1', '801.2'));
    }

    public function testTheProjectsMoveCheckSolvesAtTheConfiguredPlatformPhpOfTheTargetMinor(): void
    {
        $config = new Config(false);
        $config->merge(['config' => ['platform' => ['php' => '8.4.3', 'ext-lockrot-test' => false]]]);

        $checker = MoveCheckers::forProject(self::root(), LockFile::fromArray(['packages' => [['name' => 'acme/lib', 'version' => '1.0.0']]]), ProjectConfig::empty(), $config, [], '8.4', false, Deadline::never());

        self::assertSame('8.4.3', $checker->platformPhp());
    }

    /**
     * Ruling R8-37: Composer's PlatformRepository refuses each `config.platform` of the provider,
     * or reads it as a disabled php, so every `composer update` stops. No move resolves. The PHP
     * window still reads the configured php.
     *
     * @dataProvider platformsThatComposerRefuses
     *
     * @param array<array-key, mixed> $platform
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('platformsThatComposerRefuses')]
    public function testAConfigPlatformValueThatComposerRefusesChecksNoMove(array $platform, ?string $configuredPhp): void
    {
        $config = new Config(false);
        $config->merge(['config' => ['platform' => $platform]]);
        $repository = new RecordingComposerRepository([(new ArrayLoader())->load(['name' => 'acme/lib', 'version' => '1.1.0'])]);
        $lock = LockFile::fromArray(['packages' => [['name' => 'acme/lib', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/']]]);

        $checker = MoveCheckers::forProject(self::root(), $lock, ProjectConfig::empty(), $config, [$repository], '8.4', false, Deadline::never());

        $unchecked = ['result' => MoveCheck::NOT_CHECKED, 'form' => null, 'reason' => MoveCheck::PLATFORM_CONFIG_INVALID, 'installs' => null];
        self::assertSame($unchecked, $checker->baseline()->check()->jsonSerialize(), 'baseline');
        self::assertSame($unchecked, $checker->check(MoveQuery::update('acme/lib', '^1.1', '1.1.0'))->check()->jsonSerialize(), 'move');
        self::assertSame($configuredPhp, $checker->configuredPhp());
    }

    /**
     * Ruling R14-22: with `config.lock` false, Composer ignores composer.lock and refuses a
     * partial update, so no request is solved. The reason comes before a refused config.platform.
     *
     * @dataProvider configsWithTheLockOff
     *
     * @param array<string, mixed> $settings
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('configsWithTheLockOff')]
    public function testAConfigThatTurnsTheLockOffChecksNoMoveAndSolvesNothing(array $settings): void
    {
        $config = new Config(false);
        $config->merge(['config' => $settings]);
        $repository = new RecordingComposerRepository([(new ArrayLoader())->load(['name' => 'acme/lib', 'version' => '1.1.0'])]);
        $lock = LockFile::fromArray(['packages' => [['name' => 'acme/lib', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/']]]);

        $checker = MoveCheckers::forProject(self::root(), $lock, ProjectConfig::empty(), $config, [$repository], '8.4', false, Deadline::never());

        $unchecked = ['result' => MoveCheck::NOT_CHECKED, 'form' => null, 'reason' => MoveCheck::LOCK_DISABLED, 'installs' => null];
        self::assertSame($unchecked, $checker->baseline()->check()->jsonSerialize(), 'baseline');
        self::assertSame($unchecked, $checker->check(MoveQuery::update('acme/lib', '^1.1', '1.1.0'))->check()->jsonSerialize(), 'move');
        self::assertSame($unchecked, $checker->solveAt(['acme/lib'])->check()->jsonSerialize(), 'target fix');
        self::assertSame($unchecked, $checker->combined(['acme/lib' => '^1.1'])->check()->jsonSerialize(), 'update now');
        self::assertSame($unchecked, $checker->joint(MoveQuery::update('acme/lib', '^1.1', '1.1.0'), ['acme/other'])->check()->jsonSerialize(), 'joint');
        self::assertSame([], $repository->asked);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function configsWithTheLockOff(): iterable
    {
        yield 'lock false' => [['lock' => false]];
        yield 'lock false and a refused platform' => [['lock' => false, 'platform' => ['php' => '^8.2']]];
    }

    public function testAConfigThatKeepsTheLockOnChecksTheMoves(): void
    {
        $config = new Config(false);
        $config->merge(['config' => ['lock' => true]]);

        $checker = MoveCheckers::forProject(self::root(), LockFile::fromArray(['packages' => [['name' => 'acme/lib', 'version' => '1.0.0']]]), ProjectConfig::empty(), $config, [], '8.4', false, Deadline::never());

        self::assertSame(MoveCheck::RESOLVES, $checker->baseline()->check()->result());
    }

    /** @return iterable<string, array{array<array-key, mixed>, ?string}> the platform and its configured php */
    public static function platformsThatComposerRefuses(): iterable
    {
        yield 'php false' => [['php' => false], null];
        yield 'an extension true' => [['php' => '8.4.3', 'ext-intl' => true], '8.4.3'];
        yield 'a library as a number' => [['lib-lockrot-test' => 1], null];
        yield 'a name that is no platform package' => [['php' => '8.4.3', 'acme/lib' => '1.0.0'], '8.4.3'];
        yield 'php as a constraint' => [['php' => '^8.2'], '^8.2'];
        yield 'php that is no version' => [['php' => 'eight'], 'eight'];
        yield 'php in capitals as false' => [['PHP' => false], null];
        yield 'php in capitals as false after a php' => [['php' => '8.4.3', 'PHP' => false], null];
        yield 'an extension as a constraint' => [['php' => '8.4.3', 'ext-intl' => '^1.0'], '8.4.3'];
        yield 'a list, whose names are numbers' => [['8.4.3'], null];
    }

    /**
     * Composer disables a platform package other than php that `config.platform` sets to false,
     * and normalises the last value of each name in any letter case.
     *
     * @dataProvider platformsThatComposerAccepts
     *
     * @param array<string, string|false> $platform
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('platformsThatComposerAccepts')]
    public function testAConfigPlatformValueThatComposerAcceptsKeepsTheMoveCheck(array $platform): void
    {
        $config = new Config(false);
        $config->merge(['config' => ['platform' => $platform]]);

        $checker = MoveCheckers::forProject(self::root(), LockFile::fromArray(['packages' => [['name' => 'acme/lib', 'version' => '1.0.0']]]), ProjectConfig::empty(), $config, [], '8.4', false, Deadline::never());

        self::assertSame(MoveCheck::RESOLVES, $checker->baseline()->check()->result());
    }

    /** @return iterable<string, array{array<string, string|false>}> */
    public static function platformsThatComposerAccepts(): iterable
    {
        yield 'a disabled extension, a library and a dev version' => [['php' => '8.4.3', 'ext-intl' => false, 'lib-icu' => '72.1', 'ext-json' => '8.4.x-dev']];
        yield 'php false in capitals before a php' => [['PHP' => false, 'php' => '8.4.3']];
        yield 'a constraint before a php in capitals' => [['php' => '^8.2', 'PHP' => '8.4.3']];
    }

    /** Composer lower-cases each `config.platform` name, so `PHP` is the project's php. */
    public function testAConfiguredPlatformPhpWrittenInCapitalsIsTheProjectsPhp(): void
    {
        $config = new Config(false);
        $config->merge(['config' => ['platform' => ['PHP' => '8.4.3']]]);

        $checker = MoveCheckers::forProject(self::root(), LockFile::fromArray(['packages' => [['name' => 'acme/lib', 'version' => '1.0.0']]]), ProjectConfig::empty(), $config, [], '8.4', false, Deadline::never());

        self::assertSame(['8.4.3', '8.4.3'], [$checker->platformPhp(), $checker->configuredPhp()]);
    }

    /** Ruling R6-2: a library that `config.platform` sets is the project's fact, whatever case it is written in. */
    public function testAReleaseThatNeedsMoreThanAConfiguredLibraryIsRefused(): void
    {
        $config = new Config(false);
        $config->merge(['config' => ['platform' => ['Lib-Lockrot-Test' => '1.0.0']]]);
        $loader = new ArrayLoader();
        $repository = new RecordingComposerRepository([
            $loader->load(['name' => 'acme/lib', 'version' => '1.0.0']),
            $loader->load(['name' => 'acme/lib', 'version' => '1.1.0', 'require' => ['lib-lockrot-test' => '^2.0']]),
        ]);
        $lock = LockFile::fromArray(['packages' => [['name' => 'acme/lib', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/']]]);

        $check = MoveCheckers::forProject(self::root(), $lock, ProjectConfig::empty(), $config, [$repository], '8.4', false, Deadline::never())->check(MoveQuery::update('acme/lib', '^1.1', '1.1.0'))->check();

        self::assertSame(MoveCheck::REFUSED, $check->result());
    }

    /** `target_fix.platform_step` reads the configured php: the solve runs at the target minor's newest patch instead. */
    public function testTheProjectsMoveCheckKeepsAConfiguredPlatformPhpOutsideTheTargetMinor(): void
    {
        $config = new Config(false);
        $config->merge(['config' => ['platform' => ['php' => '7.4.29']]]);

        $checker = MoveCheckers::forProject(self::root(), LockFile::fromArray(['packages' => [['name' => 'acme/lib', 'version' => '1.0.0']]]), ProjectConfig::empty(), $config, [], '8.4', false, Deadline::never());

        self::assertSame(['8.4.99', '7.4.29'], [$checker->platformPhp(), $checker->configuredPhp()]);
        self::assertNull(MoveCheckers::forProject(self::root(), LockFile::fromArray(['packages' => []]), ProjectConfig::empty(), new Config(false), [], '8.4', false, Deadline::never())->configuredPhp());
    }

    /** The PHP window reads `php-64bit` from the config that the solve reads. */
    public function testTheProjectsMoveCheckReportsTheConfiguredPhp64bit(): void
    {
        $lock = LockFile::fromArray(['packages' => [['name' => 'acme/lib', 'version' => '1.0.0']]]);
        $checker = static function (array $platform) use ($lock): MoveChecker {
            $config = new Config(false);
            $config->merge(['config' => ['platform' => $platform]]);

            return MoveCheckers::forProject(self::root(), $lock, ProjectConfig::empty(), $config, [], '8.4', false, Deadline::never());
        };

        self::assertSame('7.4.0', $checker(['PHP-64bit' => '7.4.0'])->configuredPhp64bit());
        self::assertFalse($checker(['php-64bit' => false])->configuredPhp64bit());
        self::assertNull($checker(['php' => '8.4.1'])->configuredPhp64bit());
        self::assertSame('7.4.0', $checker(['php-64bit' => '7.4.0', 'php' => false])->configuredPhp64bit(), 'a config that Composer refuses');
        self::assertFalse($checker(['PHP-64bit' => false, 'php' => false])->configuredPhp64bit(), 'a config that Composer refuses');
    }

    /** laminas at 8.4: lockrot set the php of the solve, so Composer's words about config.platform do not hold. */
    public function testTheBaselineProblemOmitsTheConfigPlatformClauseWhenLockrotSetThePhp(): void
    {
        $checker = MoveCheckers::forProject(self::root(['php' => '~8.1.0']), LockFile::fromArray(['packages' => []]), ProjectConfig::empty(), new Config(false), [], '8.4', false, Deadline::never());

        $problem = (string) $checker->baseline()->problem();

        self::assertStringContainsString('your php version (8.4.99) does not satisfy', $problem);
    }

    /** Composer gives php-64bit the version of the php override, with the same words. */
    public function testTheBaselineProblemOmitsTheConfigPlatformClauseOfAPackageThatTheSetPhpOverrides(): void
    {
        $checker = MoveCheckers::forProject(self::root(['php-64bit' => '~8.1.0']), LockFile::fromArray(['packages' => []]), ProjectConfig::empty(), new Config(false), [], '8.4', false, Deadline::never());

        self::assertStringContainsString('your php-64bit version (8.4.99) does not satisfy', (string) $checker->baseline()->problem());
    }

    public function testTheBaselineProblemKeepsTheConfigPlatformClauseOfTheProjectsOwnPhp(): void
    {
        $config = new Config(false);
        $config->merge(['config' => ['platform' => ['php' => '8.4.3']]]);

        $checker = MoveCheckers::forProject(self::root(['php' => '~8.1.0']), LockFile::fromArray(['packages' => []]), ProjectConfig::empty(), $config, [], '8.4', false, Deadline::never());

        self::assertStringContainsString('your php version (8.4.3; overridden via config.platform) does not satisfy', (string) $checker->baseline()->problem());
    }

    /** A locked package without a Composer repository's notification-url keeps its locked version only, in packages-dev too. */
    public function testTheProjectsMoveCheckChecksNoMoveOfALockedPackageThatNoComposerRepositoryServes(): void
    {
        $lock = LockFile::fromArray(['packages' => [['name' => 'acme/lib', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/']], 'packages-dev' => [['name' => 'acme/own', 'version' => '1.0.0']]]);

        $checker = MoveCheckers::forProject(self::root(), $lock, ProjectConfig::empty(), new Config(false), [], '8.4', false, Deadline::never());

        self::assertSame(MoveCheck::NOT_FROM_COMPOSER_REPOSITORY, $checker->check(MoveQuery::update('acme/own', '^1.1', '1.1.0'))->check()->reason());
        self::assertNotSame(MoveCheck::NOT_FROM_COMPOSER_REPOSITORY, $checker->check(MoveQuery::update('acme/lib', '^1.1', '1.1.0'))->check()->reason());
    }

    /**
     * In a partial update, Composer reads a symlinked path package from its path repository, not
     * from the lock. The solve offers the lock's version in its place.
     */
    public function testTheProjectsBaselineResolvesWithARootRequirementOnALockedPathPackage(): void
    {
        $root = (new ArrayLoader())->load(['name' => 'acme/app', 'version' => '1.0.0', 'require' => ['acme/core' => '^7.0']], RootPackage::class);
        self::assertInstanceOf(RootPackage::class, $root);
        $root->setMinimumStability('dev');
        $lock = LockFile::fromArray(['packages' => [['name' => 'acme/core', 'version' => '7.0.0-dev', 'dist' => ['type' => 'path', 'url' => 'app', 'reference' => 'abc']]]]);

        $checker = MoveCheckers::forProject($root, $lock, ProjectConfig::empty(), new Config(false), [], '8.4', false, Deadline::never());

        self::assertSame(MoveCheck::RESOLVES, $checker->baseline()->check()->result());
    }

    /** A move that frees its dependencies keeps a locked path package that two locked packages require. */
    public function testAMoveWithDependenciesKeepsALockedPathPackageThatItsDependenciesRequire(): void
    {
        $root = (new ArrayLoader())->load(['name' => 'acme/app', 'version' => '1.0.0', 'require' => ['acme/lib' => '^1.0', 'acme/x' => '^1.0']], RootPackage::class);
        self::assertInstanceOf(RootPackage::class, $root);
        $root->setMinimumStability('dev');
        $packagist = ['notification-url' => 'https://packagist.org/downloads/'];
        $lock = LockFile::fromArray(['packages' => [
            ['name' => 'acme/lib', 'version' => '1.0.0', 'require' => ['acme/core' => '^7.0', 'acme/zeta' => '^1.0']] + $packagist,
            ['name' => 'acme/zeta', 'version' => '1.0.0'] + $packagist,
            ['name' => 'acme/x', 'version' => '1.0.0', 'require' => ['acme/core' => '^7.0']] + $packagist,
            ['name' => 'acme/core', 'version' => '7.0.0-dev', 'dist' => ['type' => 'path', 'url' => 'core', 'reference' => 'abc']],
        ]]);
        $loader = new ArrayLoader();
        $repository = new RecordingComposerRepository([
            $loader->load(['name' => 'acme/lib', 'version' => '1.0.0', 'require' => ['acme/core' => '^7.0', 'acme/zeta' => '^1.0']]),
            $loader->load(['name' => 'acme/lib', 'version' => '1.1.0', 'require' => ['acme/core' => '^7.0', 'acme/zeta' => '^2.0']]),
            $loader->load(['name' => 'acme/zeta', 'version' => '1.0.0']),
            $loader->load(['name' => 'acme/zeta', 'version' => '2.0.0']),
            $loader->load(['name' => 'acme/x', 'version' => '1.0.0', 'require' => ['acme/core' => '^7.0']]),
        ]);

        $checker = MoveCheckers::forProject($root, $lock, ProjectConfig::empty(), new Config(false), [$repository], '8.4', false, Deadline::never());
        $check = $checker->check(MoveQuery::update('acme/lib', '^1.1', '1.1.0'))->check();

        self::assertSame([MoveCheck::RESOLVES, MoveCheck::WITH_DEPENDENCIES], [$check->result(), $check->form()]);
    }

    /**
     * UpdateCommand and RequireCommand read `--minimal-changes` from COMPOSER_MINIMAL_CHANGES, cast
     * to bool, or from the config key.
     *
     * @dataProvider minimalChangesSettings
     *
     * @param array<string, mixed> $config
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('minimalChangesSettings')]
    public function testTheMoveCheckReadsMinimalChangesAsComposerDoes(array $config, ?string $variable, bool $minimal): void
    {
        $composerConfig = new Config(false);
        $composerConfig->merge(['config' => $config]);
        if ($variable !== null) {
            Platform::putEnv('COMPOSER_MINIMAL_CHANGES', $variable);
        }
        try {
            self::assertSame($minimal, MoveCheckers::minimalChanges($composerConfig));
        } finally {
            Platform::clearEnv('COMPOSER_MINIMAL_CHANGES');
        }
    }

    /** @return iterable<string, array{array<string, mixed>, ?string, bool}> the Composer config, the variable's value, whether the solve keeps changes minimal */
    public static function minimalChangesSettings(): iterable
    {
        yield 'neither' => [[], null, false];
        yield 'the config key' => [['update-with-minimal-changes' => true], null, true];
        yield 'the config key off' => [['update-with-minimal-changes' => false], null, false];
        yield 'the variable' => [[], '1', true];
        yield 'the variable at 0' => [[], '0', false];
        yield 'the variable at an empty value' => [[], '', false];
    }

    /** R5-33: Composer takes acme/x from the canonical package repository, which defines 1.0.0 only, so it refuses acme/lib 2.0.0. */
    public function testAPackageRepositoryBeforeTheComposerRepositoryHidesItsVersionsOfTheName(): void
    {
        $root = (new ArrayLoader())->load(['name' => 'acme/app', 'version' => '1.0.0', 'require' => ['acme/lib' => '^1.0']], RootPackage::class);
        self::assertInstanceOf(RootPackageInterface::class, $root);
        $lock = LockFile::fromArray(['packages' => [['name' => 'acme/lib', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/']]]);
        $loader = new ArrayLoader();
        $package = new PackageRepository(['package' => [['name' => 'acme/x', 'version' => '1.0.0']]]);
        $repository = new RecordingComposerRepository([
            $loader->load(['name' => 'acme/lib', 'version' => '1.0.0']),
            $loader->load(['name' => 'acme/lib', 'version' => '2.0.0', 'require' => ['acme/x' => '^2.0']]),
            $loader->load(['name' => 'acme/x', 'version' => '2.0.0']),
        ]);

        $check = MoveCheckers::forProject($root, $lock, ProjectConfig::empty(), new Config(false), [$package, $repository], '8.4', false, Deadline::never())->check(MoveQuery::require('acme/lib', '^2.0', '2.0.0'))->check();

        self::assertSame(MoveCheck::REFUSED, $check->result());
    }

    /** R5-33: the lock records acme/lib from the URL of the vcs repository, which lockrot does not load and which can hide its versions. */
    public function testAMoveOfANameThatTheLockRecordsFromAnEarlierVcsRepositoryIsNotChecked(): void
    {
        $lock = LockFile::fromArray(['packages' => [['name' => 'acme/lib', 'version' => '1.0.0', 'source' => ['type' => 'git', 'url' => 'https://github.com/acme/lib.git', 'reference' => 'abc'], 'notification-url' => 'https://packagist.org/downloads/']]]);
        $loader = new ArrayLoader();
        $vcs = new ConfiguredRepository(['type' => 'vcs', 'url' => 'https://github.com/acme/lib']);
        $repository = new RecordingComposerRepository([
            $loader->load(['name' => 'acme/lib', 'version' => '1.0.0']),
            $loader->load(['name' => 'acme/lib', 'version' => '1.1.0']),
        ]);

        $check = MoveCheckers::forProject(self::root(), $lock, ProjectConfig::empty(), new Config(false), [$vcs, $repository], '8.4', false, Deadline::never())->check(MoveQuery::update('acme/lib', '^1.1', '1.1.0'))->check();

        self::assertSame([MoveCheck::NOT_CHECKED, MoveCheck::UNLOADED_NAME], [$check->result(), $check->reason()]);
    }

    /**
     * Ruling R14-1: no Composer repository lists acme/private, which the vcs repository can serve.
     * Without it, acme/lib 1.2.0 cannot install, so the solve resolves at 1.1.0 while Composer can
     * install 1.2.0. A virtual name that a pool package provides keeps the solve resolving.
     *
     * @dataProvider unreachedRequirements
     *
     * @param array<string, string> $require the requirements of acme/lib 1.2.0
     * @param array{string, ?string, ?string} $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unreachedRequirements')]
    public function testAResolvingSolveThatLosesAReleaseToAnUnreachedNameIsNotChecked(array $require, array $expected): void
    {
        $packagist = ['notification-url' => 'https://packagist.org/downloads/'];
        $lock = LockFile::fromArray(['packages' => [
            ['name' => 'acme/lib', 'version' => '1.0.0'] + $packagist,
            ['name' => 'acme/logger', 'version' => '1.0.0', 'provide' => ['psr/log-implementation' => '1.0.0']] + $packagist,
        ]]);
        $loader = new ArrayLoader();
        $vcs = new ConfiguredRepository(['type' => 'vcs', 'url' => 'https://git.example.org/acme/private']);
        $repository = new RecordingComposerRepository([
            $loader->load(['name' => 'acme/lib', 'version' => '1.0.0']),
            $loader->load(['name' => 'acme/lib', 'version' => '1.1.0']),
            $loader->load(['name' => 'acme/lib', 'version' => '1.2.0', 'require' => $require]),
            $loader->load(['name' => 'acme/logger', 'version' => '1.0.0', 'provide' => ['psr/log-implementation' => '1.0.0']]),
        ]);
        $checker = MoveCheckers::forProject(self::root(['acme/lib' => '^1.0', 'acme/logger' => '^1.0']), $lock, ProjectConfig::empty(), new Config(false), [$vcs, $repository], '8.4', false, Deadline::never());

        $check = $checker->check(MoveQuery::update('acme/lib', '^1.1', '1.1.0'))->check();

        self::assertSame($expected, [$check->result(), $check->reason(), $check->installs()]);
        self::assertSame(MoveCheck::RESOLVES, $checker->baseline()->check()->result());
    }

    /** @return iterable<string, array{array<string, string>, array{string, ?string, ?string}}> */
    public static function unreachedRequirements(): iterable
    {
        yield 'a name that no pool package provides' => [['acme/private' => '^1.0'], [MoveCheck::NOT_CHECKED, MoveCheck::UNLOADED_NAME, null]];
        yield 'a version of a provided name that no pool package provides' => [['psr/log-implementation' => '^2.0'], [MoveCheck::NOT_CHECKED, MoveCheck::UNLOADED_NAME, null]];
        yield 'a virtual name that a pool package provides' => [['psr/log-implementation' => '^1.0'], [MoveCheck::RESOLVES, null, '1.2.0']];
        yield 'a met virtual name on a release that the target php refuses' => [['psr/log-implementation' => '^1.0', 'php' => '>=9.0'], [MoveCheck::RESOLVES, null, '1.1.0']];
    }

    /** docs/verdicts.md, move-check limits: a solve that needs another release of a vcs-locked name proves nothing. */
    public function testAMoveThatNeedsAnotherReleaseOfAVcsLockedNameIsNotChecked(): void
    {
        $packagist = ['notification-url' => 'https://packagist.org/downloads/'];
        $lock = LockFile::fromArray(['packages' => [
            ['name' => 'acme/lib', 'version' => '1.0.0', 'require' => ['acme/core' => '^1.0']] + $packagist,
            ['name' => 'acme/core', 'version' => '1.0.0', 'source' => ['type' => 'git', 'url' => 'https://git.example.org/acme/core.git', 'reference' => 'abc']],
        ]]);
        $loader = new ArrayLoader();
        $repository = new RecordingComposerRepository([
            $loader->load(['name' => 'acme/lib', 'version' => '1.0.0', 'require' => ['acme/core' => '^1.0']]),
            $loader->load(['name' => 'acme/lib', 'version' => '1.1.0', 'require' => ['acme/core' => '^2.0']]),
            $loader->load(['name' => 'acme/core', 'version' => '2.0.0']),
        ]);

        $check = MoveCheckers::forProject(self::root(), $lock, ProjectConfig::empty(), new Config(false), [$repository], '8.4', false, Deadline::never())->check(MoveQuery::update('acme/lib', '^1.1', '1.1.0'))->check();

        self::assertSame([MoveCheck::NOT_CHECKED, MoveCheck::UNLOADED_NAME], [$check->result(), $check->reason()]);
    }

    /** `-W` frees a root requirement that a VCS repository serves: the solve offers its locked version, and no other. */
    public function testAMoveWithAllDependenciesKeepsTheLockedVersionOfAVcsPackageThatItFrees(): void
    {
        $root = (new ArrayLoader())->load(['name' => 'acme/app', 'version' => '1.0.0', 'require' => ['acme/lib' => '^1.0', 'acme/zeta' => '^1.0 || ^2.0', 'acme/vcs' => '^1.0']], RootPackage::class);
        self::assertInstanceOf(RootPackageInterface::class, $root);
        $packagist = ['notification-url' => 'https://packagist.org/downloads/'];
        $lock = LockFile::fromArray(['packages' => [
            ['name' => 'acme/lib', 'version' => '1.0.0', 'require' => ['acme/vcs' => '^1.0', 'acme/zeta' => '^1.0']] + $packagist,
            ['name' => 'acme/zeta', 'version' => '1.0.0'] + $packagist,
            ['name' => 'acme/vcs', 'version' => '1.0.0', 'source' => ['type' => 'git', 'url' => 'https://git.example.org/acme/vcs.git', 'reference' => 'abc']],
        ]]);
        $loader = new ArrayLoader();
        $repository = new RecordingComposerRepository([
            $loader->load(['name' => 'acme/lib', 'version' => '1.0.0', 'require' => ['acme/vcs' => '^1.0', 'acme/zeta' => '^1.0']]),
            $loader->load(['name' => 'acme/lib', 'version' => '1.1.0', 'require' => ['acme/vcs' => '^1.0', 'acme/zeta' => '^2.0']]),
            $loader->load(['name' => 'acme/zeta', 'version' => '1.0.0']),
            $loader->load(['name' => 'acme/zeta', 'version' => '2.0.0']),
            $loader->load(['name' => 'acme/vcs', 'version' => '1.1.0', 'require' => ['acme/missing' => '^1.0']]),
        ]);

        $checker = MoveCheckers::forProject($root, $lock, ProjectConfig::empty(), new Config(false), [$repository], '8.4', false, Deadline::never());
        $check = $checker->check(MoveQuery::update('acme/lib', '^1.1', '1.1.0'))->check();

        self::assertSame([MoveCheck::RESOLVES, MoveCheck::WITH_ALL_DEPENDENCIES], [$check->result(), $check->form()]);
    }

    /**
     * Ruling R2-24: under `advisory-lookup: composer-repositories`, the `-W` solve frees acme/vcs,
     * which a VCS repository serves. The move check keeps its name out of the advisory lookup, and
     * the resolve that still holds it proves nothing (ruling R2-47, widened by R6).
     */
    public function testTheMoveChecksAdvisoryLookupKeepsOutAVcsNameThatAMoveWithAllDependenciesFrees(): void
    {
        $root = (new ArrayLoader())->load(['name' => 'acme/app', 'version' => '1.0.0', 'require' => ['acme/lib' => '^1.0', 'acme/zeta' => '^1.0 || ^2.0', 'acme/vcs' => '^1.0']], RootPackage::class);
        self::assertInstanceOf(RootPackageInterface::class, $root);
        $packagist = ['notification-url' => 'https://packagist.org/downloads/'];
        $lock = LockFile::fromArray(['packages' => [
            ['name' => 'acme/lib', 'version' => '1.0.0', 'require' => ['acme/vcs' => '^1.0', 'acme/zeta' => '^1.0']] + $packagist,
            ['name' => 'acme/zeta', 'version' => '1.0.0'] + $packagist,
            ['name' => 'acme/vcs', 'version' => '1.0.0', 'source' => ['type' => 'git', 'url' => 'https://git.example.org/acme/vcs.git', 'reference' => 'abc']],
        ]]);
        $loader = new ArrayLoader();
        $repository = new RecordingComposerRepository([
            $loader->load(['name' => 'acme/lib', 'version' => '1.0.0', 'require' => ['acme/vcs' => '^1.0', 'acme/zeta' => '^1.0']]),
            $loader->load(['name' => 'acme/lib', 'version' => '1.1.0', 'require' => ['acme/vcs' => '^1.0', 'acme/zeta' => '^2.0']]),
            $loader->load(['name' => 'acme/zeta', 'version' => '1.0.0']),
            $loader->load(['name' => 'acme/zeta', 'version' => '2.0.0']),
        ]);
        $feed = new class () extends ArrayRepository implements AdvisoryProviderInterface {
            /** @var list<string> */
            public array $asked = [];

            public function getRepoName(): string
            {
                return 'feed';
            }

            public function hasSecurityAdvisories(): bool
            {
                return true;
            }

            public function getSecurityAdvisories(array $packageConstraintMap, bool $allowPartialAdvisories = false): array
            {
                $this->asked = array_merge($this->asked, array_map('strval', array_keys($packageConstraintMap)));

                return ['namesFound' => [], 'advisories' => []];
            }
        };

        $checker = MoveCheckers::forProject($root, $lock, ProjectConfig::empty(), new Config(false), [$repository, $feed], '8.4', false, Deadline::never(), AdvisoryCoverage::SCOPE_COMPOSER_REPOSITORIES)
            ->withAdvisories([], ['acme/lib', 'acme/zeta']);
        $check = $checker->check(MoveQuery::update('acme/lib', '^1.1', '1.1.0'))->check();

        self::assertSame([MoveCheck::NOT_CHECKED, MoveCheck::ADVISORY_LOOKUP_INCOMPLETE], [$check->result(), $check->reason()]);
        self::assertNotContains('acme/vcs', $feed->asked, 'asked: '.implode(', ', $feed->asked));
    }

    /** A locked VCS branch keeps its branch alias, which the root requirement matches. */
    public function testAMoveWithAllDependenciesKeepsTheBranchAliasOfALockedVcsBranch(): void
    {
        $root = (new ArrayLoader())->load(['name' => 'acme/app', 'version' => '1.0.0', 'require' => ['acme/lib' => '^1.0', 'acme/zeta' => '^1.0 || ^2.0', 'acme/vcs' => '^1.0@dev']], RootPackage::class);
        self::assertInstanceOf(RootPackage::class, $root);
        $root->setStabilityFlags(['acme/vcs' => BasePackage::STABILITY_DEV]);
        $packagist = ['notification-url' => 'https://packagist.org/downloads/'];
        $lock = LockFile::fromArray(['packages' => [
            ['name' => 'acme/lib', 'version' => '1.0.0', 'require' => ['acme/vcs' => '^1.0@dev', 'acme/zeta' => '^1.0']] + $packagist,
            ['name' => 'acme/zeta', 'version' => '1.0.0'] + $packagist,
            ['name' => 'acme/vcs', 'version' => 'dev-main', 'extra' => ['branch-alias' => ['dev-main' => '1.x-dev']], 'source' => ['type' => 'git', 'url' => 'https://git.example.org/acme/vcs.git', 'reference' => 'abc']],
        ]]);
        $loader = new ArrayLoader();
        $repository = new RecordingComposerRepository([
            $loader->load(['name' => 'acme/lib', 'version' => '1.0.0', 'require' => ['acme/vcs' => '^1.0@dev', 'acme/zeta' => '^1.0']]),
            $loader->load(['name' => 'acme/lib', 'version' => '1.1.0', 'require' => ['acme/vcs' => '^1.0@dev', 'acme/zeta' => '^2.0']]),
            $loader->load(['name' => 'acme/zeta', 'version' => '1.0.0']),
            $loader->load(['name' => 'acme/zeta', 'version' => '2.0.0']),
        ]);

        $checker = MoveCheckers::forProject($root, $lock, ProjectConfig::empty(), new Config(false), [$repository], '8.4', false, Deadline::never());
        $check = $checker->check(MoveQuery::update('acme/lib', '^1.1', '1.1.0'))->check();

        self::assertSame([MoveCheck::RESOLVES, MoveCheck::WITH_ALL_DEPENDENCIES], [$check->result(), $check->form()]);
    }

    /** @param array<string, string> $requires */
    private static function root(array $requires = ['acme/lib' => '^1.0']): RootPackageInterface
    {
        $root = (new ArrayLoader())->load(['name' => 'acme/app', 'version' => '1.0.0', 'require' => $requires], RootPackage::class);
        self::assertInstanceOf(RootPackageInterface::class, $root);

        return $root;
    }
}
