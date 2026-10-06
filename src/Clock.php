<?php

declare(strict_types=1);

namespace Lockrot;

/** @internal */
final class Clock
{
    /** The year every "years ago" and every libyear is counted in: 365.25 days. */
    public const SECONDS_PER_YEAR = 31557600;
    private \DateTimeImmutable $now;

    public function __construct(?\DateTimeImmutable $now = null)
    {
        $this->now = $now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public static function fixed(string $iso8601): self
    {
        return new self(new \DateTimeImmutable($iso8601));
    }

    /**
     * LOCKROT_TODAY pins every "years ago" calculation to a fixed instant. It is a testing hook
     * (README "Testing hooks"), not part of the configuration contract, which is why it is read
     * here rather than through LockrotConfig.
     *
     * @param array<string, mixed> $env
     */
    public static function fromEnvironment(array $env): self
    {
        $today = $env['LOCKROT_TODAY'] ?? null;

        return \is_string($today) && $today !== '' ? self::fixed($today) : new self();
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    /**
     * The exact ratio of the seconds since $date to a year, negative for a date after the run
     * clock: what every threshold compares ({@see \Lockrot\Signal\Thresholds::levelFor()}), never
     * the published tenths, so a reading of 4.95 years prints 5.0 and stays below a 5-year threshold.
     */
    public function yearsSince(\DateTimeInterface $date): float
    {
        return ($this->now->getTimestamp() - $date->getTimestamp()) / self::SECONDS_PER_YEAR;
    }

    /**
     * The years since $date in integer tenths, rounded half up: the one form every published years
     * value takes (`tenths / 10`). A date after the run clock reads 0.
     */
    public function tenthsSince(\DateTimeInterface $date): int
    {
        return self::tenthsOf($this->now->getTimestamp() - $date->getTimestamp());
    }

    /**
     * $seconds in tenths of a year, half up, clamped to 0 below. `10 * $seconds` passes a 32-bit
     * int at 6.8 years, so with 4-byte integers the same rounding is taken in a float, where it is
     * exact: `10 * $seconds` stays far below 2^53, and a quotient that is not a half lies at least
     * 1 / (2 * SECONDS_PER_YEAR) away from one, far beyond the float's error.
     *
     * @param int $intSize the integer width to compute for, {@see \PHP_INT_SIZE} unless a test asks
     */
    public static function tenthsOf(int $seconds, int $intSize = \PHP_INT_SIZE): int
    {
        $seconds = max(0, $seconds);
        if ($intSize < 8) {
            return (int) floor($seconds * 10.0 / self::SECONDS_PER_YEAR + 0.5);
        }

        return intdiv(10 * $seconds + intdiv(self::SECONDS_PER_YEAR, 2), self::SECONDS_PER_YEAR);
    }
}
