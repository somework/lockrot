<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit;

use Lockrot\Clock;
use PHPUnit\Framework\TestCase;

final class ClockTest extends TestCase
{
    public function testFixedClockReturnsGivenInstant(): void
    {
        $clock = Clock::fixed('2026-09-14T00:00:00+00:00');
        self::assertSame('2026-09-14', $clock->now()->format('Y-m-d'));
    }

    public function testYearsSinceUsesJulianYear(): void
    {
        $clock = Clock::fixed('2026-09-14T00:00:00+00:00');
        $years = $clock->yearsSince(new \DateTimeImmutable('2015-11-16T16:30:51+00:00'));
        self::assertEqualsWithDelta(10.83, $years, 0.01);
    }

    public function testDefaultClockIsNow(): void
    {
        $before = time();
        $now = (new Clock())->now()->getTimestamp();
        self::assertGreaterThanOrEqual($before, $now);
    }
}
