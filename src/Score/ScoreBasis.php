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
 * subtraction. The engine counts in integer half points. {@see toArray()} writes a half as 0.5.
 *
 * @internal
 *
 * @phpstan-type Context array{maintenance_judged: bool, advisories_complete: bool, liveness_complete: bool, s3_unread: bool, s8_unread: bool}
 * @phpstan-type Modifier array{reason: string, applies_to: string, divide_by: int, before: int|float, after: int|float}
 * @phpstan-type Accepted array{flag: string, weight: int, if_counted: array{total: int, verdict: ?string, role: ?string, at_least: bool, modifiers: list<Modifier>}}
 * @phpstan-type Without array{remove: array{kind: string, id: string}, revealed: list<array{flag: string, role: string}>, total: int, verdict: ?string, lead: ?string, deciding_advisory: ?string, at_least: bool}
 * @phpstan-type MaintenanceTerm array{part: 'maintenance', flag: string, role: string, weight: int, divisor: int, points: int, contribution: int|float}
 * @phpstan-type SecurityTerm array{part: 'security', flag: string, role: string, advisory: string, severity: string, fix_kind: string, weight: int, multiplier: int, points: int, contribution: int|float}
 * @phpstan-type Term MaintenanceTerm|SecurityTerm
 * @phpstan-type Part array{status: string, contribution: int|float, alone: array{total: int, verdict: ?string}}
 * @phpstan-type Graded array{model: int, total: int, exact: int|float, rounded_down: bool, band: array{floor: int, next: ?string, to_next: ?int}, decided_by: string, parts: array{maintenance: Part, security: array{status: string, contribution: int|float, alone: array{total: int, verdict: ?string}, of: int, tied: list<string>}}, terms: list<Term>, modifiers: list<Modifier>, accepted: list<Accepted>, without: list<Without>, text: string}
 * @phpstan-type Zero array{model: int, total: int, exact: int, accepted: list<Accepted>, text: string}
 */
final class ScoreBasis
{
    private FlagSet $flags;
    private Score $score;
    /** @var Context */
    private array $context;

