<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Lockrot\Score\Accepted;
use Lockrot\Score\MaintenanceTerm;
use Lockrot\Score\Modifier;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\ScoreModel;

/**
 * `run.score_rules_used`: per rule of the score model, the findings on which it changed or decided
 * a number. `zero-verdicts` counts the score-0 findings, `counted` every finding with an accepted
 * flag, and `sort`, which changes no number, is null.
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
    public static function of(array $findings): array
    {
        $used = ScoreModel::rulesUnused();
        foreach ($findings as $finding) {
            $score = $finding->scoreBasis();
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
}
