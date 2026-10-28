<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Analyzer;

use Composer\Package\Loader\ArrayLoader;
use Composer\Package\RootPackage;
use Composer\Package\RootPackageInterface;
use Composer\Repository\PlatformRepository;
use Lockrot\Analyzer\PhpWindow;
use Lockrot\Analyzer\TargetFix;
use Lockrot\Clock;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Deadline;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Security\Holder;
use Lockrot\Solver\AdvisoryBlocking;
use Lockrot\Solver\ComposerSolver;
use Lockrot\Solver\MoveCheck;
use Lockrot\Solver\MoveChecker;
use Lockrot\Solver\PoolAdvisories;
use Lockrot\Solver\SolverChecker;
use Lockrot\Solver\UncheckedMoves;
use Lockrot\Solver\VersionSource;
use Lockrot\Tests\Support\ArrayVersionSource;
use Lockrot\Tests\Support\RecordedMoveChecker;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type Data from PhpWindow
 * @phpstan-import-type Fix from PhpWindow
 */
final class TargetFixTest extends TestCase
{
    private const CAPPED = ['name' => 'acme/capped', 'version' => '1.0.0', 'require' => ['php' => '>=7.4 <8.4']];

    public function testATargetInsideTheWindowHasNoFix(): void
    {
        $data = self::data(['packages' => [['name' => 'acme/lib', 'version' => '1.0.0', 'require' => ['php' => '>=7.4']]], 'packages-dev' => []], [], self::resolving());

        self::assertTrue($data['target']['inside']);
        self::assertNull($data['target_fix']);
        self::assertSame('target_fix', array_key_last($data), 'target_fix is the last key of run.php_window');
    }

    /** drupal-site at 7.2: the lowest edge, 7.3.0, excludes the target, and no update lowers it. */
    public function testATargetBelowTheLowestEdgeHasNoFix(): void
    {
        $data = self::data(['packages' => [['name' => 'acme/lib', 'version' => '1.0.0', 'require' => ['php' => '>=7.3']]], 'packages-dev' => []], [], self::resolving(), '7.2');

        self::assertFalse($data['target']['inside']);
        self::assertNull($data['target_fix']);
    }

    public function testARunThatChecksNoMoveListsThePackagesWithNoReason(): void
    {
        $fix = self::fix(['packages' => [self::CAPPED], 'packages-dev' => []], [], null);

        self::assertSame([
            'packages' => [['name' => 'acme/capped', 'version' => '1.0.0', 'link' => 'require', 'target' => 'php', 'constraint' => '>=7.4 <8.4', 'dev' => false]],
            'lock_platform' => [],
            'command' => null,
            'check' => 'not_checked',
            'reason' => null,
            'held_by' => [],
            'platform_step' => false,
        ], $fix);
    }

    /** monica at 8.5: each listed name once, production first, then packages-dev, with the long option. */
    public function testAResolvingFixNamesEachListedPackageOnceInTheCommand(): void
    {
        $lock = ['packages' => [
            ['name' => 'acme/zeta', 'version' => '1.0.0', 'require' => ['php' => '<8.4', 'php-64bit' => '<8.4']],
            ['name' => 'acme/alpha', 'version' => '2.0.0', 'require' => ['php' => '<8.4']],
        ], 'packages-dev' => [['name' => 'acme/dev-tool', 'version' => '1.0.0', 'require' => ['php' => '<8.4']]]];

        $fix = self::fix($lock, [], self::recorded(['result' => 'resolves', 'form' => 'with-all-dependencies']));

        self::assertSame([['acme/alpha', 'php', false], ['acme/zeta', 'php', false], ['acme/zeta', 'php-64bit', false], ['acme/dev-tool', 'php', true]], array_map(static fn (array $req): array => [$req['name'], $req['target'], $req['dev']], $fix['packages']));
        self::assertSame(['composer', 'update', 'acme/alpha', 'acme/zeta', 'acme/dev-tool', '--with-all-dependencies'], $fix['command']);
        self::assertSame(['resolves', null, []], [$fix['check'], $fix['reason'], $fix['held_by']]);
    }

