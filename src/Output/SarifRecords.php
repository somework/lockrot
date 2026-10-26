<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Baseline\BaselineComparison;
use Lockrot\Config\GateStanding;
use Lockrot\Score\FactState;
use Lockrot\Score\MaintenanceTerm;
use Lockrot\Score\Standing;
use Lockrot\Security\Severity;
use Lockrot\Signal\Data\AdvisoryData;
use Lockrot\Signal\Signal;
use Lockrot\Signal\Thresholds;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\ScoreModel;

/**
 * The SARIF results of one finding: one per counted maintenance flag and one per counted
 * advisory, each marked by the fact that tripped the gate. A finding with S10 and no counted fact
 * gets its `lockrot/unchecked` result, and a score-0 finding under `--all` the result of its word.
 *
 * @internal
 *
 * @phpstan-type Bag array<string, scalar|object|list<string>|null>
 * @phpstan-type Result array{ruleId: string, level: string, rank?: float, message: array{text: string}, partialFingerprints: array<string, string>, suppressions?: list<array{kind: string, status: string, justification: string}>, properties: Bag}
 * @phpstan-type Rule array{id: string, name: string, shortDescription: array{text: string}, fullDescription: array{text: string}, defaultConfiguration: array{level: string}, helpUri: string, properties: array{tags: list<string>, security-severity?: string}}
 */
final class SarifRecords
{
    /** The rule text of each rule id, as {short, full}. A `{key}` in the full text is a threshold that `run.score_model` lists for the flag. */
    private const RULES = [
        'abandoned' => ['Package is marked abandoned, or its repository is archived', 'The package\'s registry or composer.lock marks it abandoned, or its repository is archived.'],
        'silent' => ['No stable release and no repository push for a long time', 'No release for {release-high-years} and no repository push for {push-high-years}.'],
        'pinned' => ['Installed version is a branch snapshot, or the package has no stable release', 'The installed version is a branch snapshot (dev-* or #hash), or the package has no stable release at all.'],
        'left-behind' => ['The installed release branch stopped while a newer branch kept releasing', 'No stable release on the installed branch for {release-warn-years}, while a higher branch has released since.'],
        'old-promise' => ['Released before the target PHP\'s major with an open-ended php constraint', 'The installed version was released before the target PHP\'s GA date, and its php constraint admits that target untested.'],
        'stale' => ['Old release or old push, below the silent thresholds', 'No release for {release-warn-years}, or no repository push for {push-warn-years}, short of silent.'],
        'capped' => ['The installed version\'s php constraint stops below the newest PHP the project allows', 'The installed version\'s php requirement has an upper bound below the newest PHP minor that the project allows.'],
        'ok' => ['Nothing counts', 'No flag counts and no counted advisory affects the installed version.'],
        'finished' => ['Accepted by an allowlist entry', 'An allowlist entry accepts the package as complete by design, not neglected.'],
        'unknown' => ['lockrot could not read its release metadata', 'No flag counts, and lockrot could not read the release metadata that judges maintenance.'],
        'unchecked' => ['A check did not run, and nothing else grades the package', 'A check that the package needs did not run (S10), and no counted fact grades it. --fail-on=unchecked fails on it.'],
        'advisories-unchecked' => ['The advisories of some packages were not checked', 'The gate could not read the advisories of every package. It is an error when --fail-on=unchecked fails on the gap.'],
    ];
    private const HELP_URI = 'https://lockrot.dev/verdicts/';

    private Finding $finding;
    private ?GateStanding $gate;
    private ?Standing $standing;
    private ?string $baselinePath;
    /** @var array<string, ?string> by rule id, the advisory severity of each record that results() wrote */
    private array $rules = [];

    public function __construct(Finding $finding, ?GateStanding $gate, ?BaselineComparison $baseline)
    {
        $this->finding = $finding;
        $this->gate = $gate;
        $this->standing = $baseline === null ? null : $baseline->standingOf($finding->package());
        $this->baselinePath = $baseline === null ? null : $baseline->path();
    }

