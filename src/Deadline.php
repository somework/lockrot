<?php

declare(strict_types=1);

namespace Lockrot;

/**
 * A budget for how long a piece of work may keep going.
 *
 * Built on a monotonic clock rather than {@see Clock}/wall-clock time: a budget must not be
 * shortened or extended by a system-clock adjustment (NTP sync, DST) happening mid-run. `$now` is
 * injectable so a caller can drive it from a counter of its own.
 */
final class Deadline
{
    /** @var float|null seconds on the monotonic clock at which this deadline expires; null = never */
    private ?float $expiresAt;
    /** @var callable(): float */
    private $now;

    /** @param callable(): float $now */
    private function __construct(?float $expiresAt, callable $now)
    {
        $this->expiresAt = $expiresAt;
        $this->now = $now;
    }

    public static function never(): self
    {
        return new self(null, self::monotonic());
    }

    /** @param null|callable(): float $now monotonic seconds; defaults to hrtime() */
    public static function inSeconds(float $seconds, ?callable $now = null): self
    {
        $now ??= self::monotonic();

        return new self($now() + $seconds, $now);
    }

    public function isNever(): bool
    {
        return $this->expiresAt === null;
    }

    public function isPast(): bool
    {
        return $this->expiresAt !== null && ($this->now)() >= $this->expiresAt;
    }

    /** @return float seconds left before expiry; INF when this deadline never expires */
    public function remainingSeconds(): float
    {
        if ($this->expiresAt === null) {
            return \INF;
        }

        return max(0.0, $this->expiresAt - ($this->now)());
    }

    /** @return callable(): float */
    private static function monotonic(): callable
    {
        return static fn (): float => hrtime(true) / 1e9;
    }
}