    public function testAFixThatResolvesAsListedAddsNoOption(): void
    {
        $fix = self::fix(['packages' => [self::CAPPED], 'packages-dev' => []], [], self::recorded(['result' => 'resolves', 'form' => 'inline']));

        self::assertSame(['composer', 'update', 'acme/capped'], $fix['command']);
    }

    /** wallabag 2.6.14 at 8.4: the root requires the one 2.x release, and it requires php ^7.0. */
    public function testARefusedFixKeepsTheRootRequirementAndDropsTheListedPackagesOwnPhpHold(): void
    {
        $holds = [
            ['source' => 'root', 'link' => 'root-require', 'target' => 'acme/capped', 'constraint' => '^1.0'],
            ['source' => 'package', 'package' => 'acme/capped', 'version' => '1.0.0', 'link' => 'platform', 'target' => 'php', 'constraint' => '>=7.4 <8.4'],
            ['source' => 'package', 'package' => 'acme/other', 'version' => '3.0.0', 'link' => 'platform', 'target' => 'php', 'constraint' => '<8.0'],
        ];

        $fix = self::fix(['packages' => [self::CAPPED], 'packages-dev' => []], [], self::recorded(['result' => 'refused', 'holds' => $holds]));

        self::assertSame(['refused', null, null], [$fix['check'], $fix['command'], $fix['reason']]);
        self::assertSame([['root', 'root-require', 'acme/capped'], ['package', 'platform', 'php']], self::holds($fix), 'the listed package\'s platform hold restates packages; another package\'s stays');
        self::assertSame([null, 'acme/other'], array_map(static fn (Holder $hold): ?string => $hold->source() === Holder::ROOT ? null : $hold->package(), $fix['held_by']));
    }

    public function testARefusedFixDropsTheListedPackagesOwnPhp64bitHold(): void
    {
        $holds = [
            ['source' => 'package', 'package' => 'acme/capped', 'version' => '1.0.0', 'link' => 'platform', 'target' => 'php-64bit', 'constraint' => '<8.3'],
            ['source' => 'package', 'package' => 'acme/other', 'version' => '3.0.0', 'link' => 'platform', 'target' => 'php-64bit', 'constraint' => '<8.0'],
        ];

        $fix = self::fix(['packages' => [self::CAPPED], 'packages-dev' => []], [], self::recorded(['result' => 'refused', 'holds' => $holds]));

        self::assertSame(['acme/other'], array_map(static fn (Holder $hold): ?string => $hold->package(), $fix['held_by']), 'the listed package\'s php-64bit hold restates packages; another package\'s stays');
    }

    public function testARefusedFixKeepsEveryHoldOfAListedPackageThatIsNotOnPhp(): void
    {
        $holds = [['source' => 'package', 'package' => 'acme/capped', 'version' => '2.0.0', 'link' => 'require', 'target' => 'acme/lib', 'constraint' => '^2.0']];

        $fix = self::fix(['packages' => [self::CAPPED], 'packages-dev' => []], [], self::recorded(['result' => 'refused', 'holds' => $holds]));

        self::assertSame([['package', 'require', 'acme/lib']], self::holds($fix));
    }

    public function testARefusalThatNamesNoHoldHasAnEmptyHeldBy(): void
    {
        $fix = self::fix(['packages' => [self::CAPPED], 'packages-dev' => []], [], self::recorded(['result' => 'refused']));

        self::assertSame(['refused', []], [$fix['check'], $fix['held_by']]);
    }

    public function testAFixThatTheSolveCannotCheckTakesItsReason(): void
    {
        $fix = self::fix(['packages' => [self::CAPPED], 'packages-dev' => []], [], self::recorded(['result' => 'not_checked', 'reason' => 'offline']));

        self::assertSame(['not_checked', 'offline', null, []], [$fix['check'], $fix['reason'], $fix['command'], $fix['held_by']]);
    }

