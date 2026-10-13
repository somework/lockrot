<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Verdict\Moves;
use PHPUnit\Framework\TestCase;

final class MovesTest extends TestCase
{
    private static function moves(string $id): Moves
    {
        $case = CaseHydrator::case($id);
        $finding = CaseHydrator::analysisOf($case)->finding(JsonPath::stringAt($case, ['finding', 'package']));
        self::assertNotNull($finding);

        return $finding->details()->moves();
    }

    public function testTheSecurityMoveIsTheNextStepWhenItClearsTheAdvisories(): void
    {
        $moves = self::moves('B');

        self::assertSame(Moves::IN_NEXT_STEP, $moves->moveIn());
        self::assertSame($moves->nextStep(), $moves->securityMove());
    }

    public function testTheSecurityMoveIsTheFirstOtherMoveWhenOnlyItClearsThem(): void
    {
        $moves = self::moves('either-critical‡');
        $next = $moves->nextStep();
        self::assertNotNull($next);

        self::assertSame(Moves::IN_ALSO, $moves->moveIn());
        self::assertSame($next->also()[0], $moves->securityMove());
    }

    public function testWithoutAMoveThatClearsTheAdvisoriesThereIsNoSecurityMove(): void
    {
        $moves = self::moves('C');

        self::assertNotNull($moves->nextStep());
        self::assertNull($moves->moveIn());
        self::assertNull($moves->securityMove());
        self::assertNull(Moves::none()->securityMove());
    }
}