    /**
     * A score-0 finding gets the record of its word when the format lists every finding, and its own
     * `lockrot/unchecked` record when $uncheckedRecord holds.
     *
     * @return list<Result>
     */
    public function results(bool $showAll, bool $uncheckedRecord): array
    {
        $this->rules = [];
        if (!$this->finding->isGraded()) {
            $results = [];
            $head = $this->finding->package().' '.$this->finding->version();
            if ($showAll) {
                $results[] = $this->result('lockrot/'.$this->finding->verdict(), FormatContext::LEVEL_NOTE, null, $head.' — finding '.$this->finding->verdict().', '.FindingBlock::scoreLine($this->finding), ['lockrot/package' => $this->finding->package()], $this->bag([], true));
            }
            if ($uncheckedRecord) {
                $errorBy = $this->errorBy(MarkedFact::SIGNAL, null);
                $results[] = $this->result('lockrot/unchecked', $errorBy === [] ? FormatContext::LEVEL_NOTE : FormatContext::LEVEL_ERROR, null, FindingBlock::uncheckedMessage($this->finding), ['lockrot/package' => $this->finding->package()], $this->bag($errorBy, $results === []));
            }

            return $results;
        }
        $results = [];
        $summaries = $this->finding->flagSummaries();
        $move = FindingBlock::move($this->finding);
        // `unchecked` marks the finding's first record.
        $unchecked = $this->errorBy(MarkedFact::SIGNAL, null);
        $first = true;
        foreach ($this->finding->scoreBasis()->maintenanceTerms() as $term) {
            $flag = $term->flag();
            $errorBy = self::union($this->errorBy('flag', $flag), $results === [] ? $unchecked : []);
            $text = $this->finding->package().' '.$this->finding->version().' — finding '.$this->finding->verdict().', '.FindingBlock::scoreLine($this->finding).' — '.$flag.': '.($summaries[$flag] ?? '').'.'.($move === null ? '' : ' Do: '.$move.'.');
            $state = $this->standing === null ? null : $this->standing->flagState($flag);
            $results[] = $this->result('lockrot/'.$flag, $this->level($errorBy, $state), self::termRank($term), $text, ['lockrot/package' => $this->finding->package()], $this->bag($errorBy, $first) + ['term' => $term], $state);
            $first = false;
        }
        $first = true;
        $s9 = $this->finding->signalData(AdvisoryData::class);
        $security = $this->finding->scoreBasis()->securityTerm();
        foreach ($s9 === null ? [] : $s9->withDeciding($security === null ? null : $security->advisory())->rows() as $row) {
            $errorBy = self::union($this->errorBy('advisory', $row->id()), $results === [] ? $unchecked : []);
            $advisoryMove = $this->moveClearing($row->id());
            $text = $this->finding->package().' '.$this->finding->version().' is affected by '.($row->cve() ?? $row->id()).' ('.$row->severity().' advisory): '.$row->title().'.'.($advisoryMove === null ? '' : ' Do: '.$advisoryMove.'.');
            $bag = $this->bag($errorBy, $first) + ['advisory' => $row->id(), 'cve' => $row->cve(), 'severity' => $row->severity(), 'fix' => $row->fix(), 'points' => $row->points(), 'deciding' => $row->isDeciding()];
            if ($row->isDeciding() && $security !== null) {
                $bag['term'] = $security;
            }
            $state = $this->standing === null ? null : $this->standing->advisoryState($row->id());
            $results[] = $this->result('lockrot/advisory/'.$row->id(), $this->level($errorBy, $state), (float) Severity::fromComposer($row->severity())->sarifRank(), $text, ['lockrot/package' => $this->finding->package(), 'lockrot/advisory' => $row->id()], $bag, $state, $row->severity());
            $first = false;
        }

        return $results;
    }

