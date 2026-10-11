<?php

declare(strict_types=1);

namespace Lockrot\Score;

use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\Score;

/**
 * The engine reruns of a move (`if_applied`), under the allowlist entry's whole accepted set.
 * `without[]` and `accepted[].if_counted` rerun the engine the same way ({@see ScoreBasis}).
 *
 * @internal
 */
final class Counterfactual
{
    /**
     * The score after a move. The verdict at 0 reads the flags that still fire: a removed accepted
     * flag no longer makes the finding `finished`.
     *
     * @param list<string> $removed           the flags that the move clears or settles: their signals go
     * @param list<string> $clearedAdvisories the advisory ids that the move clears
     * @param list<string> $raised            the flags that the move adds, and those it may raise unchecked
     * @param list<string> $assumes           the rerun's `assumes`
     */
    public static function afterMove(FlagSet $flags, array $removed, array $clearedAdvisories, array $raised, string $reach, bool $dev, bool $maintenanceJudged, bool $atMost, array $assumes): IfApplied
    {
        foreach ($removed as $flag) {
            $flags = $flags->without($flag);
        }
        foreach ($clearedAdvisories as $id) {
            $flags = $flags->withoutAdvisory($id);
        }
        foreach ($raised as $flag) {
            $flags = $flags->raising($flag);
        }
        $score = Score::of($flags, $reach, $dev);
        $deciding = $score->deciding();

        return new IfApplied($score->total(), ScoreBasis::verdict($score, $flags, $maintenanceJudged), $score->lead(), $deciding === null ? null : $deciding->advisory(), $atMost, $assumes);
    }
}