    /** K4: a lock-only run's move check gives no_composer_json. */
    public function testALockOnlyRunLeavesTheFixNotCheckedWithNoComposerJson(): void
    {
        $fix = self::fix(['packages' => [self::CAPPED], 'packages-dev' => [], 'platform' => ['php' => '>=7.4']], null, new UncheckedMoves(MoveCheck::NO_COMPOSER_JSON));

        self::assertSame(['not_checked', 'no_composer_json'], [$fix['check'], $fix['reason']]);
        self::assertSame(['acme/capped'], array_column($fix['packages'], 'name'));
    }

    /** Ruling T1: a name that Composer's pattern refuses reaches no solve and no command. */
    public function testAListedNameThatIsNoComposerPackageNameIsNotCheckedAndNotSolved(): void
    {
        $lock = ['packages' => [['name' => '--with-all-dependencies', 'version' => '1.0.0', 'require' => ['php' => '<8.4']]], 'packages-dev' => []];

        $fix = self::fix($lock, [], self::recorded(['result' => 'resolves', 'form' => 'inline']));

        self::assertSame(['--with-all-dependencies'], array_column($fix['packages'], 'name'));
        self::assertSame(['not_checked', 'unloaded_name', null], [$fix['check'], $fix['reason'], $fix['command']]);
    }

    public function testANameThatIsNoComposerPackageNameInAnyCaseReachesNoSolve(): void
    {
        $lock = ['packages' => [['name' => '--With-All-Dependencies', 'version' => '1.0.0', 'require' => ['php' => '<8.4']]], 'packages-dev' => []];

        $fix = self::fix($lock, [], self::recorded(['result' => 'resolves', 'form' => 'inline']));

        self::assertSame(['not_checked', 'unloaded_name'], [$fix['check'], $fix['reason']]);
    }

    /** Ruling T5: the baseline's reason wins over `unloaded_name`, which applies only when a solve can run. */
    public function testABaselineThatWasNotCheckedGivesItsReasonOverAnInvalidListedName(): void
    {
        $lock = ['packages' => [['name' => '--with-all-dependencies', 'version' => '1.0.0', 'require' => ['php' => '<8.4']]], 'packages-dev' => []];

        $fix = self::fix($lock, [], new UncheckedMoves(MoveCheck::OFFLINE));

        self::assertSame(['not_checked', 'offline'], [$fix['check'], $fix['reason']]);
    }

    /** G1: Composer names a package in lower case, so the solve takes the lowered name and the command the lock's. */
    public function testAMixedCaseNameThatNoComposerRepositoryServesIsNotChecked(): void
    {
        $lock = ['packages' => [['name' => 'Acme/Legacy', 'version' => '1.0.0', 'require' => ['php' => '<8.4']]], 'packages-dev' => []];

        $fix = self::fix($lock, [], self::solving($lock, ['acme/legacy'], new ArrayVersionSource([])));

        self::assertSame(['Acme/Legacy'], array_column($fix['packages'], 'name'));
        self::assertSame(['not_checked', 'not_from_composer_repository', []], [$fix['check'], $fix['reason'], $fix['held_by']]);
    }

    public function testAMixedCaseNameThatTheSourceFailedToLoadIsNotChecked(): void
    {
        $lock = ['packages' => [['name' => 'Acme/Legacy', 'version' => '1.0.0', 'require' => ['php' => '<8.4']]], 'packages-dev' => []];

        $fix = self::fix($lock, [], self::solving($lock, [], new ArrayVersionSource([], ['acme/legacy' => VersionSource::OFFLINE])));

        self::assertSame(['not_checked', 'offline'], [$fix['check'], $fix['reason']]);
    }

