<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Solver;

use Composer\Semver\Constraint\Constraint;
use Lockrot\Security\Holder;
use Lockrot\Solver\CheckOutcome;
use Lockrot\Solver\MoveCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CheckOutcomeTest extends TestCase
{
    /** @return iterable<string, array{Holder}> a root hold on the moved package whose constraint matches 1.1.0 and that is no requirement */
    public static function otherRootHolds(): iterable
    {
        yield 'a conflict' => [new Holder(Holder::ROOT, null, null, Holder::CONFLICT, '>=1.1')];
        yield 'the root-require of no release' => [new Holder(Holder::ROOT, null, null, Holder::ROOT_REQUIRE, '^1.1')];
        yield 'a requirement on another package' => [new Holder(Holder::ROOT, null, null, Holder::REQUIRE, '^1.0', 'acme/other')];
    }

    /**
     * @dataProvider otherRootHolds
     */
    #[DataProvider('otherRootHolds')]
    public function testARootHoldThatIsNoRequirementOnTheMovedPackageStays(Holder $root): void
    {
        $package = new Holder(Holder::PACKAGE, 'acme/lib', '1.1.0', Holder::REQUIRE, '^1.0', 'acme/gone');

        $outcome = (new CheckOutcome(MoveCheck::refused(), [$root, $package]))->withoutAdmitting('1.1.0');

        self::assertSame([$root, $package], $outcome->holds());
    }

    /** mautic at 8.2: mautic/core-lib's own requirement holds the move, so the root requirement on core-lib is not a second hold. */
    public function testARootHoldOnAPackageThatAPackageHoldNamesDrops(): void
    {
        $coreLib = new Holder(Holder::ROOT, null, null, Holder::ROOT_REQUIRE, '^7.0', 'mautic/core-lib');
        $other = new Holder(Holder::ROOT, null, null, Holder::ROOT_REQUIRE, '^3.0', 'acme/other');
        $package = new Holder(Holder::PACKAGE, 'mautic/core-lib', '7.0.1', Holder::REQUIRE, '~2.7.0', null);

        $outcome = (new CheckOutcome(MoveCheck::refused(), [$coreLib, $other, $package]))->withoutRootOnHolders();

        self::assertSame([$other, $package], $outcome->holds());
    }

    /** wallabag at 7.4: monolog-bundle's own requirement admits v5.4.52, so only the root pin on http-kernel holds the move (ruling R2-2). */
    public function testAPackageRequirementOnTheMovedPackageThatAdmitsItsReleaseIsNoHold(): void
    {
        $bundle = new Holder(Holder::PACKAGE, 'symfony/monolog-bundle', 'v3.8.0', Holder::REQUIRE, '~4.4 || ^5.0 || ^6.0');
        $kernel = new Holder(Holder::ROOT, null, null, Holder::REQUIRE, '^4.4.51', 'symfony/http-kernel');

        $outcome = (new CheckOutcome(MoveCheck::refused(), [$bundle, $kernel]))->withoutAdmitting('v5.4.52');

        self::assertSame([$kernel], $outcome->holds());
    }

    public function testAPackageRequirementOnTheMovedPackageThatExcludesItsReleaseStays(): void
    {
        $bundle = new Holder(Holder::PACKAGE, 'symfony/monolog-bundle', 'v3.8.0', Holder::REQUIRE_DEV, '~4.4');
        $kernel = new Holder(Holder::ROOT, null, null, Holder::REQUIRE, '^4.4.51', 'symfony/http-kernel');

        $outcome = (new CheckOutcome(MoveCheck::refused(), [$bundle, $kernel]))->withoutAdmitting('v5.4.52');

        self::assertSame([$bundle, $kernel], $outcome->holds());
    }

    /** @return iterable<string, array{Holder::REQUIRE|Holder::REQUIRE_DEV|Holder::ROOT_REQUIRE}> */
    public static function rootRequirementLinks(): iterable
    {
        yield 'require' => [Holder::REQUIRE];
        yield 'require-dev' => [Holder::REQUIRE_DEV];
        yield 'root-require' => [Holder::ROOT_REQUIRE];
    }

    /**
     * @dataProvider rootRequirementLinks
     *
     * @param Holder::REQUIRE|Holder::REQUIRE_DEV|Holder::ROOT_REQUIRE $link
     */
    #[DataProvider('rootRequirementLinks')]
    public function testEachRootRequirementOnAPackageThatAPackageHoldNamesDrops(string $link): void
    {
        $root = new Holder(Holder::ROOT, null, null, $link, '^7.0', 'mautic/core-lib');
        $package = new Holder(Holder::PACKAGE, 'mautic/core-lib', '7.0.1', Holder::REQUIRE, '~2.7.0', null);

        $outcome = (new CheckOutcome(MoveCheck::refused(), [$root, $package]))->withoutRootOnHolders();

        self::assertSame([$package], $outcome->holds());
    }

    /** @return iterable<string, array{Holder::CONFLICT|Holder::REPLACE}> */
    public static function rootDeclarationLinks(): iterable
    {
        yield 'a conflict' => [Holder::CONFLICT];
        yield 'a replace' => [Holder::REPLACE];
    }

    /**
     * A conflict or a replace that composer.json declares is a hold of its own, not the head of the package hold's chain.
     *
     * @dataProvider rootDeclarationLinks
     *
     * @param Holder::CONFLICT|Holder::REPLACE $link
     */
    #[DataProvider('rootDeclarationLinks')]
    public function testARootConflictOrReplaceOnAPackageThatAPackageHoldNamesStays(string $link): void
    {
        $root = new Holder(Holder::ROOT, null, null, $link, '>=7.1', 'mautic/core-lib');
        $package = new Holder(Holder::PACKAGE, 'mautic/core-lib', '7.0.1', Holder::REQUIRE, '~2.7.0', null);

        $outcome = (new CheckOutcome(MoveCheck::refused(), [$root, $package]))->withoutRootOnHolders();

        self::assertSame([$root, $package], $outcome->holds());
    }

    public function testAJointSolveThatInstallsAReleaseOutsideTheConstraintKeepsTheFirstRefusal(): void
    {
        $first = new Holder(Holder::PACKAGE, 'acme/holder', '1.0.0', Holder::REQUIRE, '^1.0');
        $outcome = new CheckOutcome(MoveCheck::refused(), [$first]);
        $joint = (new CheckOutcome(MoveCheck::resolves(MoveCheck::INLINE, '1.9.0')))->withChanges(['acme/lib' => '1.9.0', 'acme/holder' => '2.0.0']);

        $joined = $outcome->joinedWith($joint, 'acme/lib', '^2.0', ['acme/holder']);

        self::assertSame([true, [$first], []], [$joined->check()->isRefused(), $joined->holds(), $joined->movesWith()]);
    }

    /** The root hold names the root's `name` as its package: it is no holder of a joint request. */
    public function testARootHoldIsNoHolderAlthoughItNamesTheRootPackage(): void
    {
        $root = new Holder(Holder::ROOT, 'acme/app', null, Holder::REQUIRE, '^1.0');
        $package = new Holder(Holder::PACKAGE, 'acme/holder', '1.0.0', Holder::REQUIRE, '^1.0');

        $outcome = new CheckOutcome(MoveCheck::refused(), [$root, $package]);

        self::assertSame(['acme/holder'], $outcome->holders('acme/lib'));
    }

    public function testTheCopiesWithChangesAndWithoutRootOnHoldersLeaveTheOutcomeAsItWas(): void
    {
        $root = new Holder(Holder::ROOT, null, null, Holder::ROOT_REQUIRE, '^1.0', 'acme/holder');
        $package = new Holder(Holder::PACKAGE, 'acme/holder', '1.0.0', Holder::REQUIRE, '^1.0');
        $outcome = new CheckOutcome(MoveCheck::refused(), [$root, $package]);

        $changed = $outcome->withChanges(['acme/lib' => '1.9.0']);
        $withoutRoot = $outcome->withoutRootOnHolders();

        self::assertSame(
            [[], ['acme/lib' => '1.9.0'], [$root, $package], [$package]],
            [$outcome->changes(), $changed->changes(), $outcome->holds(), $withoutRoot->holds()],
        );
    }

    /** The joint solve moves h/dev from one dev-main commit to another: the step must update it, although its version reads the same. */
    public function testAHolderThatTheJointSolveMovesIsListedAlthoughItsVersionReadsTheSame(): void
    {
        $hold = new Holder(Holder::PACKAGE, 'h/dev', 'dev-main', Holder::REQUIRE, '^1.0');
        $outcome = new CheckOutcome(MoveCheck::refused(), [$hold]);
        $joint = (new CheckOutcome(MoveCheck::resolves(MoveCheck::INLINE, '2.0.0')))->withChanges(['acme/lib' => '2.0.0', 'h/dev' => 'dev-main'], ['acme/lib' => '1.0.0', 'h/dev' => 'dev-main']);

        $joined = $outcome->joinedWith($joint, 'acme/lib', '^2.0', ['h/dev']);

        self::assertSame([['package' => 'h/dev', 'installed_version' => 'dev-main', 'version' => 'dev-main']], $joined->movesWith());
    }

    /** SPEC §5.6, case `mc-joint-retag‡`: the tag 1.0.0 of h/x points at a new commit, and a new install of the same tag changes no holder. */
    public function testAJointSolveThatInstallsAHolderAgainAtItsLockedTagKeepsTheFirstRefusal(): void
    {
        $hold = new Holder(Holder::PACKAGE, 'h/x', '1.0.0', Holder::REQUIRE, '^1.0');
        $outcome = new CheckOutcome(MoveCheck::refused(), [$hold]);
        $locked = (new CheckOutcome(MoveCheck::resolves(MoveCheck::INLINE, '2.0.0')))->withChanges(['acme/lib' => '2.0.0', 'h/x' => '1.0.0'], ['acme/lib' => '1.0.0', 'h/x' => '1.0.0']);
        $recorded = (new CheckOutcome(MoveCheck::resolves(MoveCheck::INLINE, '2.0.0')))->withChanges(['acme/lib' => '2.0.0', 'h/x' => '1.0.0']);

        $fromLock = $outcome->joinedWith($locked, 'acme/lib', '^2.0', ['h/x']);
        $fromHold = $outcome->joinedWith($recorded, 'acme/lib', '^2.0', ['h/x']);

        self::assertSame([MoveCheck::REFUSED, [$hold], []], [$fromLock->check()->result(), $fromLock->holds(), $fromLock->movesWith()]);
        self::assertSame([MoveCheck::REFUSED, [$hold], []], [$fromHold->check()->result(), $fromHold->holds(), $fromHold->movesWith()]);
    }

    /** A `-W` refusal names a release of the holder that the lock does not hold: the installed version comes from the lock. */
    public function testTheInstalledVersionOfAMovedHolderIsItsLockedRelease(): void
    {
        $hold = new Holder(Holder::PACKAGE, 'h/x', '1.2.0', Holder::REQUIRE, '^1.0');
        $outcome = new CheckOutcome(MoveCheck::refused(), [$hold]);
        $joint = (new CheckOutcome(MoveCheck::resolves(MoveCheck::INLINE, '2.0.0')))->withChanges(['acme/lib' => '2.0.0', 'h/x' => '2.1.0'], ['acme/lib' => '1.0.0', 'h/x' => '1.0.0']);

        $joined = $outcome->joinedWith($joint, 'acme/lib', '^2.0', ['h/x']);

        self::assertSame([['package' => 'h/x', 'installed_version' => '1.0.0', 'version' => '2.1.0']], $joined->movesWith());
    }

    /** SPEC §5.6: Composer refuses the move alone, so a joint answer other than resolves or refused keeps that refusal. */
    public function testAJointRequestThatIsNotCheckedKeepsTheFirstRefusal(): void
    {
        $hold = new Holder(Holder::PACKAGE, 'h/x', '1.0.0', Holder::REQUIRE, '^1.0');
        $outcome = new CheckOutcome(MoveCheck::refused(), [$hold]);

        $incomplete = $outcome->joinedWith(new CheckOutcome(MoveCheck::notChecked(MoveCheck::ADVISORY_LOOKUP_INCOMPLETE)), 'acme/lib', '^2.0', ['h/x']);
        $budget = $outcome->joinedWith(new CheckOutcome(MoveCheck::notChecked(MoveCheck::INSTALL_TIME_BUDGET)), 'acme/lib', '^2.0', ['h/x']);

        self::assertSame([MoveCheck::REFUSED, [$hold]], [$incomplete->check()->result(), $incomplete->holds()]);
        self::assertSame([MoveCheck::REFUSED, [$hold]], [$budget->check()->result(), $budget->holds()]);
    }

    /** A recorded joint answer names no locked releases: the hold's version stands in. */
    public function testWithoutTheLockedReleaseTheHoldGivesTheInstalledVersion(): void
    {
        $hold = new Holder(Holder::PACKAGE, 'h/x', '1.0.0', Holder::REQUIRE, '^1.0');
        $outcome = new CheckOutcome(MoveCheck::refused(), [$hold]);
        $joint = (new CheckOutcome(MoveCheck::resolves(MoveCheck::INLINE, '2.0.0')))->withChanges(['acme/lib' => '2.0.0', 'h/x' => '2.1.0']);

        $joined = $outcome->joinedWith($joint, 'acme/lib', '^2.0', ['h/x']);

        self::assertSame([['package' => 'h/x', 'installed_version' => '1.0.0', 'version' => '2.1.0']], $joined->movesWith());
    }

    public function testTheInstalledVersionComesFromTheHoldOfThatHolderNotFromAnEarlierHold(): void
    {
        $other = new Holder(Holder::PACKAGE, 'h/a', '1.0.0', Holder::REQUIRE, '^1.0');
        $hold = new Holder(Holder::PACKAGE, 'h/x', '1.2.0', Holder::REQUIRE, '^1.0');
        $outcome = new CheckOutcome(MoveCheck::refused(), [$other, $hold]);
        $joint = (new CheckOutcome(MoveCheck::resolves(MoveCheck::INLINE, '2.0.0')))->withChanges(['acme/lib' => '2.0.0', 'h/x' => '2.1.0']);

        $joined = $outcome->joinedWith($joint, 'acme/lib', '^2.0', ['h/a', 'h/x']);

        self::assertSame([['package' => 'h/x', 'installed_version' => '1.2.0', 'version' => '2.1.0']], $joined->movesWith());
    }

    public function testTheCopyLeavesTheOutcomeItComesFromAsItWas(): void
    {
        $root = new Holder(Holder::ROOT, null, null, Holder::REQUIRE, '^1.0');
        $package = new Holder(Holder::PACKAGE, 'acme/lib', '1.1.0', Holder::REQUIRE, '^1.0', 'acme/gone');
        $outcome = new CheckOutcome(MoveCheck::refused(), [$root, $package]);

        $copy = $outcome->withoutAdmitting('1.1.0');

        self::assertSame([[$root, $package], [$package]], [$outcome->holds(), $copy->holds()]);
    }

    public function testTheCopyWithARangeLeavesTheOutcomeItComesFromWithoutOne(): void
    {
        $outcome = new CheckOutcome(MoveCheck::refused(), []);
        $range = new Constraint('>=', '1.2.5.0');

        $copy = $outcome->withRange($range);

        self::assertSame([null, $range], [$outcome->range(), $copy->range()]);
    }
}
