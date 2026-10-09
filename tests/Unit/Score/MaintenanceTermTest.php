<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Score;

use Lockrot\Score\MaintenanceTerm;
use Lockrot\Tests\Support\ScoreSweep;
use PHPUnit\Framework\TestCase;

/** The worked row "left-behind + stale, transitive, dev: rounded down" of score model 1. */
final class MaintenanceTermTest extends TestCase
{
    public function testTheLeadAndTheCorroboratingTermWriteTheirKeysInOrder(): void
    {
        $terms = ScoreSweep::basis(['axis' => 'base', 'flags' => ['left-behind', 'stale'], 'advisories' => [], 'reach' => 'transitive', 'dev' => true, 'under' => null, 'accepted' => null])->terms();

        self::assertCount(2, $terms);
        self::assertInstanceOf(MaintenanceTerm::class, $terms[0]);
        self::assertSame(['part' => 'maintenance', 'flag' => 'left-behind', 'role' => 'lead', 'weight' => 16, 'divisor' => 1, 'points' => 16, 'contribution' => 4], $terms[0]->toArray());
        self::assertSame(['part' => 'maintenance', 'flag' => 'stale', 'role' => 'corroborating', 'weight' => 8, 'divisor' => 4, 'points' => 2, 'contribution' => 0.5], $terms[1]->toArray());
    }

    public function testTheGettersGiveTheEngineNumbers(): void
    {
        $term = new MaintenanceTerm('stale', 'corroborating', 8, 4, 2, 1);

        self::assertSame('stale', $term->flag());
        self::assertSame('corroborating', $term->role());
        self::assertFalse($term->isLead());
        self::assertSame(8, $term->weight());
        self::assertSame(4, $term->divisor());
        self::assertSame(2, $term->points());
        self::assertTrue((new MaintenanceTerm('pinned', 'lead', 16, 1, 16, 32))->isLead());
    }
}
