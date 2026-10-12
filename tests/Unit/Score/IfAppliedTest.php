<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Score;

use Lockrot\Score\IfApplied;
use PHPUnit\Framework\TestCase;

final class IfAppliedTest extends TestCase
{
    /** A branch row's `if_applied` drops the lead and the deciding advisory, and the move's keeps them. */
    public function testARowCopyLeavesTheScoreItCameFrom(): void
    {
        $applied = new IfApplied(4, 'low', null, 'PKSA-1', false, ['clears_hold']);

        $row = $applied->asRow();

        self::assertSame(['total' => 4, 'verdict' => 'low', 'lead' => null, 'deciding_advisory' => 'PKSA-1', 'at_most' => false, 'assumes' => ['clears_hold']], $applied->jsonSerialize());
        self::assertSame(['total' => 4, 'verdict' => 'low', 'at_most' => false, 'assumes' => ['clears_hold']], $row->jsonSerialize());
    }
}
