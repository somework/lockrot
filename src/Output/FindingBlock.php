<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Allowlist\AllowlistEntry;
use Lockrot\Config\GateStanding;
use Lockrot\Lock\PackageOrigin;
use Lockrot\Score\FactState;
use Lockrot\Score\ScoreText;
use Lockrot\Score\Standing;
use Lockrot\Score\WorsenedFact;
use Lockrot\Signal\Data\AdvisoryData;
use Lockrot\Signal\Signal;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\MoveText;

/**
 * The parts of a finding block that table, markdown and github share, each a plain string
 * rendered from the finding's fields. A format adds its own markup.
 *
 * @internal
 */
final class FindingBlock
{
    private const ALLOWLIST_BY = [AllowlistEntry::BY_BUILTIN => "lockrot's list", AllowlistEntry::BY_PROJECT => 'your ignore list', AllowlistEntry::BY_TYPE => 'its package type'];

    /** The signals whose own flag line already prints a reading: the age line then stays out. */
    private const AGE_SIGNALS = [Signal::S2, Signal::S4, Signal::S8];

    /** A framework's core package has a direct dependent per bundle: the rest is counted, so the line stays one line. */
    private const DEPENDENTS_NAMED = 3;

    /** `direct`, `via <direct dependents>` or `unreached`. */
    public static function reach(Finding $finding): string
    {
        if ($finding->isDirect()) {
            return 'direct';
        }

        return $finding->reach() === 'unreached' ? 'unreached' : 'via '.self::dependents($finding);
    }

    /** `a, b, c and 2 more`: the first direct dependents, then how many are left out. */
    public static function dependents(Finding $finding): string
    {
        $dependents = $finding->directDependents();
        $named = implode(', ', \array_slice($dependents, 0, self::DEPENDENTS_NAMED));
        $more = \count($dependents) - self::DEPENDENTS_NAMED;

        return $more > 0 ? $named.' and '.$more.' more' : $named;
    }

    /**
     * The flags of the header in flag order: the counted ones, a flag the baseline entry lists read
     * `(baseline)`; a score-0 finding leads with its word. Accepted flags come last.
     *
     * @return list<string>
     */
    public static function headerFlags(Finding $finding, ?Standing $standing): array
    {
        $items = [];
        if (!$finding->isGraded()) {
            $items[] = $finding->verdict();
        }
        foreach ($finding->flagIds() as $flag) {
            $state = $standing === null ? null : $standing->flagState($flag);
            $items[] = $flag.($state !== null && $state->state() === FactState::KNOWN ? ' (baseline)' : '');
        }
        foreach ($finding->flags()->accepted() as $flag) {
            $items[] = $flag.' (accepted)';
        }

        return $items;
    }

    /** The counted flags joined ` · `, the github title's and the markdown Flags cell's list. */
    public static function flagList(Finding $finding): string
    {
        return implode(' · ', $finding->flagIds());
    }

    /** `score.text`. */
    public static function scoreLine(Finding $finding): string
    {
        return ScoreText::render($finding->scoreBasis());
    }

    /** `next_step.text`, null for a finding with no move. */
    public static function move(Finding $finding): ?string
    {
        $move = $finding->isGraded() ? $finding->nextStep() : null;

        return $move === null ? null : $move->text();
    }

    /**
     * {@see move()} as {@see MoveText::parts()}, null for a finding with no move.
     *
     * @return list<array{0: string, 1: bool}>|null
     */
    public static function moveParts(Finding $finding): ?array
    {
        $move = $finding->isGraded() ? $finding->nextStep() : null;

        return $move === null ? null : MoveText::parts($move);
    }

    /**
     * `flags[].summary` of each counted flag, in flag order.
     *
     * @return array<string, string> flag id => summary
     */
    public static function evidence(Finding $finding): array
    {
        return array_intersect_key($finding->flagSummaries(), array_flip($finding->flagIds()));
    }

