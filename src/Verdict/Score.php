<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

use Lockrot\Security\Severity;

/**
 * The score of one finding under {@see ScoreModel}, in integer half points. The lead maintenance flag
 * counts in full, and each other counted one adds a quarter. The highest-scoring counted advisory
 * adds its points. Reach halves maintenance, and packages-dev halves the whole. The total is floored
 * once. Every quarter of a weight is whole, so every value stays on the half-point grid.
 *
 * @internal
 *
 * @phpstan-type Advisory array{id: string, severity: string, fix_kind: string}
 * @phpstan-type MaintenanceTerm array{flag: string, role: string, weight: int, divisor: int, points: int}
 */
final class Score
{
    public const DIRECT = 'direct';
    public const TRANSITIVE = 'transitive';
    /** Not direct, with no chain from composer.json. */
    public const UNREACHED = 'unreached';

    public const LEAD = 'lead';
    public const CORROBORATING = 'corroborating';

    /** @var list<MaintenanceTerm> */
    private array $terms;
    /** @var ?Advisory */
    private ?array $deciding;
    /** @var list<string> */
    private array $tied;
    private int $advisories;
    private string $reach;
    private bool $dev;

    /**
     * @param list<MaintenanceTerm> $terms
     * @param ?Advisory             $deciding
     * @param list<string>          $tied
     */
    private function __construct(array $terms, ?array $deciding, array $tied, int $advisories, string $reach, bool $dev)
    {
        $this->terms = $terms;
        $this->deciding = $deciding;
        $this->tied = $tied;
        $this->advisories = $advisories;
        $this->reach = $reach;
        $this->dev = $dev;
    }

    /** @return Advisory */
    public static function advisory(string $id, string $severity, string $fixKind): array
    {
        return ['id' => $id, 'severity' => $severity, 'fix_kind' => $fixKind];
    }

    public static function of(FlagSet $flags, string $reach, bool $dev): self
    {
        return self::compute($flags->countedMaintenance(), $flags->advisories(), $reach, $dev);
    }

    /**
     * @param list<string>   $maintenance the counted maintenance flags, in any order
     * @param list<Advisory> $advisories  the counted advisories. A severity is a Composer word: the
     *                                    engine reads its bucket ({@see Severity::fromComposer()})
     *
     * @throws \InvalidArgumentException for a reach that is not direct, transitive or unreached, or a
     *                                   fix kind outside {@see ScoreModel::FIX_KINDS}
     */
    public static function compute(array $maintenance, array $advisories, string $reach, bool $dev): self
    {
        if (!\in_array($reach, [self::DIRECT, self::TRANSITIVE, self::UNREACHED], true)) {
            throw new \InvalidArgumentException('not a reach: '.$reach);
        }
        foreach ($advisories as $advisory) {
            if (!\in_array($advisory['fix_kind'], ScoreModel::FIX_KINDS, true)) {
                throw new \InvalidArgumentException('not a fix kind: '.$advisory['fix_kind']);
            }
        }
        $advisories = array_map(static fn (array $advisory): array => array_merge($advisory, ['severity' => Severity::fromComposer($advisory['severity'])->bucket()]), $advisories);
        $terms = [];
        foreach (array_intersect(array_keys(ScoreModel::POINTS), $maintenance) as $flag) {
            $divisor = $terms === [] ? 1 : ScoreModel::CORROBORATING_DIVISOR;
            $weight = ScoreModel::POINTS[$flag];
            $terms[] = ['flag' => $flag, 'role' => $terms === [] ? self::LEAD : self::CORROBORATING, 'weight' => $weight, 'divisor' => $divisor, 'points' => intdiv($weight, $divisor)];
        }
        usort($advisories, static function (array $a, array $b): int {
            return ScoreModel::advisoryPoints($b['severity'], $b['fix_kind']) <=> ScoreModel::advisoryPoints($a['severity'], $a['fix_kind'])
                ?: array_search($a['severity'], ScoreModel::SEVERITIES, true) <=> array_search($b['severity'], ScoreModel::SEVERITIES, true)
                ?: strcmp($a['id'], $b['id']);
        });
        $deciding = $advisories[0] ?? null;
        $tied = [];
        if ($deciding !== null) {
            $points = ScoreModel::advisoryPoints($deciding['severity'], $deciding['fix_kind']);
            foreach (\array_slice($advisories, 1) as $advisory) {
                if (ScoreModel::advisoryPoints($advisory['severity'], $advisory['fix_kind']) === $points) {
                    $tied[] = $advisory['id'];
                }
            }
        }

        return new self($terms, $deciding, $tied, \count($advisories), $reach, $dev);
    }

    /** @return list<MaintenanceTerm> the counted maintenance flags in flag order, the lead first */
    public function maintenanceTerms(): array
    {
        return $this->terms;
    }

    /** @return ?Advisory the advisory that the security part takes: the most points, then the severity order, then the lowest id */
    public function deciding(): ?array
    {
        return $this->deciding;
    }

    /** @return list<string> the ids of the other counted advisories with the deciding one's points, in tie-break order */
    public function tied(): array
    {
        return $this->tied;
    }

    public function advisoryCount(): int
    {
        return $this->advisories;
    }

    public function lead(): ?string
    {
        return $this->terms[0]['flag'] ?? null;
    }

    public function reach(): string
    {
        return $this->reach;
    }

    public function isDev(): bool
    {
        return $this->dev;
    }

    /** The maintenance points before any halving. */
    public function maintenancePointsHalves(): int
    {
        return 2 * array_sum(array_column($this->terms, 'points'));
    }

    /** The deciding advisory's points, × 2 with no reachable fix, before the dev halving. */
    public function securityPointsHalves(): int
    {
        return $this->deciding === null ? 0 : 2 * ScoreModel::advisoryPoints($this->deciding['severity'], $this->deciding['fix_kind']);
    }

    /** The maintenance points after the reach halving, before the dev halving. */
    public function reachedHalves(): int
    {
        return $this->halveForReach($this->maintenancePointsHalves());
    }

    /** What maintenance adds to `exact`, after every halving. */
    public function maintenanceHalves(): int
    {
        return $this->halveForDev($this->reachedHalves());
    }

    /** What security adds to `exact`: reach never halves it. */
    public function securityHalves(): int
    {
        return $this->halveForDev($this->securityPointsHalves());
    }

    public function exactHalves(): int
    {
        return $this->halveForDev($this->reachedHalves() + $this->securityPointsHalves());
    }

    public function total(): int
    {
        return intdiv($this->exactHalves(), 2);
    }

    /** Null at 0. */
    public function grade(): ?string
    {
        return ScoreModel::band($this->total());
    }

    public function halveForReach(int $halves): int
    {
        return $this->reach === self::DIRECT ? $halves : intdiv($halves, ScoreModel::REACH_DIVISOR);
    }

    public function halveForDev(int $halves): int
    {
        return $this->dev ? intdiv($halves, ScoreModel::DEV_DIVISOR) : $halves;
    }
}
