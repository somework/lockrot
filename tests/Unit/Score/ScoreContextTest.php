<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Score;

use Lockrot\Score\ScoreContext;
use PHPUnit\Framework\TestCase;

/** What the score basis words from the checks that ran: each fact stays apart from the others. */
final class ScoreContextTest extends TestCase
{
    public function testEachFactReadsBackOnItsOwn(): void
    {
        $facts = [
            new ScoreContext(true, false, false, false, false),
            new ScoreContext(false, true, false, false, false),
            new ScoreContext(false, false, true, false, false),
            new ScoreContext(false, false, false, true, false),
            new ScoreContext(false, false, false, false, true),
        ];

        self::assertSame(
            [[true, false, false, false, false], [false, true, false, false, false], [false, false, true, false, false], [false, false, false, true, false], [false, false, false, false, true]],
            array_map(static fn (ScoreContext $c): array => [$c->maintenanceJudged(), $c->advisoriesComplete(), $c->livenessComplete(), $c->s3Unread(), $c->s8Unread()], $facts)
        );
    }
}
