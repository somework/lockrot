<?php

declare(strict_types=1);

namespace Lockrot\Score;

use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\Score;
use Lockrot\Verdict\ScoreModel;

/**
 * The structured basis of a score, `finding.score`: every number that the engine used, after every
 * step. A consumer draws, words and compares it without a second engine. Every counterfactual
 * (`without[]`, `accepted[].if_counted`) is an engine rerun on the flags derived again, never a
 * subtraction. The engine counts in integer half points ({@see HalfPoints}).
 *
 * @internal
 *
 * @phpstan-type Context array{maintenance_judged: bool, advisories_complete: bool, liveness_complete: bool, s3_unread: bool, s8_unread: bool}
 *
 * @phpstan-import-type Shape from MaintenanceTerm as MaintenanceShape
 * @phpstan-import-type Shape from SecurityTerm as SecurityShape
 * @phpstan-import-type Shape from Modifier as ModifierShape
 * @phpstan-import-type Shape from Accepted as AcceptedShape
 * @phpstan-import-type Shape from Without as WithoutShape
 * @phpstan-import-type Shape from Part as PartShape
 * @phpstan-import-type Shape from SecurityPart as SecurityPartShape
 *
 * @phpstan-type Graded array{model: int, total: int, exact: int|float, rounded_down: bool, band: array{floor: int, next: ?string, to_next: ?int}, decided_by: string, parts: array{maintenance: PartShape, security: SecurityPartShape}, terms: list<MaintenanceShape|SecurityShape>, modifiers: list<ModifierShape>, accepted: list<AcceptedShape>, without: list<WithoutShape>, text: string}
 * @phpstan-type Zero array{model: int, total: int, exact: int, accepted: list<AcceptedShape>, text: string}
 */
final class ScoreBasis
{
    private FlagSet $flags;
    private Score $score;
    /** @var Context */
    private array $context;
    /** @var list<MaintenanceTerm> */
    private array $maintenanceTerms = [];
    private ?SecurityTerm $securityTerm = null;
    /** @var list<Modifier> */
    private array $modifiers = [];
    /** @var list<Accepted> */
    private array $accepted;
    /** @var list<Without> */
    private array $without = [];
    private Part $maintenancePart;
    private SecurityPart $securityPart;

    /** @param Context $context */
    private function __construct(FlagSet $flags, Score $score, array $context)
    {
        $this->flags = $flags;
        $this->score = $score;
        $this->context = $context;
        $this->accepted = $this->acceptedRows();
        $this->maintenancePart = new Part($this->maintenanceStatus(), $score->maintenanceHalves());
        $this->securityPart = new SecurityPart(new Part($this->securityStatus(), $score->securityHalves()), $score->advisoryCount(), $score->tied());
        if ($score->grade() !== null) {
            $this->maintenanceTerms = $this->maintenanceTermsOf();
            $this->securityTerm = $this->securityTermOf();
            $this->modifiers = self::modifiersOf($score);
            $this->without = $this->withoutRows();
        }
    }

    /**
     * @param Context $context `maintenance_judged`: lockrot read the release metadata.
     *                         `advisories_complete`: every advisory feed answered.
     *                         `liveness_complete`: lockrot read S2 and S4.
     *                         `s3_unread`: no S3 answer.
     *                         `s8_unread`: S8 cannot date a branch that a tag can land on.
     */
    public static function of(FlagSet $flags, string $reach, bool $dev, array $context): self
    {
        return new self($flags, Score::of($flags, $reach, $dev), $context);
    }

    /**
     * The band of the total, else the score-0 word: `finished` when the entry accepts the whole
     * package or a fired flag, `unknown` when lockrot read no release metadata, else `ok`.
     */
    public static function verdict(Score $score, FlagSet $flags, bool $maintenanceJudged): string
    {
        if ($score->grade() !== null) {
            return $score->grade();
        }
        if ($flags->accepted() !== [] || $flags->acceptSet() === array_keys(ScoreModel::POINTS)) {
            return 'finished';
        }

        return $maintenanceJudged ? 'ok' : 'unknown';
    }

    /** False for a score of 0, which writes only `model`, `total`, `exact`, `accepted` and `text`. */
    public function isGraded(): bool
    {
        return $this->score->grade() !== null;
    }

    public function total(): int
    {
        return $this->score->total();
    }

    public function exactHalves(): int
    {
        return $this->score->exactHalves();
    }

