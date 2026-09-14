<?php

declare(strict_types=1);

namespace Lockrot;

final class Clock
{
    private const SECONDS_PER_YEAR = 31557600; // 365.25 days
    private \DateTimeImmutable $now;

    public function __construct(?\DateTimeImmutable $now = null)
    {
        $this->now = $now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public static function fixed(string $iso8601): self
    {
        return new self(new \DateTimeImmutable($iso8601));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function yearsSince(\DateTimeInterface $date): float
    {
        return ($this->now->getTimestamp() - $date->getTimestamp()) / self::SECONDS_PER_YEAR;
    }
}
