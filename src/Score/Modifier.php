<?php

declare(strict_types=1);

namespace Lockrot\Score;

use Lockrot\Verdict\ScoreModel;

/**
 * One halving of a score: reach on maintenance, or dev on the total.
 *
 * @internal
 *
 * @phpstan-type Shape array{reason: string, applies_to: string, divide_by: int, before: int|float, after: int|float}
 */
final class Modifier implements \JsonSerializable
{
    private const MAINTENANCE = 'maintenance';
    private const TOTAL = 'total';
    private const DEV = 'dev';

    private string $reason;
    private string $appliesTo;
    private int $divideBy;
    private int $beforeHalves;
    private int $afterHalves;

    private function __construct(string $reason, string $appliesTo, int $divideBy, int $beforeHalves, int $afterHalves)
    {
        $this->reason = $reason;
        $this->appliesTo = $appliesTo;
        $this->divideBy = $divideBy;
        $this->beforeHalves = $beforeHalves;
        $this->afterHalves = $afterHalves;
    }

    /** @param string $reach `transitive` or `unreached`: the reason that the line prints */
    public static function reach(string $reach, int $beforeHalves, int $afterHalves): self
    {
        return new self($reach, self::MAINTENANCE, ScoreModel::REACH_DIVISOR, $beforeHalves, $afterHalves);
    }

    public static function dev(int $beforeHalves, int $afterHalves): self
    {
        return new self(self::DEV, self::TOTAL, ScoreModel::DEV_DIVISOR, $beforeHalves, $afterHalves);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function divideBy(): int
    {
        return $this->divideBy;
    }

    public function isReach(): bool
    {
        return $this->appliesTo === self::MAINTENANCE;
    }

    public function isDev(): bool
    {
        return $this->appliesTo === self::TOTAL;
    }

    /** False for a halving of 0. */
    public function changes(): bool
    {
        return $this->beforeHalves !== $this->afterHalves;
    }

    /** @return Shape */
    public function jsonSerialize(): array
    {
        return [
            'reason' => $this->reason,
            'applies_to' => $this->appliesTo,
            'divide_by' => $this->divideBy,
            'before' => HalfPoints::json($this->beforeHalves),
            'after' => HalfPoints::json($this->afterHalves),
        ];
    }
}