    public function roundedDown(): bool
    {
        return $this->score->exactHalves() !== 2 * $this->score->total();
    }

    /** @return list<MaintenanceTerm> in flag order, the lead first. Empty for a score of 0. */
    public function maintenanceTerms(): array
    {
        return $this->maintenanceTerms;
    }

    public function securityTerm(): ?SecurityTerm
    {
        return $this->securityTerm;
    }

    /** @return list<Term> the maintenance terms, then the security term. Empty for a score of 0. */
    public function terms(): array
    {
        return $this->securityTerm === null ? $this->maintenanceTerms : array_merge($this->maintenanceTerms, [$this->securityTerm]);
    }

    /**
     * Each halving whose fact holds, in application order: reach on maintenance, then dev on the
     * total. The list holds a halving that removes nothing. Empty for a score of 0.
     *
     * @return list<Modifier>
     */
    public function modifiers(): array
    {
        return $this->modifiers;
    }

    /** @return list<Accepted> one per accepted flag that fired, in flag order */
    public function accepted(): array
    {
        return $this->accepted;
    }

    /**
     * One row per counted flag when the score has two or more terms, and one for the deciding
     * advisory whenever two or more advisories count. Empty for a score of 0.
     *
     * @return list<Without>
     */
    public function without(): array
    {
        return $this->without;
    }

    public function maintenancePart(): Part
    {
        return $this->maintenancePart;
    }

    public function securityPart(): SecurityPart
    {
        return $this->securityPart;
    }

    /**
     * The graded shape when a term exists, else the score-0 shape `{model, total, exact, accepted, text}`.
     *
     * @return Graded|Zero
     */
    public function toArray(): array
    {
        $score = $this->score;
        $grade = $score->grade();
        $accepted = array_map(static fn (Accepted $row): array => $row->toArray(), $this->accepted);
        if ($grade === null) {
            return ['model' => ScoreModel::ID, 'total' => 0, 'exact' => 0, 'accepted' => $accepted, 'text' => ScoreText::render($this)];
        }
        $total = $score->total();
        $next = ScoreModel::nextBand($grade);

        return [
            'model' => ScoreModel::ID,
            'total' => $total,
            'exact' => HalfPoints::json($score->exactHalves()),
            'rounded_down' => $this->roundedDown(),
            'band' => ['floor' => ScoreModel::floorOf($grade), 'next' => $next, 'to_next' => $next === null ? null : ScoreModel::floorOf($next) - $total],
            'decided_by' => self::decidedBy($grade, $score->maintenanceHalves(), $score->securityHalves()),
            'parts' => ['maintenance' => $this->maintenancePart->toArray(), 'security' => $this->securityPart->toArray()],
            'terms' => array_map(static fn (Term $term): array => $term->toArray(), $this->terms()),
            'modifiers' => array_map(static fn (Modifier $modifier): array => $modifier->toArray(), $this->modifiers),
            'accepted' => $accepted,
            'without' => array_map(static fn (Without $row): array => $row->toArray(), $this->without),
            'text' => ScoreText::render($this),
        ];
    }

    /** @return list<MaintenanceTerm> */
    private function maintenanceTermsOf(): array
    {
        $score = $this->score;
        $terms = [];
        foreach ($score->maintenanceTerms() as $term) {
            $terms[] = new MaintenanceTerm($term['flag'], $term['role'], $term['weight'], $term['divisor'], $term['points'], $score->halveForDev($score->halveForReach(2 * $term['points'])));
        }

        return $terms;
    }

    private function securityTermOf(): ?SecurityTerm
    {
        $score = $this->score;
        $deciding = $score->deciding();
        if ($deciding === null) {
            return null;
        }
        $multiplier = \in_array($deciding['fix_kind'], ScoreModel::DOUBLING, true) ? ScoreModel::NO_REACHABLE_FIX_FACTOR : 1;

        return new SecurityTerm($deciding['id'], $deciding['severity'], $deciding['fix_kind'], intdiv($score->securityPointsHalves(), 2 * $multiplier), $multiplier, intdiv($score->securityPointsHalves(), 2), $score->securityHalves());
    }

