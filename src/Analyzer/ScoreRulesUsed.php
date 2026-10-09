<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Lockrot\Score\Accepted;
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
                $used['divide-reach'] += self::rerunHalves($accepted, 'isReach') ? 1 : 0;
                $used['divide-dev'] += self::rerunHalves($accepted, 'isDev') ? 1 : 0;
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
            // The first maintenance term is the lead, and each other one corroborates it.
            $maintenance = \count($score->maintenanceTerms());
            $used['lead-first'] += $maintenance > 0 ? 1 : 0;
            $used['corroborating-share'] += $maintenance > 1 ? 1 : 0;
            $used['advisory-points'] += $security === null ? 0 : 1;
            $used['no-reachable-fix-multiplier'] += $security !== null && $security->multiplier() === ScoreModel::NO_REACHABLE_FIX_FACTOR ? 1 : 0;
            $used['security-max'] += $score->securityPart()->of() >= 2 ? 1 : 0;
            $used['divide-reach'] += $reach || self::rerunHalves($accepted, 'isReach') ? 1 : 0;
            $used['security-exempt-from-reach'] += $security !== null && !$finding->isDirect() ? 1 : 0;
            $used['sum'] += $score->maintenancePart()->isCounted() && $score->securityPart()->isCounted() ? 1 : 0;
            $used['divide-dev'] += $dev || self::rerunHalves($accepted, 'isDev') ? 1 : 0;
            $used['floor-once'] += $score->roundedDown() ? 1 : 0;
        }

        return $used;
    }

    /**
     * Whether the rerun of an accepted flag halves its number by the kind of modifier.
     *
     * @param list<Accepted>          $accepted
     * @param 'isReach'|'isDev' $kind
     */
    private static function rerunHalves(array $accepted, string $kind): bool
    {
        foreach ($accepted as $row) {
            foreach ($row->modifiers() as $modifier) {
                if ($modifier->{$kind}() && $modifier->changes()) {
                    return true;
                }
            }
        }

        return false;
    }
}
