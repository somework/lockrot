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

    public function testTwoMovesShareAKeyOnlyWhenTheyAskTheSameComposerRequest(): void
    {
        $key = MoveQuery::update('acme/lib', '^2.0', '2.0.0')->key();

        self::assertSame($key, MoveQuery::update('acme/lib', '^2.0', '2.0.5')->key());
        self::assertNotSame($key, MoveQuery::update('acme/lib', '^2.0', '2.0.0', '>=8.1')->key());
        self::assertNotSame($key, MoveQuery::require('acme/lib', '^2.0', '2.0.0')->key());
    }
}
