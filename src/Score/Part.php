<?php

declare(strict_types=1);

namespace Lockrot\Score;

use Lockrot\Verdict\ScoreModel;

/**
 * What one part, maintenance or security, adds to the score, and the grade it would give alone.
 *
 * @internal
 *
 * @phpstan-type Shape array{status: string, contribution: int|float, alone: array{total: int, verdict: ?string}}
 */
final class Part
{
    public const COUNTED = 'counted';

    private string $status;
    private int $contributionHalves;

    public function __construct(string $status, int $contributionHalves)
    {
        $this->status = $status;
        $this->contributionHalves = $contributionHalves;
    }

    public function isCounted(): bool
    {
        return $this->status === self::COUNTED;
    }

    /** @return Shape */
    public function toArray(): array
    {
        $alone = intdiv($this->contributionHalves, 2);

        return ['status' => $this->status, 'contribution' => HalfPoints::json($this->contributionHalves), 'alone' => ['total' => $alone, 'verdict' => ScoreModel::band($alone)]];
    }
}