    /**
     * The baseline suffix, from the finding's standing alone. Null without a baseline
     * or for a score-0 finding that the baseline does not hold.
     */
    public static function baselineSuffix(Finding $finding, ?Standing $standing): ?string
    {
        if ($standing === null) {
            return null;
        }
        if (!$standing->hasEntry()) {
            return '(baseline: new package)';
        }
        $now = $standing->advisoriesNow();
        $items = [];
        if ($now['known'] > 0) {
            $items[] = $now['known'].' '.($now['known'] === 1 ? 'advisory' : 'advisories').' known';
        }
        foreach ($standing->worsenedBy() as $fact) {
            if ($fact->kind() === WorsenedFact::FLAG) {
                $items[] = $fact->id().' new';
            }
        }
        if ($now['new'] > 0) {
            $items[] = $now['new'].' new '.($now['new'] === 1 ? 'advisory' : 'advisories');
        }
        foreach ($standing->worsenedBy() as $fact) {
            $item = self::advisoryItem($finding, $fact, $standing->comparedFixes());
            if ($item !== null) {
                $items[] = $item;
            }
        }
        foreach ($finding->flagIds() as $flag) {
            $state = $standing->flagState($flag);
            if ($state !== null && $state->state() === FactState::COVERED) {
                $items[] = $flag.' covered by the recorded '.$state->coveredBy();
            }
        }

        return '(baseline: '.($items === [] ? 'known' : implode('; ', $items)).')';
    }

    private static function advisoryItem(Finding $finding, WorsenedFact $fact, bool $comparedFixes): ?string
    {
        $now = $fact->now();
        if ($now === null || $fact->why() === FactState::NEW_FACT) {
            return null;
        }
        $name = self::advisoryName($finding, $fact->id());
        $lost = ' fix lost: '.$fact->recordedFixKind().' → '.$now->fixKind();
        if ($fact->why() === FactState::FIX_LOST) {
            return $name.$lost;
        }
        $crossed = $comparedFixes && !\in_array($fact->recordedFixKind(), Standing::LOST, true) && \in_array($now->fixKind(), Standing::LOST, true);

        return $name.' re-rated '.$fact->recordedSeverity().' → '.$now->severity().($crossed ? ','.$lost : '');
    }

    /** An advisory as a sentence names it: its CVE, else its id. */
    public static function advisoryName(Finding $finding, string $id): string
    {
        $s9 = $finding->signalData(AdvisoryData::class);
        foreach ($s9 === null ? [] : $s9->rows() as $row) {
            if ($row->id() === $id) {
                return $row->cve() ?? $id;
            }
        }

        return $id;
    }

    /** The gate basis line: the gate score, its two bounds and the new facts, null without a basis. */
    public static function gateLine(Finding $finding, ?GateStanding $gate): ?string
    {
        $basis = $gate === null ? null : $gate->basis();
        if ($basis === null) {
            return null;
        }
        $words = ['score' => 'the score is lower', 'cap' => 'the new facts bound it', 'equal' => 'both agree'];

        return \sprintf('gate %d = min(%d, 2 × %d) (%s); new: %s', $basis->total(), $finding->score()->total(), $basis->newScore()->total(), $words[$basis->limitedBy()] ?? $basis->limitedBy(), ScoreText::ofScore($basis->newScore()));
    }

    /** The abandoned-marking line, null when Composer's list removed no marking. */
    public static function markingLine(Finding $finding): ?string
    {
        $ignored = $finding->details()->abandonedIgnored();
        if ($ignored === null) {
            return null;
        }
        $patterns = [];
        $reasons = [];
        $constraints = [];
        foreach ($ignored->rules() as $rule) {
            $patterns[] = $rule->pattern();
            if ($rule->reason() !== null) {
                $reasons[] = $rule->reason();
            }
            foreach ($rule->constraints() as $constraint) {
                $constraints[$constraint] = $constraint;
            }
        }
        sort($constraints);

        return 'abandoned marking ignored ('.$ignored->by().': '.implode(', ', $patterns).')'
            .($reasons === [] ? '' : ': '.implode('; ', $reasons))
            .($constraints === [] ? '' : ' (constraint '.implode(', ', $constraints).' not applied, as composer audit does not apply it)');
    }

