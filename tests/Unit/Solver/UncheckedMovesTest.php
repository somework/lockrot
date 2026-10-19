<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Solver;

use Lockrot\Solver\CheckOutcome;
use Lockrot\Solver\MoveCheck;
use Lockrot\Solver\MoveQuery;
use Lockrot\Solver\UncheckedMoves;
use PHPUnit\Framework\TestCase;

final class UncheckedMovesTest extends TestCase
{
    public function testEveryCheckIsNotCheckedForTheReasonAndNothingIsBlocked(): void
    {
        $checker = new UncheckedMoves(MoveCheck::NO_COMPOSER_JSON);

        $outcomes = [$checker->baseline(), $checker->check(MoveQuery::update('acme/lib', '^1.1', '1.1.0')), $checker->combined(['acme/lib' => '^1.1'])];

        self::assertSame(array_fill(0, 3, ['result' => 'not_checked', 'form' => null, 'reason' => 'no_composer_json']), array_map(static fn (CheckOutcome $outcome): array => $outcome->check()->jsonSerialize(), $outcomes));
        self::assertSame([null, null, null, false], [$checker->platformPhp(), $checker->composerVersion(), $checker->solverError(), $checker->blocksAdvisories()]);
        self::assertSame($checker, $checker->withAdvisories([], []));
    }
}
