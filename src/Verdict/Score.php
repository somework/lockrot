<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

use Lockrot\Score\CountedAdvisory;
use Lockrot\Score\MaintenanceTerm;
use Lockrot\Score\SecurityTerm;
use Lockrot\Security\Severity;

/**
 * The score of one finding under {@see ScoreModel}, in integer half points. The lead maintenance flag
 * counts in full, and each other counted one adds a quarter. The highest-scoring counted advisory
 * adds its points. Reach halves maintenance, and packages-dev halves the whole. The total is floored
 * once. Every quarter of a weight is whole, so every value stays on the half-point grid.
 *
 * @internal
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
    private array $terms = [];
    private ?SecurityTerm $deciding;
    /** @var list<string> */
    private array $tied;
    private int $advisories;
    private string $reach;
    private bool $dev;

    /**
     * @param list<string>     $maintenance the counted maintenance flags, in any order
     * @param ?CountedAdvisory $deciding    with the severity bucket, not Composer's word
     * @param list<string>     $tied
     */
    private function __construct(array $maintenance, ?CountedAdvisory $deciding, array $tied, int $advisories, string $reach, bool $dev)
    {
        $this->tied = $tied;
        $this->advisories = $advisories;
        $this->reach = $reach;
        $this->dev = $dev;
        foreach (array_intersect(array_keys(ScoreModel::POINTS), $maintenance) as $flag) {
            $divisor = $this->terms === [] ? 1 : ScoreModel::CORROBORATING_DIVISOR;
            $weight = ScoreModel::POINTS[$flag];
            $points = intdiv($weight, $divisor);
            $this->terms[] = new MaintenanceTerm($flag, $this->terms === [] ? self::LEAD : self::CORROBORATING, $weight, $divisor, $points, $this->halveForDev($this->halveForReach(2 * $points)));
        }
        $this->deciding = $deciding === null ? null : $this->securityTerm($deciding);
    }

    /** @param CountedAdvisory $advisory with the severity bucket, not Composer's word */
    private function securityTerm(CountedAdvisory $advisory): SecurityTerm
    {
        $multiplier = \in_array($advisory->fixKind(), ScoreModel::DOUBLING, true) ? ScoreModel::NO_REACHABLE_FIX_FACTOR : 1;
        $points = ScoreModel::advisoryPoints($advisory->severity(), $advisory->fixKind());

        return new SecurityTerm($advisory->id(), $advisory->severity(), $advisory->fixKind(), intdiv($points, $multiplier), $multiplier, $points, $this->halveForDev(2 * $points));
    }

    public static function of(FlagSet $flags, string $reach, bool $dev): self
    {
        return self::compute($flags->countedMaintenance(), $flags->advisories(), $reach, $dev);
    }

    /**
     * @param list<string>          $maintenance the counted maintenance flags, in any order
     * @param list<CountedAdvisory> $advisories  the counted advisories. A severity is a Composer word: the
     *                                           engine reads its bucket ({@see Severity::fromComposer()})
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
            if (!\in_array($advisory->fixKind(), ScoreModel::FIX_KINDS, true)) {
                throw new \InvalidArgumentException('not a fix kind: '.$advisory->fixKind());
            }
        }
        $advisories = array_map(static fn (CountedAdvisory $advisory): CountedAdvisory => $advisory->bucketed(), $advisories);
        usort($advisories, static function (CountedAdvisory $a, CountedAdvisory $b): int {
            return ScoreModel::advisoryPoints($b->severity(), $b->fixKind()) <=> ScoreModel::advisoryPoints($a->severity(), $a->fixKind())
                ?: array_search($a->severity(), ScoreModel::SEVERITIES, true) <=> array_search($b->severity(), ScoreModel::SEVERITIES, true)
                ?: strcmp($a->id(), $b->id());
        });
        $deciding = $advisories[0] ?? null;
        $tied = [];
        if ($deciding !== null) {
            $points = ScoreModel::advisoryPoints($deciding->severity(), $deciding->fixKind());
            foreach (\array_slice($advisories, 1) as $advisory) {
                if (ScoreModel::advisoryPoints($advisory->severity(), $advisory->fixKind()) === $points) {
                    $tied[] = $advisory->id();
                }
            }
        }

        return new self($maintenance, $deciding, $tied, \count($advisories), $reach, $dev);
    }

    /** @return list<MaintenanceTerm> the counted maintenance flags in flag order, the lead first */
    public function maintenanceTerms(): array
    {
        return $this->terms;
    }

    /** The advisory that the security part takes: the most points, then the severity order, then the lowest id. */
    public function deciding(): ?SecurityTerm
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
        return $this->terms === [] ? null : $this->terms[0]->flag();
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
        return 2 * array_sum(array_map(static fn (MaintenanceTerm $term): int => $term->points(), $this->terms));
    }

    /** The deciding advisory's points, × 2 with no reachable fix, before the dev halving. */
    public function securityPointsHalves(): int
    {
        return $this->deciding === null ? 0 : 2 * $this->deciding->points();
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

    private function halveForReach(int $halves): int
    {
        return $this->reach === self::DIRECT ? $halves : intdiv($halves, ScoreModel::REACH_DIVISOR);
    }

    private function halveForDev(int $halves): int
    {
        return $this->dev ? intdiv($halves, ScoreModel::DEV_DIVISOR) : $halves;
    }
}
