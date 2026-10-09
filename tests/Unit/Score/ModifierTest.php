<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Score;

use Lockrot\Score\Modifier;
use Lockrot\Tests\Support\ScoreSweep;
use PHPUnit\Framework\TestCase;

final class ModifierTest extends TestCase
{
    /** The worked row "left-behind + stale, transitive, dev: rounded down": reach, then dev, with a half. */
    public function testReachThenDevWriteTheirKeysInOrder(): void
    {
        $modifiers = ScoreSweep::basis(['axis' => 'base', 'flags' => ['left-behind', 'stale'], 'advisories' => [], 'reach' => 'transitive', 'dev' => true, 'under' => null, 'accepted' => null])->modifiers();

        self::assertCount(2, $modifiers);
        self::assertSame(['reason' => 'transitive', 'applies_to' => 'maintenance', 'divide_by' => 2, 'before' => 18, 'after' => 9], $modifiers[0]->toArray());
        self::assertSame(['reason' => 'dev', 'applies_to' => 'total', 'divide_by' => 2, 'before' => 9, 'after' => 4.5], $modifiers[1]->toArray());
        self::assertTrue($modifiers[0]->isReach());
        self::assertFalse($modifiers[0]->isDev());
        self::assertTrue($modifiers[1]->isDev());
        self::assertFalse($modifiers[1]->isReach());
    }

    public function testAHalvingOfZeroChangesNothing(): void
    {
        self::assertFalse(Modifier::reach('unreached', 0, 0)->changes());
        self::assertTrue(Modifier::reach('transitive', 2, 1)->changes());
        self::assertSame(['reason' => 'dev', 'applies_to' => 'total', 'divide_by' => 2, 'before' => 0, 'after' => 0], Modifier::dev(0, 0)->toArray());
        self::assertSame('unreached', Modifier::reach('unreached', 0, 0)->reason());
        self::assertSame(2, Modifier::dev(4, 2)->divideBy());
    }
}
