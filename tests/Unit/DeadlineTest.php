<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit;

use Lockrot\Deadline;
use PHPUnit\Framework\TestCase;

final class DeadlineTest extends TestCase
{
    public function testNeverIsNeverPastAndRemainsInfinite(): void
    {
        $deadline = Deadline::never();

        self::assertTrue($deadline->isNever());
        self::assertFalse($deadline->isPast());
        self::assertSame(\INF, $deadline->remainingSeconds());
    }

    /**
     * Deadline::inSeconds() calls $now() once to compute expiresAt (here 100.0 + 5.0 = 105.0), and
     * every later isPast() or remainingSeconds() call makes one more call. So that both read the
     * same simulated instant, the fake supplies each of the three instants (100.0, 104.9, 105.0)
     * twice in a row, once for isPast() and once for remainingSeconds(), in that order.
     */
    public function testInSecondsBecomesPastAtTheExpectedInstant(): void
    {
        $ticks = [100.0, 100.0, 100.0, 104.9, 104.9, 105.0, 105.0];
        $index = -1;
        $fake = static function () use (&$index, $ticks): float {
            ++$index;

            return $ticks[$index];
        };

        $deadline = Deadline::inSeconds(5.0, $fake);

        self::assertFalse($deadline->isNever());
        self::assertFalse($deadline->isPast());
        self::assertEqualsWithDelta(5.0, $deadline->remainingSeconds(), 1e-9);
        self::assertFalse($deadline->isPast());
        self::assertEqualsWithDelta(0.1, $deadline->remainingSeconds(), 1e-9);
        self::assertTrue($deadline->isPast());
        self::assertSame(0.0, $deadline->remainingSeconds());
    }

    public function testRealSixtySecondDeadlineIsNotYetPast(): void
    {
        $deadline = Deadline::inSeconds(60.0);

        self::assertFalse($deadline->isPast());
        self::assertGreaterThan(0.0, $deadline->remainingSeconds());
    }
}
