<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Solver;

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

    public function testTheCopyLeavesTheOutcomeItComesFromAsItWas(): void
    {
        $root = new Holder(Holder::ROOT, null, null, Holder::REQUIRE, '^1.0');
        $package = new Holder(Holder::PACKAGE, 'acme/lib', '1.1.0', Holder::REQUIRE, '^1.0', 'acme/gone');
        $outcome = new CheckOutcome(MoveCheck::refused(), [$root, $package]);

        $copy = $outcome->withoutAdmitting('1.1.0');

        self::assertSame([[$root, $package], [$package]], [$outcome->holds(), $copy->holds()]);
    }
}