    /** @return array<string, Rule> by rule id, the rules of the records that the last results() call returned */
    public function rules(Thresholds $thresholds): array
    {
        $rules = [];
        foreach ($this->rules as $ruleId => $severity) {
            $rules[$ruleId] = self::rule($ruleId, $severity, $thresholds);
        }

        return $rules;
    }

    /**
     * The rule descriptor of a rule id: a flag, a score-0 word, `unchecked`, or an advisory, which
     * carries its `security-severity`.
     *
     * @return Rule
     */
    public static function rule(string $ruleId, ?string $severity, Thresholds $thresholds): array
    {
        $name = substr($ruleId, \strlen('lockrot/'));
        if (strpos($name, 'advisory/') === 0) {
            $bucket = Severity::fromComposer($severity);
            $properties = ['tags' => ['security', 'dependency']];
            if ($bucket->sarifSecuritySeverity() !== null) {
                $properties['security-severity'] = $bucket->sarifSecuritySeverity();
            }

            return [
                'id' => $ruleId,
                'name' => $name,
                'shortDescription' => ['text' => 'Security advisory '.substr($name, \strlen('advisory/')).' affects the installed version'],
                'fullDescription' => ['text' => 'A counted security advisory affects the installed version: '.$bucket->bucket().' severity.'],
                'defaultConfiguration' => ['level' => FormatContext::LEVEL_WARNING],
                'helpUri' => self::HELP_URI.'#security-advisories',
                'properties' => $properties,
            ];
        }
        [$short, $full] = self::RULES[$name];

        return [
            'id' => $ruleId,
            'name' => $name,
            'shortDescription' => ['text' => $name.': '.$short],
            'fullDescription' => ['text' => self::fullText($full, $name, $thresholds)],
            'defaultConfiguration' => ['level' => \in_array($name, ScoreModel::FLAG_ORDER, true) ? FormatContext::LEVEL_WARNING : FormatContext::LEVEL_NOTE],
            'helpUri' => self::HELP_URI,
            'properties' => ['tags' => ['maintainability']],
        ];
    }

    /** `run.score_model` names the thresholds of a flag, and `run.thresholds` holds their values. */
    private static function fullText(string $full, string $flag, Thresholds $thresholds): string
    {
        $values = $thresholds->toArray();
        $words = [];
        foreach (ScoreModel::thresholdsOf($flag) as $key) {
            $years = $values[$key];
            $words['{'.$key.'}'] = $years.($years === 1 ? ' year' : ' years').' ('.$key.')';
        }

        return strtr($full, $words);
    }

    /**
     * @param array<string, string> $fingerprints
     * @param Bag                   $bag
     *
     * @return Result
     */
    private function result(string $ruleId, string $level, ?float $rank, string $text, array $fingerprints, array $bag, ?FactState $state = null, ?string $severity = null): array
    {
        $this->rules[$ruleId] = $severity;
        $result = ['ruleId' => $ruleId, 'level' => $level];
        if ($rank !== null) {
            $result['rank'] = $rank;
        }
        $result['message'] = ['text' => $text];
        $result['partialFingerprints'] = $fingerprints;
        if ($level === FormatContext::LEVEL_NOTE && $state !== null && \in_array($state->state(), [FactState::KNOWN, FactState::COVERED], true)) {
            $result['suppressions'] = [['kind' => 'external', 'status' => 'accepted', 'justification' => 'covered by '.$this->baselinePath.' (known since '.$state->since().')']];
        }

        return $result + ['properties' => $bag];
    }

    /**
     * `error` when a failed value names the fact, `note` when the baseline knows or covers it, else
     * `warning`.
     *
     * @param list<string> $errorBy
     */
    public function level(array $errorBy, ?FactState $state): string
    {
        if ($errorBy !== []) {
            return FormatContext::LEVEL_ERROR;
        }

        return $state !== null && \in_array($state->state(), [FactState::KNOWN, FactState::COVERED], true) ? FormatContext::LEVEL_NOTE : FormatContext::LEVEL_WARNING;
    }