    public function testAMixedCaseNameKeepsTheLocksSpellingInTheCommand(): void
    {
        $lock = ['packages' => [['name' => 'Acme/Legacy', 'version' => '1.0.0', 'require' => ['php' => '<8.4']]], 'packages-dev' => []];

        $fix = self::fix($lock, [], self::solving($lock, [], new ArrayVersionSource(['acme/legacy' => [['version' => '1.0.0', 'require' => ['php' => '<8.4']], ['version' => '1.1.0']]])));

        self::assertSame(['resolves', ['composer', 'update', 'Acme/Legacy']], [$fix['check'], $fix['command']]);
    }

    /** Ruling R2-47 on `solveAt()`: the printed `composer update` meets the same advisory filter as a move. */
    public function testAFixWhoseResolveHoldsANameWithUnreadAdvisoriesIsNotChecked(): void
    {
        $lock = ['packages' => [['name' => 'acme/legacy', 'version' => '1.0.0', 'require' => ['php' => '<8.4']]], 'packages-dev' => []];
        $unread = new PoolAdvisories(AdvisoryBlocking::everyAdvisory(), static fn (array $names, bool $complete): ?array => null);

        $fix = self::fix($lock, [], self::solving($lock, [], new ArrayVersionSource(['acme/legacy' => [['version' => '1.0.0', 'require' => ['php' => '<8.4']], ['version' => '1.1.0']]]), $unread));

        self::assertSame(['not_checked', 'advisory_lookup_incomplete', null], [$fix['check'], $fix['reason'], $fix['command']]);
    }

    public function testASkippedNameThatExcludesNothingLeavesTheSolve(): void
    {
        $lock = ['packages' => [self::CAPPED, ['name' => '--with-dependencies', 'version' => '1.0.0']], 'packages-dev' => []];

        $fix = self::fix($lock, [], self::recorded(['result' => 'resolves', 'form' => 'inline']));

        self::assertSame(['resolves', ['composer', 'update', 'acme/capped']], [$fix['check'], $fix['command']]);
    }

    /** K1: a package conflict on php lists the package with its conflict link. */
    public function testAPackageConflictThatMatchesTheTargetIsListed(): void
    {
        $lock = ['packages' => [['name' => 'acme/conflicting', 'version' => '1.0.0', 'require' => ['php' => '>=7.4'], 'conflict' => ['php' => '>=8.4']]], 'packages-dev' => []];

        $fix = self::fix($lock, [], self::recorded(['result' => 'resolves', 'form' => 'with-dependencies']));

        self::assertSame([['acme/conflicting', 'conflict', '>=8.4']], array_map(static fn (array $req): array => [$req['name'], $req['link'], $req['constraint']], $fix['packages']));
        self::assertSame(['composer', 'update', 'acme/conflicting', '--with-dependencies'], $fix['command']);
    }

    /** A cap inside the target minor fails at config.platform.php 8.4.7, a point of the target minor: no platform step. */
    public function testACapInsideTheTargetMinorExcludesTheConfiguredPatch(): void
    {
        $lock = ['packages' => [['name' => 'acme/patch-cap', 'version' => '1.0.0', 'require' => ['php' => '>=7.4 <8.4.5']]], 'packages-dev' => []];

        $data = self::data($lock, ['config' => ['platform' => ['php' => '8.4.7']]], self::recorded(['result' => 'resolves', 'form' => 'inline']));

        self::assertSame(['php' => '8.4.7', 'inside' => false], $data['target']);
        self::assertNotNull($data['target_fix']);
        self::assertSame(['acme/patch-cap'], array_column($data['target_fix']['packages'], 'name'));
        self::assertFalse($data['target_fix']['platform_step']);
    }

    public function testAPhp64bitRequirementOfAPackageIsListed(): void
    {
        $lock = ['packages' => [['name' => 'acme/sixty-four', 'version' => '1.0.0', 'require' => ['php-64bit' => '>=7.4 <8.4']]], 'packages-dev' => []];

        $fix = self::fix($lock, [], null);

        self::assertSame([['acme/sixty-four', 'php-64bit']], array_map(static fn (array $req): array => [$req['name'], $req['target']], $fix['packages']));
    }

