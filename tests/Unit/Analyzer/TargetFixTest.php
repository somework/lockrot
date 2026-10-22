<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Analyzer;

use Lockrot\Analyzer\PhpWindow;
use Lockrot\Analyzer\TargetFix;
use Lockrot\Clock;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Security\Holder;
use Lockrot\Solver\MoveCheck;
use Lockrot\Solver\MoveChecker;
use Lockrot\Solver\UncheckedMoves;
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
        return ['packages' => [], 'command' => null, 'check' => 'refused', 'reason' => null, 'held_by' => array_values($holds), 'platform_step' => false];
    }

    /** @param array<string, mixed> $check */
    private static function recorded(array $check): RecordedMoveChecker
    {
        return new RecordedMoveChecker(['target_fix' => $check], []);
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
