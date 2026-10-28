<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Solver;

use Lockrot\Solver\MoveQuery;
use PHPUnit\Framework\TestCase;

final class MoveQueryTest extends TestCase
{
    public function testAnUpdateThatRaisesPhpWritesThePhpRequirementBesideTheInlineConstraint(): void
    {
        $request = MoveQuery::update('acme/lib', '^2.0', '2.0.0', '>=8.1')->request();

        self::assertSame([['acme/lib' => '^2.0'], ['php' => '>=8.1']], [$request->inline(), $request->rootChanges()]);
    }

    public function testARequireThatRaisesPhpWritesBothRootRequirements(): void
    {
        $request = MoveQuery::require('acme/lib', '^2.0', '2.0.0', '>=8.1')->request();

        self::assertSame([[], ['acme/lib' => '^2.0', 'php' => '>=8.1']], [$request->inline(), $request->rootChanges()]);
    }

    public function testAnUpdateWithoutPhpChangesNoRootRequirement(): void
    {
        self::assertSame([], MoveQuery::update('acme/lib', '^2.0', '2.0.0')->request()->rootChanges());
    }

    /** The recorded move checks of the tests are keyed by this text. */
    public function testTheKeyNamesTheCommandThePackageTheConstraintAndThePhpRequirement(): void
    {
        self::assertSame('update acme/lib ^2.0 ', MoveQuery::update('acme/lib', '^2.0', '2.0.0')->key());
        self::assertSame('require acme/lib ^2.0 >=8.1', MoveQuery::require('acme/lib', '^2.0', '2.0.0', '>=8.1')->key());
    }

    public function testTwoMovesShareAKeyOnlyWhenTheyAskTheSameComposerRequest(): void
    {
        $key = MoveQuery::update('acme/lib', '^2.0', '2.0.0')->key();

        self::assertSame($key, MoveQuery::update('acme/lib', '^2.0', '2.0.5')->key());
        self::assertNotSame($key, MoveQuery::update('acme/lib', '^2.0', '2.0.0', '>=8.1')->key());
        self::assertNotSame($key, MoveQuery::require('acme/lib', '^2.0', '2.0.0')->key());
    }

    public function testARequireDevCopyAsksRequireDevAndLeavesTheQueryAsItWas(): void
    {
        $query = MoveQuery::require('acme/lib', '^2.0', '2.0.0');

        $dev = $query->withRequireDev(true);

        self::assertSame([[], ['acme/lib' => 'acme/lib'], []], [$query->request()->devChanges(), $dev->request()->devChanges(), $query->withRequireDev(false)->request()->devChanges()]);
    }

    /** `composer update` has no `--dev`: an update stays an update of the lock. */
    public function testARequireDevCopyOfAnUpdateAsksNoRequireDev(): void
    {
        self::assertSame([], MoveQuery::update('acme/lib', '^2.0', '2.0.0')->withRequireDev(true)->request()->devChanges());
    }
}
