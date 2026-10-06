<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit;

use Lockrot\Clock;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testYearsSinceKeepsTheExactRatioAndItsSign(): void
    {
        $clock = Clock::fixed('2026-10-01T00:00:00+00:00');

        self::assertSame(-1.0, $clock->yearsSince($clock->now()->modify('+'.Clock::SECONDS_PER_YEAR.' seconds')));
        self::assertSame(1577879 / Clock::SECONDS_PER_YEAR, $clock->yearsSince($clock->now()->modify('-1577879 seconds')));
    }

    /** @return iterable<string, array{int, int}> seconds before the run clock => tenths of a year */
    public static function tenthsRows(): iterable
    {
        $half = intdiv(Clock::SECONDS_PER_YEAR, 20);
        yield 'now' => [0, 0];
        yield 'one second' => [1, 0];
        yield 'one second below 0.05 years' => [$half - 1, 0];
        yield '0.05 years exactly rounds half up' => [$half, 1];
        yield '0.15 years exactly rounds half up' => [3 * $half, 2];
        yield 'one second below 0.15 years' => [3 * $half - 1, 1];
        yield 'one year' => [Clock::SECONDS_PER_YEAR, 10];
        yield '4.95 years exactly' => [99 * $half, 50];
        yield 'one second below 4.95 years' => [99 * $half - 1, 49];
        yield 'seven years' => [7 * Clock::SECONDS_PER_YEAR, 70];
        yield 'past the 32-bit edge of 10 * seconds (6.8 years)' => [214748365, 68];
        yield 'a future date clamps to 0' => [-1, 0];
        yield 'a date far in the future clamps to 0' => [-7 * Clock::SECONDS_PER_YEAR, 0];
    }

    /** @dataProvider tenthsRows */
    #[DataProvider('tenthsRows')]
    public function testTenthsSinceRoundsHalfUpOnTheRunClock(int $secondsAgo, int $tenths): void
    {
        $clock = Clock::fixed('2026-10-01T00:00:00+00:00');

        self::assertSame($tenths, $clock->tenthsSince($clock->now()->modify(\sprintf('%+d seconds', -$secondsAgo))));
    }

    /** @dataProvider tenthsRows */
    #[DataProvider('tenthsRows')]
    public function testTheThirtyTwoBitPathGivesTheSameTenths(int $secondsAgo, int $tenths): void
    {
        self::assertSame($tenths, Clock::tenthsOf($secondsAgo, 4));
        self::assertSame($tenths, Clock::tenthsOf($secondsAgo, 8));
    }

    public function testTheThirtyTwoBitPathAgreesOverASweepOfSeconds(): void
    {
        $half = intdiv(Clock::SECONDS_PER_YEAR, 20);
        for ($k = 0; $k <= 400; ++$k) {
            foreach ([$k * $half - 1, $k * $half, $k * $half + 1] as $seconds) {
                self::assertSame(Clock::tenthsOf($seconds, 8), Clock::tenthsOf($seconds, 4), (string) $seconds);
            }
        }
    }

    public function testTheThirtyTwoBitPathNeverMultipliesInAnInteger(): void
    {
        // Past PHP_INT_MAX / 10 seconds the integer path cannot take `10 * $seconds`. The float
        // path can, and gives the exact half-up tenths.
        $seconds = intdiv(\PHP_INT_MAX, 10) + 1;
        $exact = intdiv($seconds, Clock::SECONDS_PER_YEAR) * 10 + Clock::tenthsOf($seconds % Clock::SECONDS_PER_YEAR);

        self::assertSame($exact, Clock::tenthsOf($seconds, 4));
    }

    public function testSixtyFourBitIntegersStayOffTheFloatPath(): void
    {
        // A second below a half-tenth, where 10 * seconds passes 2^53: the integer path is exact,
        // and a float rounds it up. No age comes near this. It only proves which path runs.
        self::assertSame(292271023044, Clock::tenthsOf(922337203682911319, 8));
    }

    public function testTenthsOfTheRunningIntegerSizeIsTheDefault(): void
    {
        self::assertSame(Clock::tenthsOf(1577880, \PHP_INT_SIZE), Clock::tenthsOf(1577880));
        self::assertSame(1, Clock::tenthsOf(1577880));
    }

    public function testDefaultClockIsNow(): void
    {
        $before = time();
        $now = (new Clock())->now()->getTimestamp();
        self::assertGreaterThanOrEqual($before, $now);
    }

    public function testFromEnvironmentPinsTheClockToLockrotToday(): void
    {
        self::assertSame(
            '2026-09-14',
            Clock::fromEnvironment(['LOCKROT_TODAY' => '2026-09-14T00:00:00+00:00'])->now()->format('Y-m-d')
        );
    }

    public function testFromEnvironmentFallsBackToNowWhenLockrotTodayIsUnusable(): void
    {
        $before = time();
        self::assertGreaterThanOrEqual($before, Clock::fromEnvironment([])->now()->getTimestamp());
        self::assertGreaterThanOrEqual($before, Clock::fromEnvironment(['LOCKROT_TODAY' => ''])->now()->getTimestamp());
    }
}
