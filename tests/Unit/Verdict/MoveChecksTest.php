<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Lockrot\Solver\MoveCheck;
use Lockrot\Solver\MoveQuery;
use Lockrot\Tests\Support\RecordedMoveChecker;
use Lockrot\Verdict\MoveChecks;
use PHPUnit\Framework\TestCase;

final class MoveChecksTest extends TestCase
{
    public function testACopyThatAnswersOnlyWhatWasAskedKeepsTheAnswersAndAsksNothingNew(): void
    {
        $checks = new MoveChecks(new RecordedMoveChecker(['*' => ['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'holds' => []]], []), true);
        $asked = MoveQuery::update('a/b', '^2.0', '2.0.0');
        $checks->check($asked);

        $answeredOnly = $checks->answeredOnly();

        self::assertSame(MoveCheck::RESOLVES, $answeredOnly->check($asked)->check()->result());
        self::assertSame(['result' => MoveCheck::NOT_CHECKED, 'form' => null, 'reason' => MoveCheck::PASS_CAP], $answeredOnly->check(MoveQuery::update('c/d', '^3.0', '3.0.0'))->check()->jsonSerialize());
        self::assertSame(MoveCheck::RESOLVES, $checks->check(MoveQuery::update('c/d', '^3.0', '3.0.0'))->check()->result(), 'the original still asks');
    }
}