    /** @return list<string> the active values whose `gate.by[].facts` name the fact; any fact of the kind for a null id */
    public function errorBy(string $kind, ?string $id): array
    {
        $values = [];
        foreach ($this->gate === null ? [] : $this->gate->by() as $failed) {
            foreach ($failed->facts() as $fact) {
                if ($fact->kind() === $kind && ($id === null || $fact->id() === $id)) {
                    $values[$failed->value()->value()] = $failed->value()->value();
                }
            }
        }

        return array_values($values);
    }

    /**
     * The bag every result carries; the package's first result of each kind adds its chain, depth,
     * direct dependents, signal ids and age.
     *
     * @param list<string> $errorBy
     *
     * @return Bag
     */
    private function bag(array $errorBy, bool $first): array
    {
        $finding = $this->finding;
        $bag = [
            'package' => $finding->package(),
            'version' => $finding->version(),
            'verdict' => $finding->verdict(),
            'priority' => $finding->gradeOrNone(),
            'lead' => $finding->lead(),
            'score_total' => $finding->score()->total(),
            'score_text' => FindingBlock::scoreLine($finding),
            'score_model_id' => ScoreModel::ID,
            'flag_ids' => $finding->flagIds(),
            'direct' => $finding->isDirect(),
            'dev' => $finding->isDev(),
            'data_date' => $finding->dataDate() === null ? null : $finding->dataDate()->format(\DATE_ATOM),
            'baseline_status' => $this->standing === null ? null : $this->standing->status(),
            'error_by' => $errorBy,
        ];
        if ($first) {
            $bag += [
                'chain' => $finding->chain(),
                'depth' => $finding->depth(),
                'direct_dependents' => $finding->directDependents(),
                'signal_ids' => array_map(static fn (Signal $signal): string => $signal->id(), $finding->signals()),
                'age' => $finding->details()->age(),
            ];
        }

        return $bag;
    }

    /**
     * The values of a fact, then the `unchecked` value of the first record: only `unchecked` marks
     * S10, so the two lists share no value.
     *
     * @param list<string> $a
     * @param list<string> $b
     *
     * @return list<string>
     */
    public static function union(array $a, array $b): array
    {
        return array_merge($a, $b);
    }

    /** The baseline state of a counted flag, null without an entry. */
    public function flagState(string $flag): ?FactState
    {
        return $this->standing === null ? null : $this->standing->flagState($flag);
    }

    /** Whether the baseline entry knows every counted advisory: the `vulnerable` issue is then covered. */
    public function advisoriesKnown(): bool
    {
        if ($this->standing === null) {
            return false;
        }
        $now = $this->standing->advisoriesNow();

        return $now['new'] + $now['re_rated'] + $now['fix_lost'] === 0;
    }

    /** The first of `next_step` and `next_step.also[]` whose `clears[]` names the advisory. */
    private function moveClearing(string $advisory): ?string
    {
        $move = $this->finding->nextStep();
        foreach ($move === null ? [] : array_merge([$move], $move->also()) as $candidate) {
            if (\in_array($advisory, $candidate->clearedAdvisories(), true)) {
                return $candidate->text();
            }
        }

        return null;
    }


    /** The band of the term's own contribution, × 25; below low's floor it ranks 25. */
    private static function termRank(MaintenanceTerm $term): float
    {
        foreach (self::BAND_RANKS as $floor => $rank) {
            if ($term->contributionHalves() >= 2 * $floor) {
                return $rank;
            }
        }

        return self::LOWEST_RANK;
    }

    private const BAND_RANKS = [32 => 100.0, 16 => 75.0, 8 => 50.0];
    private const LOWEST_RANK = 25.0;
}
