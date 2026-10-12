<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Score\CountedAdvisory;
use Lockrot\Score\GateScore;
use Lockrot\Score\SecurityTerm;
use Lockrot\Score\Term;
use Lockrot\Security\Severity;
use Lockrot\Signal\Data\Check;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\FailOnValue;
use Lockrot\Verdict\Score;
use Lockrot\Verdict\ScoreModel;

/**
 * The single producer of `gate.by[]`: per active value that a finding fails, the facts whose records
 * are `error` (SPEC §6.2, "Record marks follow the fact"). github, gitlab and sarif read their marks
 * from it, so no format keeps a second copy of the rule.
 *
 * @internal
 */
final class RecordMarks
{
    /**
     * @param ?GateScore            $gate      the gate score under a baseline entry, null without an entry
     * @param list<string>          $uncovered the counted flags that no entry covers: all of them without one
     * @param list<CountedAdvisory> $unknown   the counted advisories that no entry knows: all of them without one
     * @param list<Check>           $missing   the checks of S10
     * @param list<FailOnValue>     $values    `run.gates[]`
     *
     * @return list<FailedValue> one per value the finding fails, in the order of $values
     */
    public static function of(Score $score, ?GateScore $gate, array $uncovered, array $unknown, array $missing, array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $facts = self::facts($score, $gate, $uncovered, $unknown, $missing, $value);
            if ($facts !== null) {
                $out[] = new FailedValue($value, $facts);
            }
        }

        return $out;
    }

    /**
     * @param list<string>          $uncovered
     * @param list<CountedAdvisory> $unknown
     * @param list<Check>           $missing
     *
     * @return ?list<MarkedFact> null when the finding does not fail the value
     */
    private static function facts(Score $score, ?GateScore $gate, array $uncovered, array $unknown, array $missing, FailOnValue $value): ?array
    {
        switch ($value->kind()) {
            case FailOn::KIND_UNCHECKED:
                return $missing === [] || ($gate !== null && $gate->newScore()->total() === 0) ? null : self::blocked($missing);
            case FailOn::KIND_FLAG:
                $limit = array_search($value->threshold(), ScoreModel::FLAG_ORDER, true);
                $hit = array_filter($uncovered, static fn (string $flag): bool => array_search($flag, ScoreModel::FLAG_ORDER, true) <= $limit);

                return $hit === [] ? null : array_values(array_map(static fn (string $flag): MarkedFact => new MarkedFact(MarkedFact::FLAG, $flag), $hit));
            case FailOn::KIND_VULNERABLE:
                $hit = self::atOrAbove($unknown, (string) $value->threshold());

                return $hit === [] ? null : array_map(static fn (CountedAdvisory $advisory): MarkedFact => new MarkedFact(MarkedFact::ADVISORY, $advisory->id()), $hit);
            default:
                $total = $gate === null ? $score->total() : $gate->total();
                if ($total < ScoreModel::floorOf((string) $value->threshold())) {
                    return null;
                }
                $terms = $gate === null ? self::termsOf($score) : $gate->newTerms();

                return array_map(static fn (Term $term): MarkedFact => $term instanceof SecurityTerm ? new MarkedFact(MarkedFact::ADVISORY, $term->advisory()) : new MarkedFact(MarkedFact::FLAG, $term->flag()), GateScore::termsReaching($terms, (string) $value->threshold(), $gate !== null));
        }
    }

    /**
     * Whether a counted advisory reaches a `vulnerable` value by its raw severity.
     *
     * @param list<CountedAdvisory> $advisories
     */
    public static function anyAtOrAbove(array $advisories, string $threshold): bool
    {
        return self::atOrAbove($advisories, $threshold) !== [];
    }

    /**
     * @param list<CountedAdvisory> $advisories
     *
     * @return list<CountedAdvisory> in the severity order, then by id
     */
    private static function atOrAbove(array $advisories, string $threshold): array
    {
        $rank = Severity::fromComposer($threshold)->gateRank();
        $hit = array_values(array_filter(
            array_map(static fn (CountedAdvisory $advisory): CountedAdvisory => $advisory->bucketed(), $advisories),
            static fn (CountedAdvisory $advisory): bool => Severity::fromComposer($advisory->severity())->gateRank() >= $rank
        ));
        usort($hit, static fn (CountedAdvisory $a, CountedAdvisory $b): int => array_search($a->severity(), Severity::DISPLAY_ORDER, true) <=> array_search($b->severity(), Severity::DISPLAY_ORDER, true) ?: strcmp($a->id(), $b->id()));

        return $hit;
    }

    /**
     * @param list<Check> $missing
     *
     * @return list<MarkedFact> each blocked signal once, in the order of the checks
     */
    private static function blocked(array $missing): array
    {
        $ids = [];
        foreach ($missing as $check) {
            foreach ($check->blocks() as $signal) {
                $ids[$signal] = new MarkedFact(MarkedFact::SIGNAL, $signal);
            }
        }

        return array_values($ids);
    }

    /** @return list<Term> */
    private static function termsOf(Score $score): array
    {
        $deciding = $score->deciding();

        return $deciding === null ? $score->maintenanceTerms() : array_merge($score->maintenanceTerms(), [$deciding]);
    }
}