    /** The laminas skeleton at 8.4: the root's own php link excludes the target, so nothing solves. */
    public function testARootRequirementThatFailsIsTheRootCeilingWithNoSolve(): void
    {
        $lock = ['packages' => [['name' => 'acme/installer', 'version' => '1.0.0', 'require' => ['php' => '<8.4']]], 'packages-dev' => []];

        $fix = self::fix($lock, ['require' => ['php' => '~8.1.0 || ~8.2.0 || ~8.3.0']], self::recorded(['result' => 'resolves', 'form' => 'inline']));

        self::assertSame(['refused', null, null], [$fix['check'], $fix['command'], $fix['reason']]);
        self::assertSame([['root', 'require', 'php', '~8.1.0 || ~8.2.0 || ~8.3.0']], self::rootHolds($fix));
        self::assertSame(['acme/installer'], array_column($fix['packages'], 'name'));
    }

    /** Ruling Q3: Composer rewrites the lock's platform from composer.json on every update, so a link that only the lock records is no ceiling. */
    public function testARootRequirementThatOnlyTheLockRecordsIsListedAndAnUpdateOfTheLockRewritesIt(): void
    {
        $lock = ['packages' => [['name' => 'acme/lib', 'version' => '1.0.0', 'require' => ['php' => '>=7.4']]], 'packages-dev' => [], 'platform' => ['php' => '>=7.4 <8.1'], 'platform-dev' => ['php' => '<8.2']];

        $fix = self::fix($lock, ['require' => ['acme/lib' => '^1.0']], self::resolving());

        self::assertSame([
            ['name' => null, 'version' => null, 'link' => 'require', 'target' => 'php', 'constraint' => '<8.2', 'dev' => true],
        ], $fix['lock_platform']);
        self::assertSame([[], 'resolves', ['composer', 'update', '--lock'], []], [$fix['packages'], $fix['check'], $fix['command'], $fix['held_by']]);
        self::assertSame('lock_platform', array_keys($fix)[1], 'lock_platform follows packages');
    }

    public function testAnUpdateThatNamesNoPackageSolvesWithComposer(): void
    {
        $lock = ['packages' => [['name' => 'acme/lib', 'version' => '1.0.0', 'require' => ['php' => '>=7.4']]], 'packages-dev' => [], 'platform' => ['php' => '<8.1']];

        $fix = self::fix($lock, ['require' => ['acme/lib' => '^1.0']], self::solving($lock, [], new ArrayVersionSource(['acme/lib' => [['version' => '1.0.0', 'require' => ['php' => '>=7.4']]]])));

        self::assertSame(['resolves', ['composer', 'update', '--lock']], [$fix['check'], $fix['command']]);
    }

    public function testALockPlatformLinkBesideAListedPackageLeavesTheCommandToThePackage(): void
    {
        $lock = ['packages' => [self::CAPPED], 'packages-dev' => [], 'platform' => ['php' => '<8.1']];

        $fix = self::fix($lock, ['require' => ['acme/capped' => '^1.0']], self::resolving());

        self::assertSame([['require', 'php', '<8.1']], array_map(static fn (array $req): array => [$req['link'], $req['target'], $req['constraint']], $fix['lock_platform']));
        self::assertSame(['acme/capped'], array_column($fix['packages'], 'name'));
        self::assertSame(['composer', 'update', 'acme/capped'], $fix['command']);
    }

    /** On a lock-only run, every root link comes from the lock's platform, and it is the root ceiling. */
    public function testALockOnlyRunReadsTheLocksPlatformAsTheRootCeiling(): void
    {
        $fix = self::fix(['packages' => [], 'packages-dev' => [], 'platform' => ['php' => '<8.1']], null, new UncheckedMoves(MoveCheck::NO_COMPOSER_JSON));

        self::assertSame([[], 'refused'], [$fix['lock_platform'], $fix['check']]);
        self::assertSame([['root', 'require', 'php', '<8.1']], self::rootHolds($fix));
    }

