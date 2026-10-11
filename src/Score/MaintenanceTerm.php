<?php

declare(strict_types=1);

namespace Lockrot\Score;

use Lockrot\Verdict\Score;

/**
 * A counted maintenance flag: the lead in full, a corroborating flag at its share.
 *
 * @internal
 *
 * @phpstan-type Shape array{part: 'maintenance', flag: string, role: string, weight: int, divisor: int, points: int, contribution: int|float}
 */
final class MaintenanceTerm implements Term
{
    private string $flag;
    private string $role;
    private int $weight;
    private int $divisor;
    private int $points;
    private int $contributionHalves;

    /** @param int $contributionHalves the points after every halving, in half points */
    public function __construct(string $flag, string $role, int $weight, int $divisor, int $points, int $contributionHalves)
    {
        $this->flag = $flag;
        $this->role = $role;
        $this->weight = $weight;
        $this->divisor = $divisor;
        $this->points = $points;
        $this->contributionHalves = $contributionHalves;
    }

    public function flag(): string
    {
        return $this->flag;
    }

    public function role(): string
    {
        return $this->role;
    }

    public function isLead(): bool
    {
        return $this->role === Score::LEAD;
    }

    public function weight(): int
    {
        return $this->weight;
    }

    public function divisor(): int
    {
        return $this->divisor;
    }

    public function points(): int
    {
        return $this->points;
    }

    /** @return Shape */
    public function jsonSerialize(): array
    {
        return [
            'part' => 'maintenance',
            'flag' => $this->flag,
            'role' => $this->role,
            'weight' => $this->weight,
            'divisor' => $this->divisor,
            'points' => $this->points,
            'contribution' => HalfPoints::json($this->contributionHalves),
        ];
    }
}
