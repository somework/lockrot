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
     * Deadline::inSeconds() calls $now() exactly once itself, to compute expiresAt (here
     * 100.0 + 5.0 = 105.0); every later isPast()/remainingSeconds() call makes one further call.
     * To observe isPast() and remainingSeconds() agreeing at the same simulated instant, this
     * fake supplies each of the three instants (100.0, 104.9, 105.0) twice in a row: once for
     * isPast(), once for remainingSeconds(), called in that order. Call sequence consumed:
     *   1. construction                       -> 100.0 (expiresAt = 105.0)
     *   2. checkpoint "still 5.0s left": isPast()          -> 100.0 -> false
     *   3.                                remainingSeconds() -> 100.0 -> 5.0
     *   4. checkpoint "0.1s left":       isPast()          -> 104.9 -> false
     *   5.                                remainingSeconds() -> 104.9 -> 0.1
     *   6. checkpoint "exactly at expiry": isPast()        -> 105.0 -> true
     *   7.                                remainingSeconds() -> 105.0 -> 0.0
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
