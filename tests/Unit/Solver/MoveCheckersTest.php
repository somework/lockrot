<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Solver;

use Composer\Config;
use Composer\Package\BasePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\RootPackage;
use Composer\Package\RootPackageInterface;
use Composer\Repository\PlatformRepository;
use Lockrot\Deadline;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Solver\ComposerSolver;
use Lockrot\Solver\MoveCheck;
use Lockrot\Solver\MoveCheckers;
use Lockrot\Solver\MoveQuery;
use Lockrot\Solver\SolverChecker;
use Lockrot\Tests\Support\ArrayVersionSource;
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
        $solver = new ComposerSolver(self::root(), LockFile::fromArray(['packages' => [['name' => 'acme/lib', 'version' => '1.0.0']]])->lockedRepository(), new PlatformRepository([], ['php' => $php]), new ArrayVersionSource(['acme/lib' => [['version' => '1.0.0'], ['version' => '1.1.0', 'require' => ['php' => '>=8.4.1']]]]), []);

        $outcome = (new SolverChecker($solver, [], $php, false, Deadline::never()))->check(MoveQuery::update('acme/lib', '^1.1.0', '1.1.0'));

        self::assertSame('8.4', $php);
        self::assertSame(MoveCheck::REFUSED, $outcome->check()->result());
    }

    public function testThePlatformPhpIsTheNewestPatchOfTheTargetWithoutAConfiguredOneInIt(): void
    {
        self::assertSame('8.4.99', MoveCheckers::platformPhp('8.4', null));
        self::assertSame('8.4.99', MoveCheckers::platformPhp('8.4', '7.4.29'));
        self::assertSame('8.4.99', MoveCheckers::platformPhp('8.4', '8.41.0'));
        self::assertSame('8.4.99', MoveCheckers::platformPhp('8.4', '8.4-dev'));
    }

    /** The regular expression reads the target minor literally: its dot matches only a dot. */
    public function testAPlatformPhpThatMatchesTheTargetMinorOnlyAsAPatternIsNotInIt(): void
    {
        self::assertSame('8.1.99', MoveCheckers::platformPhp('8.1', '801.2'));
    }

    /** Composer refuses a platform version that is no string and not false: the move check leaves it out. */
    public function testTheProjectsMoveCheckSolvesAtTheConfiguredPlatformPhpOfTheTargetMinor(): void
    {
        $config = new Config(false);
        $config->merge(['config' => ['platform' => ['php' => '8.4.3', 'ext-lockrot-test' => false, 'lib-lockrot-test' => 1]]]);

        $checker = MoveCheckers::forProject(self::root(), LockFile::fromArray(['packages' => [['name' => 'acme/lib', 'version' => '1.0.0']]]), ProjectConfig::empty(), $config, [], '8.4', false, Deadline::never());

        self::assertSame('8.4.3', $checker->platformPhp());
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

    /** A locked package without a Composer repository's notification-url keeps its locked version only, in packages-dev too. */
    public function testTheProjectsMoveCheckChecksNoMoveOfALockedPackageThatNoComposerRepositoryServes(): void
    {
        $lock = LockFile::fromArray(['packages' => [['name' => 'acme/lib', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/']], 'packages-dev' => [['name' => 'acme/own', 'version' => '1.0.0']]]);

        $checker = MoveCheckers::forProject(self::root(), $lock, ProjectConfig::empty(), new Config(false), [], '8.4', false, Deadline::never());

        self::assertSame(MoveCheck::NOT_FROM_COMPOSER_REPOSITORY, $checker->check(MoveQuery::update('acme/own', '^1.1', '1.1.0'))->check()->reason());
        self::assertNotSame(MoveCheck::NOT_FROM_COMPOSER_REPOSITORY, $checker->check(MoveQuery::update('acme/lib', '^1.1', '1.1.0'))->check()->reason());
    }

    /**
     * Composer never locks a path package in a partial update: it reads the package from the path
     * repository. The solve offers the lock's version in its place.
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

    private static function root(): RootPackageInterface
    {
        $root = (new ArrayLoader())->load(['name' => 'acme/app', 'version' => '1.0.0', 'require' => ['acme/lib' => '^1.0']], RootPackage::class);
        self::assertInstanceOf(RootPackageInterface::class, $root);

        return $root;
    }
}
