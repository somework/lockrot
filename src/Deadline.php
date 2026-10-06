<?php

declare(strict_types=1);

namespace Lockrot;

/**
 * Reads a monotonic clock, not {@see Clock}, so a system-clock adjustment (NTP, DST) mid-run
 * cannot shorten or extend the budget.
 *
 * @internal
 */
final class Deadline
{
    /** @var float|null seconds on the monotonic clock when the deadline expires, null for never */
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

    /** @param null|callable(): float $now monotonic seconds, hrtime() when null */
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

    /** @return float seconds left before expiry, INF when the deadline never expires */
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
