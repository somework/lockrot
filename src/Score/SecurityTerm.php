<?php

declare(strict_types=1);

namespace Lockrot\Score;

use Lockrot\Verdict\FlagSet;

/**
 * The deciding advisory. Its weight is the severity's points for its fix kind, and the multiplier
 * doubles it when no fix is reachable.
 *
 * @internal
 *
 * @phpstan-type Shape array{part: 'security', flag: string, role: string, advisory: string, severity: string, fix_kind: string, weight: int, multiplier: int, points: int, contribution: int|float}
 */
final class SecurityTerm implements Term
{
    public const ROLE = 'security';

    private string $advisory;
    private string $severity;
    private string $fixKind;
    private int $weight;
    private int $multiplier;
    private int $points;
    private int $contributionHalves;

    /**
     * @param string $severity           the severity bucket, not the Composer word
     * @param int    $contributionHalves the points after the dev halving, in half points
     */
    public function __construct(string $advisory, string $severity, string $fixKind, int $weight, int $multiplier, int $points, int $contributionHalves)
    {
        $this->advisory = $advisory;
        $this->severity = $severity;
        $this->fixKind = $fixKind;
        $this->weight = $weight;
        $this->multiplier = $multiplier;
        $this->points = $points;
        $this->contributionHalves = $contributionHalves;
    }

    public function flag(): string
    {
        return FlagSet::VULNERABLE;
    }

    public function role(): string
    {
        return self::ROLE;
    }

    public function severity(): string
    {
        return $this->severity;
    }

    public function weight(): int
    {
        return $this->weight;
    }

    public function multiplier(): int
    {
        return $this->multiplier;
    }

    public function points(): int
    {
        return $this->points;
    }

    /** @return Shape */
    public function toArray(): array
    {
        return [
            'part' => 'security',
            'flag' => $this->flag(),
            'role' => $this->role(),
            'advisory' => $this->advisory,
            'severity' => $this->severity,
            'fix_kind' => $this->fixKind,
            'weight' => $this->weight,
            'multiplier' => $this->multiplier,
            'points' => $this->points,
            'contribution' => HalfPoints::json($this->contributionHalves),
        ];
    }
}
