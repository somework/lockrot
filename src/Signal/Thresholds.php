<?php

declare(strict_types=1);

namespace Lockrot\Signal;

use Lockrot\Exception\ConfigException;

final class Thresholds
{
    private int $releaseWarnYears;
    private int $releaseHighYears;
    private int $pushWarnYears;
    private int $pushHighYears;

    public function __construct(int $releaseWarnYears = 3, int $releaseHighYears = 5, int $pushWarnYears = 3, int $pushHighYears = 5)
    {
        foreach ([$releaseWarnYears, $releaseHighYears, $pushWarnYears, $pushHighYears] as $value) {
            if ($value < 1) {
                throw new ConfigException('Threshold years must be positive integers');
            }
        }
        $this->releaseWarnYears = $releaseWarnYears;
        $this->releaseHighYears = $releaseHighYears;
        $this->pushWarnYears = $pushWarnYears;
        $this->pushHighYears = $pushHighYears;
    }

    /**
     * @param array<string, mixed> $extra composer.json extra.lockrot; each key here must be a JSON
     *                                     integer, re-checked rather than trusted from the config
     *                                     schema for callers that bypass it (a digit string like
     *                                     "4" is rejected)
     */
    public static function fromArray(array $extra): self
    {
        $read = static function (string $key, int $default) use ($extra): int {
            $value = $extra[$key] ?? $default;
            if (!\is_int($value)) {
                throw new ConfigException(\sprintf('extra.lockrot.%s must be an integer', $key));
            }

            return $value;
        };

        return new self($read('release-warn-years', 3), $read('release-high-years', 5), $read('push-warn-years', 3), $read('push-high-years', 5));
    }

    public function releaseWarnYears(): int
    {
        return $this->releaseWarnYears;
    }

    public function releaseHighYears(): int
    {
        return $this->releaseHighYears;
    }

    public function pushWarnYears(): int
    {
        return $this->pushWarnYears;
    }

    public function pushHighYears(): int
    {
        return $this->pushHighYears;
    }

    public static function levelFor(float $years, int $warn, int $high): ?string
    {
        if ($years >= $high) {
            return Signal::LEVEL_HIGH;
        }
        if ($years >= $warn) {
            return Signal::LEVEL_WARN;
        }

        return null;
    }
}