    /** The `age:` line: a graded finding none of whose S2, S4 and S8 fired, with a measured reading. */
    public static function ageLine(Finding $finding): ?string
    {
        if (!$finding->isGraded()) {
            return null;
        }
        foreach ($finding->signals() as $signal) {
            if (\in_array($signal->id(), self::AGE_SIGNALS, true)) {
                return null;
            }
        }

        return AgeText::line($finding);
    }

    /** S7's `pulls in …` sentence. */
    public static function pullsIn(Finding $finding): ?string
    {
        foreach ($finding->signals() as $signal) {
            if ($signal->id() === Signal::S7) {
                return $signal->summary();
            }
        }

        return null;
    }

    /** The message of a finding with S10 and no counted fact: its S10 summary, then the `checks missing:` line. */
    public static function uncheckedMessage(Finding $finding): string
    {
        $summary = '';
        foreach ($finding->signals() as $signal) {
            if ($signal->id() === Signal::S10) {
                $summary = $signal->summary();
            }
        }

        return $finding->package().' '.$finding->version().': '.$summary.'. '.self::checksMissing($finding).'.';
    }

    /** `checks missing: {ids} ({reason words})`, grouped by reason words in first-seen order. */
    public static function checksMissing(Finding $finding): ?string
    {
        $groups = [];
        foreach ($finding->checksMissing() as $check) {
            $words = self::checkWords($finding, $check->reason());
            $groups[$words] = array_merge($groups[$words] ?? [], $check->blocks());
        }
        if ($groups === []) {
            return null;
        }
        $parts = [];
        foreach ($groups as $words => $ids) {
            $parts[] = implode(', ', self::signalOrder($ids)).' ('.$words.')';
        }

        return 'checks missing: '.implode(' · ', $parts);
    }

    /** `checks accepted: …`, one group per `unchecked_accepted[]` entry, in entry order. */
    public static function checksAccepted(Finding $finding): ?string
    {
        $parts = [];
        foreach ($finding->details()->uncheckedAccepted() as $accepted) {
            $check = $accepted->unchecked();
            $parts[] = implode(', ', $check->blocks()).' ('.self::checkWords($finding, $check->reason()).'; '.CauseWords::of('accepted_by_config').': '.$accepted->entry()->reason().')';
        }

        return $parts === [] ? null : 'checks accepted: '.implode(' · ', $parts);
    }

    /**
     * The allowlist line when an entry accepts every maintenance flag: its label and its reason.
     *
     * @return ?array{string, string}
     */
    public static function allowlist(Finding $finding): ?array
    {
        $entry = $finding->details()->entry();
        if ($entry === null || !$entry->acceptsAll()) {
            return null;
        }

        return ['maintenance accepted ('.(self::ALLOWLIST_BY[$entry->by()] ?? $entry->by()).')', $entry->reason()];
    }

    /** The words of a check reason. An unknown origin reads its own words for `not_from_composer_repository`. */
    public static function checkWords(Finding $finding, string $reason): string
    {
        if ($reason === 'not_from_composer_repository' && !$finding->isFromComposerRepository() && $finding->origin()->kind() === PackageOrigin::UNKNOWN) {
            return 'origin unknown';
        }

        return CauseWords::of($reason);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string> each once, by signal number
     */
    private static function signalOrder(array $ids): array
    {
        $ids = array_unique($ids);
        usort($ids, 'strnatcmp');

        return $ids;
    }

    /** The colour of a grade: red for critical and high, yellow for medium, none otherwise. */
    public static function colour(string $verdict): ?string
    {
        if ($verdict === 'critical' || $verdict === 'high') {
            return 'red';
        }

        return $verdict === 'medium' ? 'yellow' : null;
    }
}
