<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Lockrot\Baseline\BaselineComparison;
use Lockrot\Config\Gate;
use Lockrot\Score\Accepted;
use Lockrot\Score\FactState;
use Lockrot\Score\GateScore;
use Lockrot\Score\MaintenanceTerm;
use Lockrot\Score\Modifier;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\ScoreModel;

/**
 * `run.score_rules_used`: per rule of the score model, the findings on which it changed or decided
 * a number. `zero-verdicts` counts the score-0 findings, `counted` every finding with an accepted
 * flag, and `sort`, which changes no number, is null. `gate-bounded-new` counts a graded finding
 * whose gate basis the cap limits, and `baseline-cover` a finding whose entry covers a flag.
 *
 * @internal
 */
final class ScoreRulesUsed
{
    /**
     * @param list<Finding> $findings
     *
     * @return array<string, int|null>
     */
    public static function of(array $findings, ?BaselineComparison $baseline = null, ?Gate $gate = null): array
    {
        $used = ScoreModel::rulesUnused();
        foreach ($findings as $finding) {
            $standing = $gate === null ? null : $gate->standingOf($finding->package());
            $basis = $standing === null ? null : $standing->basis();
            $score = $finding->scoreBasis();
            $used['gate-bounded-new'] += $score->isGraded() && $basis !== null && $basis->limitedBy() === GateScore::LIMITED_BY_CAP ? 1 : 0;
            $used['baseline-cover'] += self::coversAFlag($finding, $baseline) ? 1 : 0;
            $accepted = $score->accepted();
            $used['counted'] += $accepted === [] ? 0 : 1;
            if (!$score->isGraded()) {
                $used['divide-reach'] += self::rerunHalves($accepted, static fn (Modifier $modifier): bool => $modifier->isReach()) ? 1 : 0;
                $used['divide-dev'] += self::rerunHalves($accepted, static fn (Modifier $modifier): bool => $modifier->isDev()) ? 1 : 0;
                ++$used['zero-verdicts'];
                continue;
            }
            $security = $score->securityTerm();
            $reach = false;
            $dev = false;
            foreach ($score->modifiers() as $modifier) {
                $reach = $reach || ($modifier->isReach() && $modifier->changes());
                $dev = $dev || $modifier->isDev();
            }
            ++$used['band-floors'];
            $leads = \count(array_filter($score->maintenanceTerms(), static fn (MaintenanceTerm $term): bool => $term->isLead()));
            $used['lead-first'] += $leads > 0 ? 1 : 0;
            $used['corroborating-share'] += \count($score->maintenanceTerms()) > $leads ? 1 : 0;
            $used['advisory-points'] += $security === null ? 0 : 1;
            $used['no-reachable-fix-multiplier'] += $security !== null && $security->multiplier() === ScoreModel::NO_REACHABLE_FIX_FACTOR ? 1 : 0;
            $used['security-max'] += $score->securityPart()->of() >= 2 ? 1 : 0;
            $used['divide-reach'] += $reach || self::rerunHalves($accepted, static fn (Modifier $modifier): bool => $modifier->isReach()) ? 1 : 0;
            $used['security-exempt-from-reach'] += $security !== null && !$finding->isDirect() ? 1 : 0;
            $used['sum'] += $score->maintenancePart()->isCounted() && $score->securityPart()->isCounted() ? 1 : 0;
            $used['divide-dev'] += $dev ? 1 : 0;
            $used['floor-once'] += $score->roundedDown() ? 1 : 0;
        }

        return $used;
    }

    /**
     * Whether the rerun of an accepted flag halves its number by the kind of modifier.
     *
     * @param list<Accepted>             $accepted
     * @param \Closure(Modifier): bool $isKind
     */
    private static function rerunHalves(array $accepted, \Closure $isKind): bool
    {
        foreach ($accepted as $row) {
            foreach ($row->modifiers() as $modifier) {
                if ($isKind($modifier) && $modifier->changes()) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function coversAFlag(Finding $finding, ?BaselineComparison $baseline): bool
    {
        $standing = $baseline === null ? null : $baseline->standingOf($finding->package());
        if ($standing === null) {
            return false;
        }
        foreach ($finding->flags()->fired() as $flag) {
            $state = $standing->flagState($flag);
            if ($state !== null && $state->state() === FactState::COVERED) {
                return true;
            }
        }

        return false;
    }
}