    /** @return list<Modifier> */
    private static function modifiersOf(Score $score): array
    {
        $modifiers = [];
        if ($score->reach() !== Score::DIRECT) {
            $modifiers[] = Modifier::reach($score->reach(), $score->maintenancePointsHalves(), $score->reachedHalves());
        }
        if ($score->isDev()) {
            $modifiers[] = Modifier::dev($score->reachedHalves() + $score->securityPointsHalves(), $score->exactHalves());
        }

        return $modifiers;
    }

    /** @return list<Accepted> */
    private function acceptedRows(): array
    {
        $rows = [];
        foreach ($this->flags->accepted() as $flag) {
            $counting = $this->flags->counting($flag);
            $rerun = Score::of($counting, $this->score->reach(), $this->score->isDev());
            $role = null;
            foreach ($rerun->maintenanceTerms() as $term) {
                $role = $term['flag'] === $flag ? $term['role'] : $role;
            }
            $rows[] = new Accepted(
                $flag,
                ScoreModel::POINTS[$flag],
                $rerun->total(),
                self::verdict($rerun, $counting, $this->context['maintenance_judged']),
                $role,
                $flag === FlagSet::STALE && (!$this->context['liveness_complete'] || $this->context['s3_unread']),
                self::modifiersOf($rerun)
            );
        }

        return $rows;
    }

    /** @return list<Without> */
    private function withoutRows(): array
    {
        $score = $this->score;
        $rows = [];
        $deciding = $score->deciding();
        if (\count($score->maintenanceTerms()) + ($deciding === null ? 0 : 1) >= 2) {
            foreach ($score->maintenanceTerms() as $term) {
                $flags = $this->flags->without($term['flag']);
                $hidden = $term['flag'] === FlagSet::ABANDONED ? $this->flags->hidden() : null;
                $rows[] = $this->rerun('flag', $term['flag'], $flags, $hidden === null ? [] : [$hidden], $this->atLeast($term['flag']));
            }
            if ($deciding !== null) {
                $rows[] = $this->rerun('flag', FlagSet::VULNERABLE, $this->flags->without(FlagSet::VULNERABLE), [], false);
            }
        }
        if ($deciding !== null && $score->advisoryCount() >= 2) {
            $rows[] = $this->rerun('advisory', $deciding['id'], $this->flags->withoutAdvisory($deciding['id']), [], false);
        }

        return $rows;
    }

    /** @param list<string> $revealed the words that the removal restores */
    private function rerun(string $kind, string $id, FlagSet $flags, array $revealed, bool $atLeast): Without
    {
        $rerun = Score::of($flags, $this->score->reach(), $this->score->isDev());
        $roles = array_column($rerun->maintenanceTerms(), 'role', 'flag');

        return new Without(
            $kind,
            $id,
            array_map(static fn (string $flag): array => ['flag' => $flag, 'role' => $roles[$flag] ?? 'accepted'], $revealed),
            $rerun->total(),
            self::verdict($rerun, $flags, $this->context['maintenance_judged']),
            $rerun->lead(),
            $rerun->deciding()['id'] ?? null,
            $atLeast
        );
    }

    /**
     * True for a without[abandoned] row when lockrot did not read S4 and S2 is absent or at high: S4
     * can restore silent or stale. True for a without[pinned] row when S8 cannot date the tag's branch.
     */
    private function atLeast(string $flag): bool
    {
        if ($flag === FlagSet::ABANDONED) {
            return !$this->context['liveness_complete'] && \in_array($this->flags->releaseLevel(), [null, 'high'], true);
        }

        return $flag === FlagSet::PINNED && $this->context['s8_unread'];
    }

    private function maintenanceStatus(): string
    {
        if ($this->score->maintenanceTerms() !== []) {
            return Part::COUNTED;
        }
        if ($this->flags->accepted() !== []) {
            return 'accepted';
        }

        return $this->context['maintenance_judged'] ? 'none' : 'not_judged';
    }

    private function securityStatus(): string
    {
        if ($this->score->deciding() !== null) {
            return Part::COUNTED;
        }

        return $this->context['advisories_complete'] ? 'clear' : 'unchecked';
    }

    private static function decidedBy(string $grade, int $maintenance, int $security): string
    {
        $byMaintenance = ScoreModel::band(intdiv($maintenance, 2)) === $grade;
        $bySecurity = ScoreModel::band(intdiv($security, 2)) === $grade;
        if ($byMaintenance) {
            return $bySecurity ? 'either' : 'maintenance';
        }

        return $bySecurity ? 'security' : 'combination';
    }
}