    public function testALinkThatComposerJsonAlsoHasIsTheRootCeiling(): void
    {
        $fix = self::fix(['packages' => [], 'packages-dev' => [], 'platform' => ['php' => '<8.0']], ['require' => ['php' => '<8.1']], self::resolving());

        self::assertSame([[], 'refused'], [$fix['lock_platform'], $fix['check']]);
        self::assertSame([['root', 'require', 'php', '<8.1']], self::rootHolds($fix));
    }

    /** F4: each root ceiling hold names its section, `require-dev` and `conflict` included. */
    public function testTheRootCeilingNamesARequireDevCapAndARootConflict(): void
    {
        $composer = ['require-dev' => ['php' => '<8.4'], 'conflict' => ['php' => '>=8.4.50']];

        $fix = self::fix(['packages' => [], 'packages-dev' => []], $composer, null);

        self::assertSame([['root', 'require-dev', 'php', '<8.4'], ['root', 'conflict', 'php', '>=8.4.50']], self::rootHolds($fix));
        self::assertSame(['refused', []], [$fix['check'], $fix['packages']]);
    }

    public function testARootPhp64bitRequirementIsTheRootCeiling(): void
    {
        $fix = self::fix(['packages' => [], 'packages-dev' => []], ['require' => ['php-64bit' => '<8.4']], null);

        self::assertSame([['root', 'require', 'php-64bit', '<8.4']], self::rootHolds($fix));
    }

    /**
     * Page case 23: config.platform.php 7.4.29 lies outside 8.4, so the solve runs at 8.4.99 and the
     * project needs its own platform step first.
     */
    public function testAConfiguredPlatformPhpOutsideTheTargetMinorIsAPlatformStepOnAResolvingAndARefusedFix(): void
    {
        $composer = ['config' => ['platform' => ['php' => '7.4.29']]];
        $lock = ['packages' => [self::CAPPED], 'packages-dev' => []];

        $resolving = self::data($lock, $composer, self::recorded(['result' => 'resolves', 'form' => 'inline']));
        $refused = self::fix($lock, $composer, self::recorded(['result' => 'refused']));

        self::assertSame('8.4.99', $resolving['target']['php']);
        self::assertNotNull($resolving['target_fix']);
        self::assertSame([true, 'resolves'], [$resolving['target_fix']['platform_step'], $resolving['target_fix']['check']]);
        self::assertSame([true, 'refused'], [$refused['platform_step'], $refused['check']]);
    }

    /** M5: the solve's config.platform.php is Composer's merged config, which the move check reads. */
    public function testThePlatformStepReadsTheMoveChecksConfiguredPhpBeforeComposerJson(): void
    {
        $checker = new RecordedMoveChecker(['target_fix' => ['result' => 'resolves', 'form' => 'inline']], ['config_php' => '7.4.29']);

        $fix = self::fix(['packages' => [self::CAPPED], 'packages-dev' => []], [], $checker);

        self::assertTrue($fix['platform_step']);
    }

    public function testAFixLeavesTheWindowItWasBuiltFromUnchanged(): void
    {
        $window = PhpWindow::of(LockFile::fromArray(['packages' => [self::CAPPED], 'packages-dev' => []]), ProjectConfig::fromArray([]), '8.4', PhpReleaseDates::load()->minors(Clock::fixed('2026-10-01T00:00:00+00:00')));
        self::assertNotNull($window);

        $window->withTargetFix(null);

        self::assertNull($window->toArray()['target_fix']);
    }

    /** A solved refusal whose root holds are no root php link is no root ceiling. */
    public function testOnlyRootLinksOnPhpMakeTheRootCeiling(): void
    {
        self::assertTrue(TargetFix::isRootCeiling(self::refused(new Holder(Holder::ROOT, null, null, Holder::CONFLICT, '>=8.4', 'php-64bit'))));
        self::assertFalse(TargetFix::isRootCeiling(self::refused(new Holder(Holder::ROOT, 'acme/app', null, Holder::PLATFORM, '^7.4', 'php'))), 'composer.json requires php, from a solve');
        self::assertFalse(TargetFix::isRootCeiling(self::refused(new Holder(Holder::ROOT, 'acme/app', null, Holder::REQUIRE, '^2.0', 'acme/lib'))), 'a root requirement on a package');
        self::assertFalse(TargetFix::isRootCeiling(self::refused(new Holder(Holder::PACKAGE, 'acme/lib', '1.0.0', Holder::REQUIRE, '<8.4', 'php'))));
        self::assertFalse(TargetFix::isRootCeiling(self::refused()), 'a refusal that names no hold');
    }