    /** @param Context $context */
    private function __construct(FlagSet $flags, Score $score, array $context)
    {
        $this->flags = $flags;
        $this->score = $score;
        $this->context = $context;
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
     * The graded shape when a term exists, else the score-0 shape `{model, total, exact, accepted, text}`.
     *
     * @return Graded|Zero
     */
    public function toArray(): array
    {
        $score = $this->score;
        $grade = $score->grade();
        if ($grade === null) {
            $zero = ['model' => ScoreModel::ID, 'total' => 0, 'exact' => 0, 'accepted' => $this->accepted()];

            return $zero + ['text' => ScoreText::render($zero)];
        }
        $total = $score->total();
        $next = ScoreModel::nextBand($grade);
        $maintenance = $score->maintenanceHalves();
        $security = $score->securityHalves();
        $out = [
            'model' => ScoreModel::ID,
            'total' => $total,
            'exact' => self::number($score->exactHalves()),
            'rounded_down' => $score->exactHalves() !== 2 * $total,
            'band' => ['floor' => ScoreModel::floorOf($grade), 'next' => $next, 'to_next' => $next === null ? null : ScoreModel::floorOf($next) - $total],
            'decided_by' => self::decidedBy($grade, $maintenance, $security),
            'parts' => [
                'maintenance' => ['status' => $this->maintenanceStatus(), 'contribution' => self::number($maintenance), 'alone' => self::alone($maintenance)],
                'security' => ['status' => $this->securityStatus(), 'contribution' => self::number($security), 'alone' => self::alone($security), 'of' => $score->advisoryCount(), 'tied' => $score->tied()],
            ],
            'terms' => $this->terms(),
            'modifiers' => self::modifiers($score),
            'accepted' => $this->accepted(),
            'without' => $this->without(),
        ];

        return $out + ['text' => ScoreText::render($out)];
    }

    /** @return list<Term> */
    private function terms(): array
    {
        $score = $this->score;
        $terms = [];
        foreach ($score->maintenanceTerms() as $term) {
            $terms[] = ['part' => 'maintenance'] + $term + ['contribution' => self::number($score->halveForDev($score->halveForReach(2 * $term['points'])))];
        }
        $deciding = $score->deciding();
        if ($deciding !== null) {
            $multiplier = \in_array($deciding['fix_kind'], ScoreModel::DOUBLING, true) ? ScoreModel::NO_REACHABLE_FIX_FACTOR : 1;
            $terms[] = [
                'part' => 'security', 'flag' => FlagSet::VULNERABLE, 'role' => 'security', 'advisory' => $deciding['id'], 'severity' => $deciding['severity'], 'fix_kind' => $deciding['fix_kind'],
                'weight' => intdiv($score->securityPointsHalves(), 2 * $multiplier), 'multiplier' => $multiplier, 'points' => intdiv($score->securityPointsHalves(), 2), 'contribution' => self::number($score->securityHalves()),
            ];
        }

        return $terms;
    }

    /**
     * Each halving whose fact holds on a score with a term, in application order: reach on
     * maintenance, then dev on the total. The list holds a halving that removes nothing, with
     * `before` equal to `after`.
     *
     * @return list<Modifier>
     */
    private static function modifiers(Score $score): array
    {
        $modifiers = [];
        if ($score->reach() !== Score::DIRECT) {
            $modifiers[] = ['reason' => $score->reach(), 'applies_to' => 'maintenance', 'divide_by' => ScoreModel::REACH_DIVISOR, 'before' => self::number($score->maintenancePointsHalves()), 'after' => self::number($score->reachedHalves())];
        }
        if ($score->isDev()) {
            $before = $score->reachedHalves() + $score->securityPointsHalves();
            $modifiers[] = ['reason' => 'dev', 'applies_to' => 'total', 'divide_by' => ScoreModel::DEV_DIVISOR, 'before' => self::number($before), 'after' => self::number($score->exactHalves())];
        }

        return $modifiers;
    }

    /**
     * One row per accepted flag that fired, in flag order, with the rerun that counts it.
     *
     * @return list<Accepted>
     */
    private function accepted(): array
    {
        $rows = [];
        foreach ($this->flags->accepted() as $flag) {
            $rerun = Score::of($this->flags->counting($flag), $this->score->reach(), $this->score->isDev());
            $role = null;
            foreach ($rerun->maintenanceTerms() as $term) {
                $role = $term['flag'] === $flag ? $term['role'] : $role;
            }
            $rows[] = ['flag' => $flag, 'weight' => ScoreModel::POINTS[$flag], 'if_counted' => [
                'total' => $rerun->total(),
                'verdict' => $rerun->grade(),
                'role' => $role,
                'at_least' => $flag === FlagSet::STALE && (!$this->context['liveness_complete'] || $this->context['s3_unread']),
                'modifiers' => self::modifiers($rerun),
            ]];
        }

        return $rows;
    }

    /**
     * One row per counted flag when the score has two or more terms, and one for the deciding advisory
     * whenever two or more advisories count.
     *
     * @return list<Without>
     */
    private function without(): array
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

    /**
     * @param list<string> $revealed the words that the removal restores
     *
     * @return Without
     */
    private function rerun(string $kind, string $id, FlagSet $flags, array $revealed, bool $atLeast): array
    {
        $rerun = Score::of($flags, $this->score->reach(), $this->score->isDev());
        $roles = array_column($rerun->maintenanceTerms(), 'role', 'flag');

        return [
            'remove' => ['kind' => $kind, 'id' => $id],
            'revealed' => array_map(static fn (string $flag): array => ['flag' => $flag, 'role' => $roles[$flag] ?? 'accepted'], $revealed),
            'total' => $rerun->total(),
            'verdict' => $rerun->grade(),
            'lead' => $rerun->lead(),
            'deciding_advisory' => $rerun->deciding()['id'] ?? null,
            'at_least' => $atLeast,
        ];
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
            return 'counted';
        }
        if ($this->flags->accepted() !== []) {
            return 'accepted';
        }

        return $this->context['maintenance_judged'] ? 'none' : 'not_judged';
    }

    private function securityStatus(): string
    {
        if ($this->score->deciding() !== null) {
            return 'counted';
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

    /** @return array{total: int, verdict: ?string} */
    private static function alone(int $halves): array
    {
        return ['total' => intdiv($halves, 2), 'verdict' => ScoreModel::band(intdiv($halves, 2))];
    }

    /** @return int|float PHP's `/` gives an integer for a whole quotient and an exact float for a half */
    private static function number(int $halves)
    {
        return $halves / 2;
    }
}
