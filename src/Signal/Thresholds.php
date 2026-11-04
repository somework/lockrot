<?php

declare(strict_types=1);

namespace Lockrot\Signal;

use Lockrot\Exception\ConfigException;

/** @internal */
final class Thresholds
{
    private int $releaseWarnYears;
    private int $releaseHighYears;
    private int $pushWarnYears;
    private int $pushHighYears;

    /** @param list<string> $unsetKeys the `extra.lockrot` keys that give their default, as a refusal names them */
    public function __construct(int $releaseWarnYears = 3, int $releaseHighYears = 5, int $pushWarnYears = 3, int $pushHighYears = 5, array $unsetKeys = [])
    {
        foreach ([$releaseWarnYears, $releaseHighYears, $pushWarnYears, $pushHighYears] as $value) {
            if ($value < 1) {
                throw new ConfigException('Threshold years must be positive integers');
            }
        }
        self::refuseHighBelowWarn('release', $releaseWarnYears, $releaseHighYears, $unsetKeys);
        self::refuseHighBelowWarn('push', $pushWarnYears, $pushHighYears, $unsetKeys);
        $this->releaseWarnYears = $releaseWarnYears;
        $this->releaseHighYears = $releaseHighYears;
        $this->pushWarnYears = $pushWarnYears;
        $this->pushHighYears = $pushHighYears;
    }

    /**
     * @param array<string, mixed> $extra `extra.lockrot` of composer.json. Each key here must be a
     *                                     JSON integer, and the string "4" is rejected: a caller
     *                                     can bypass the config schema.
     */
    public static function fromArray(array $extra): self
    {
        $unsetKeys = [];
        $read = static function (string $key, int $default) use ($extra, &$unsetKeys): int {
            if (!\array_key_exists($key, $extra)) {
                $unsetKeys[] = $key;

                return $default;
            }
            $value = $extra[$key];
            if (!\is_int($value)) {
                throw new ConfigException(\sprintf('extra.lockrot.%s must be an integer', $key));
            }

            return $value;
        };

        return new self($read('release-warn-years', 3), $read('release-high-years', 5), $read('push-warn-years', 3), $read('push-high-years', 5), $unsetKeys);
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

    /** @return array{release-warn-years: int, release-high-years: int, push-warn-years: int, push-high-years: int} by `extra.lockrot` key, as `run.thresholds` writes them */
    public function toArray(): array
    {
        return [
            'release-warn-years' => $this->releaseWarnYears,
            'release-high-years' => $this->releaseHighYears,
            'push-warn-years' => $this->pushWarnYears,
            'push-high-years' => $this->pushHighYears,
        ];
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

    /** A high level below its warn level makes an age `high` before it is `warn`, and the exclusive groups of the score model stop holding. */
    /** @param list<string> $unsetKeys */
    private static function refuseHighBelowWarn(string $what, int $warn, int $high, array $unsetKeys): void
    {
        if ($high >= $warn) {
            return;
        }
        $shown = static fn (string $key, int $value): string => \in_array($key, $unsetKeys, true) ? $value.', the default of the unset key' : (string) $value;

        throw new ConfigException(\sprintf('extra.lockrot.%1$s-high-years (%3$s) must not be below extra.lockrot.%1$s-warn-years (%2$s)', $what, $shown($what.'-warn-years', $warn), $shown($what.'-high-years', $high)));
    }
}