    public function testTheLocksPlatformOverrideIsNoPlatformStep(): void
    {
        $fix = self::fix(['packages' => [self::CAPPED], 'packages-dev' => [], 'platform-overrides' => ['php' => '7.4.29']], [], null);

        self::assertFalse($fix['platform_step']);
    }

    /** @return Fix */
    private static function refused(Holder ...$holds): array
    {
        return ['packages' => [], 'lock_platform' => [], 'command' => null, 'check' => 'refused', 'reason' => null, 'held_by' => array_values($holds), 'platform_step' => false];
    }

    /** @param array<string, mixed> $check */
    private static function recorded(array $check): RecordedMoveChecker
    {
        return new RecordedMoveChecker(['target_fix' => $check], []);
    }

    /**
     * Composer's own solve at PHP 8.4.99, over a root that requires each locked package at `*`.
     *
     * @param array{packages: list<array{name: string, version: string}>} $lock
     * @param list<string>                                               $notFromComposerRepository
     */
    private static function solving(array $lock, array $notFromComposerRepository, VersionSource $source, ?PoolAdvisories $advisories = null): SolverChecker
    {
        $root = (new ArrayLoader())->load(['name' => 'acme/app', 'version' => '1.0.0', 'require' => array_fill_keys(array_column($lock['packages'], 'name'), '*')], RootPackage::class);
        self::assertInstanceOf(RootPackageInterface::class, $root);
        $solver = new ComposerSolver($root, LockFile::fromArray($lock)->lockedRepository(), new PlatformRepository([], ['php' => '8.4.99']), $source);

        return new SolverChecker($solver, $notFromComposerRepository, '8.4.99', $advisories !== null, Deadline::never(), $advisories);
    }

    private static function resolving(): RecordedMoveChecker
    {
        return self::recorded(['result' => 'resolves', 'form' => 'inline']);
    }

    /**
     * @param array<string, mixed>  $lock
     * @param ?array<string, mixed> $composer null for a lock-only run
     *
     * @return Data
     */
    private static function data(array $lock, ?array $composer, ?MoveChecker $checker, string $target = '8.4'): array
    {
        $project = $composer === null ? ProjectConfig::empty() : ProjectConfig::fromArray($composer);
        $window = PhpWindow::of(LockFile::fromArray($lock), $project, $target, PhpReleaseDates::load()->minors(Clock::fixed('2026-10-01T00:00:00+00:00')));
        self::assertNotNull($window);

        return $window->withTargetFix($checker)->toArray();
    }

    /**
     * @param array<string, mixed>  $lock
     * @param ?array<string, mixed> $composer
     *
     * @return Fix
     */
    private static function fix(array $lock, ?array $composer, ?MoveChecker $checker): array
    {
        $fix = self::data($lock, $composer, $checker)['target_fix'];
        self::assertNotNull($fix);

        return $fix;
    }

    /**
     * @param Fix $fix
     *
     * @return list<array{string, string, ?string}>
     */
    private static function holds(array $fix): array
    {
        return array_map(static fn (Holder $hold): array => [$hold->source(), $hold->link(), $hold->target()], $fix['held_by']);
    }

    /**
     * @param Fix $fix
     *
     * @return list<array{string, string, ?string, string}>
     */
    private static function rootHolds(array $fix): array
    {
        return array_map(static fn (Holder $hold): array => [$hold->source(), $hold->link(), $hold->target(), $hold->constraint()], $fix['held_by']);
    }
}
