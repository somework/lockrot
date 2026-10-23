<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Lockrot\Baseline\BaselineComparison;
use Lockrot\Config\Gate;
use Lockrot\Config\GateStanding;
use Lockrot\Data\Advisory\AdvisoryCoverage;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\ScoreModel;

/** @internal */
final class Report
{
    /** The parents the `pulled in by:` line names before it counts the rest. */
    public const EXPOSURE_NAMES = 5;

    /** @var list<Finding> */
    private array $findings;
    /** @var list<RunNote> */
    private array $notes;
    private \DateTimeImmutable $generatedAt;
    private int $packagesChecked;
    private int $notFromComposerRepository;
    /** Null when the project has no baseline file. */
    private ?BaselineComparison $baseline;
    /** Null when the report was not told what the run was asked to do. */
    private ?RunSettings $run = null;
    /**
     * Fetch time of the oldest repository-activity answer from lockrot's cache, null when every
     * answer was fetched in this run or none was needed. Repository metadata is revalidated on
     * every run, so this is the one source whose age the report must state.
     */
    private ?\DateTimeImmutable $activityCacheOldestAt;
    private bool $includesDev;

    private AdvisoryCoverage $advisoryCoverage;
    /**
     * @var list<Finding>|null the findings in the order of {@see sorted()}, once it is read. Every format of
     *                         a run reads the order, and each sort compares the scores of every finding.
     *                         A copy keeps it: the order reads the findings alone.
     */
    private ?array $sorted = null;
    private ?MoveCheckRun $moveCheck = null;
    private ?UpdateNow $updateNow = null;
    private ?PhpWindow $phpWindow = null;

    /**
     * @param list<Finding> $findings
     * @param list<RunNote> $notes    what the run could not see, in the order that the run noted it
     */
    public function __construct(array $findings, array $notes, \DateTimeImmutable $generatedAt, int $packagesChecked, int $notFromComposerRepository, ?BaselineComparison $baseline = null, ?\DateTimeImmutable $activityCacheOldestAt = null, bool $includesDev = false, ?AdvisoryCoverage $advisoryCoverage = null)
    {
        // By name: the order is the input's to {@see sorted()}, and a run's order must not depend on it.
        usort($findings, static fn (Finding $a, Finding $b): int => strcmp($a->package(), $b->package()));
        $this->advisoryCoverage = $advisoryCoverage ?? AdvisoryCoverage::none();
        $this->findings = $findings;
        $this->notes = $notes;
        $this->generatedAt = $generatedAt;
        $this->packagesChecked = $packagesChecked;
        $this->notFromComposerRepository = $notFromComposerRepository;
        $this->baseline = $baseline;
        $this->activityCacheOldestAt = $activityCacheOldestAt;
        $this->includesDev = $includesDev;
    }

    /**
     * The order of {@see sorted()}: the keys of {@see ScoreModel::toArray()} `sort`. Descending keys
     * take the other finding's value, ascending keys their own.
     */
    public static function compareGraded(Finding $a, Finding $b): int
    {
        return [self::verdictGroup($a), $b->score()->securityHalves(), $b->score()->exactHalves(), $b->isDirect()]
            <=> [self::verdictGroup($b), $a->score()->securityHalves(), $a->score()->exactHalves(), $a->isDirect()]
            ?: strcmp($a->package(), $b->package());
    }

    private static function verdictGroup(Finding $finding): int
    {
        foreach (ScoreModel::VERDICT_ORDER as $group => $verdicts) {
            if (\in_array($finding->verdict(), $verdicts, true)) {
                return $group;
            }
        }

        throw new \LogicException(\sprintf('%s has a verdict outside the verdict order: %s', $finding->package(), $finding->verdict()));
    }

    /** Returns a copy: a caller that handed the report on keeps the report it handed over. */
    public function withBaseline(BaselineComparison $baseline): self
    {
        $copy = new self(
            $this->findings,
            $this->notes,
            $this->generatedAt,
            $this->packagesChecked,
            $this->notFromComposerRepository,
            $baseline,
            $this->activityCacheOldestAt,
            $this->includesDev,
            $this->advisoryCoverage
        );
        $copy->notes = array_merge($this->notes, $baseline->notes());
        $copy->run = $this->run;
        $copy->sorted = $this->sorted;
        $copy->moveCheck = $this->moveCheck;
        $copy->updateNow = $this->updateNow;
        $copy->phpWindow = $this->phpWindow;

        return $copy;
    }

    public function baseline(): ?BaselineComparison
    {
        return $this->baseline;
    }

    /** Returns a copy, like {@see self::withBaseline()}. */
    public function withRun(RunSettings $run): self
    {
        $copy = new self(
            $this->findings,
            $this->notes,
            $this->generatedAt,
            $this->packagesChecked,
            $this->notFromComposerRepository,
            $this->baseline,
            $this->activityCacheOldestAt,
            $this->includesDev,
            $this->advisoryCoverage
        );
        $copy->run = $run;
        $copy->sorted = $this->sorted;
        $copy->moveCheck = $this->moveCheck;
        $copy->updateNow = $this->updateNow;
        $copy->phpWindow = $this->phpWindow;

        return $copy;
    }

    /** A copy with how Composer's solver checked the moves, and the `update_now` that it solved, with its note. */
    public function withMoveChecks(MoveCheckRun $moveCheck, UpdateNow $updateNow): self
    {
        $copy = clone $this;
        $copy->moveCheck = $moveCheck;
        $copy->updateNow = $updateNow;
        $note = $updateNow->note();
        if ($note !== null) {
            $copy->notes[] = $note;
        }

        return $copy;
    }

    public function withNote(RunNote $note): self
    {
        $copy = clone $this;
        $copy->notes[] = $note;

        return $copy;
    }

    public function withPhpWindow(?PhpWindow $phpWindow): self
    {
        $copy = clone $this;
        $copy->phpWindow = $phpWindow;

        return $copy;
    }

    /** `run.php_window`, null when the window is unknowable or the run did not compute it. */
    public function phpWindow(): ?PhpWindow
    {
        return $this->phpWindow;
    }

    /** `run.move_check`, null when the run checked no move. */
    public function moveCheck(): ?MoveCheckRun
    {
        return $this->moveCheck;
    }

    /**
     * `security.update_now`: with checked moves, the members of {@see UpdateNow}; else the
     * vulnerable packages whose fix is a `composer update`, in report order.
     *
     * @return list<string>
     */
    public function updateNow(): array
    {
        return $this->updateNow === null ? Report2Root::updateNow($this->sorted()) : $this->updateNow->members();
    }

    /** @return list<string>|null `security.update_now_command`, null when it has no members or Composer refuses them together */
    public function updateNowCommand(): ?array
    {
        if ($this->updateNow !== null) {
            return $this->updateNow->command();
        }
        $members = Report2Root::updateNow($this->sorted());

        return $members === [] ? null : array_merge(['composer', 'update'], $members);
    }

    /** @return list<Finding> */
    public function findings(): array
    {
        return $this->findings;
    }

    /** @return list<Finding> every finding in the order of {@see compareGraded()} */
    public function sorted(): array
    {
        if ($this->sorted === null) {
            $sorted = $this->findings;
            usort($sorted, [self::class, 'compareGraded']);
            $this->sorted = $sorted;
        }

        return $this->sorted;
    }

    /** @return list<Finding> the findings with a grade, in the order of {@see sorted()} */
    public function graded(): array
    {
        return array_values(array_filter($this->sorted(), static fn (Finding $f): bool => $f->isGraded()));
    }

    /** @return array<string, int> the findings by {@see Finding::verdict()}, every verdict in sort order */
    public function byGrade(): array
    {
        $counts = array_fill_keys(array_merge(...ScoreModel::VERDICT_ORDER), 0);
        foreach ($this->findings as $finding) {
            ++$counts[$finding->verdict()];
        }

        return $counts;
    }

    /**
     * Transitive exposure by direct requirement, most first and then by name
     * (docs/verdicts.md#transitive-exposure). {@see TransitiveExposure::attributable()} decides
     * which findings count, the rule that S7 uses.
     *
     * @return array<string, int> parent => attributable packages reachable from it
     */
    public function exposure(): array
    {
        $counts = [];
        foreach ($this->findings as $finding) {
            if (!TransitiveExposure::attributable($finding)) {
                continue;
            }
            foreach ($finding->directDependents() as $parent) {
                $counts[$parent] = ($counts[$parent] ?? 0) + 1;
            }
        }
        // Ties sort by strcmp, the byte order that chainsTo() sorts by.
        uksort($counts, static function (string $a, string $b) use ($counts): int {
            return $counts[$b] <=> $counts[$a] ?: strcmp($a, $b);
        });

        return $counts;
    }

    /** Root `rot_depth`, counted from the findings on every call. */
    public function rotDepth(): RotDepth
    {
        return RotDepth::of($this->sorted());
    }

    /** Derived from the findings on every call, so the block cannot disagree with them. */
    public function libyears(): Libyears
    {
        return Libyears::fromFindings($this->findings);
    }

    /**
     * Decided on every call, over the findings, the baseline comparison and the network failures,
     * so it cannot go stale when a baseline is attached. Null without a run. A run without a
     * fail-on gates on `none`.
     */
    public function gate(): ?Gate
    {
        if ($this->run === null) {
            return null;
        }

        return Gate::decide($this, $this->run->failOn() ?? FailOn::none(), $this->run->strictNetwork(), $this->run->mode());
    }

    public function includesDev(): bool
    {
        return $this->includesDev;
    }

    /** @return list<string> each note's sentence, which every format prints */
    public function notes(): array
    {
        return array_map(static fn (RunNote $note): string => $note->text(), $this->notes);
    }

    /** @return list<RunNote> */
    public function runNotes(): array
    {
        return $this->notes;
    }

    public function generatedAt(): \DateTimeImmutable
    {
        return $this->generatedAt;
    }
    public function packagesChecked(): int
    {
        return $this->packagesChecked;
    }
    public function notFromComposerRepository(): int
    {
        return $this->notFromComposerRepository;
    }
    public function hadNetworkFailures(): bool
    {
        foreach ($this->notes as $note) {
            if ($note->setsNetworkFailures()) {
                return true;
            }
        }

        return false;
    }

    public function activityCacheOldestAt(): ?\DateTimeImmutable
    {
        return $this->activityCacheOldestAt;
    }

    /**
     * The footer's data sources. When every answer was fetched in this run, it is the plain pair.
     * Else it gives the age of the oldest cached activity answer in whole hours, rounded up and
     * never below one. A minutes-old answer and a clock that runs backwards both read as one hour.
     * The age passes the cache lifetime ({@see \Lockrot\Data\Forge\ActivityClient::CACHE_TTL}, in
     * seconds) after a failed refetch or under `--offline`. The table and markdown footers share it.
     */
    public function dataSourcesClause(): string
    {
        if ($this->activityCacheOldestAt === null) {
            return 'package repositories, repository hosts';
        }
        $seconds = $this->generatedAt->getTimestamp() - $this->activityCacheOldestAt->getTimestamp();

        return \sprintf('package repositories; repository activity from lockrot\'s cache, up to %d h old', max(1, (int) ceil($seconds / 3600)));
    }

    /**
     * {@see exposure()} as a list, so the JSON document always encodes it as an array, never as an
     * object keyed by parent.
     *
     * @return list<array{package: string, flagged: int}>
     */
    private function exposureList(): array
    {
        $list = [];
        foreach ($this->exposure() as $parent => $count) {
            $list[] = ['package' => $parent, 'flagged' => $count];
        }

        return $list;
    }

    /** @return list<array{package: string, verdict: string, lead: ?string, flag_ids: list<string>, fan_in: int}> */
    private function unattributedList(): array
    {
        $list = [];
        foreach ($this->sorted() as $finding) {
            if (TransitiveExposure::sharedAboveCap($finding)) {
                $list[] = ['package' => $finding->package(), 'verdict' => $finding->verdict(), 'lead' => $finding->lead(), 'flag_ids' => $finding->flagIds(), 'fan_in' => \count($finding->directDependents())];
            }
        }

        return $list;
    }

    /** How the advisory lookup of the run went, counted over the findings that the report writes. */
    public function advisoryLookup(): AdvisoryLookup
    {
        return AdvisoryLookup::of($this->advisoryCoverage, $this->sorted());
    }

    /** The run settings the report was told, null when nothing told it. */
    public function run(): ?RunSettings
    {
        return $this->run;
    }

    /**
     * report-2's finding objects in the order of {@see sorted()}, each with its rank, baseline and
     * gate, keyed by package name.
     *
     * @return array<string, array<string, mixed>>
     */
    public function findingRows(?Gate $gate = null): array
    {
        $gate ??= $this->gate();
        $rows = [];
        foreach ($this->sorted() as $at => $finding) {
            $baseline = $this->baseline === null ? null : $this->baseline->standingOf($finding->package());
            $row = $finding->toArray($baseline);
            $lead = array_search('lead', array_keys($row), true) + 1;
            $rows[$finding->package()] = \array_slice($row, 0, $lead, true) + ['rank' => $at + 1] + \array_slice($row, $lead, null, true) + [
                'baseline' => $baseline,
                'gate' => ($gate === null ? null : $gate->standingOf($finding->package())) ?? new GateStanding(false, false, null),
            ];
        }

        return $rows;
    }

    /**
     * report-2 without `$schema` and `lockrot`, which the writer adds. Findings come in the order of
     * {@see sorted()}, each with its rank. A finding's gate is the report-1 gate's standing, so the
     * document agrees with the exit code.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $gate = $this->gate();
        $sorted = $this->sorted();
        $run = $this->run ?? new RunSettings(null, null, null, RunSettings::SOURCE_RUNTIME, null, null, RunSettings::SOURCE_DEFAULT, null);
        $graded = array_filter($this->findings, static fn (Finding $f): bool => $f->isGraded());
        $multi = array_filter($graded, static fn (Finding $f): bool => \count($f->flagIds()) >= 2);
        $oldest = $this->activityCacheOldestAt;

        return [
            'generated_at' => $this->generatedAt->format(\DATE_ATOM),
            'run' => $run->toArray(ScoreRulesUsed::of($sorted), $this->includesDev, $this->advisoryLookup(), $this->moveCheck, $this->phpWindow),
            'activity_cache_oldest_at' => $oldest === null ? null : $oldest->format(\DATE_ATOM),
            'activity_cache_age_hours' => $oldest === null ? null : round(max(0, $this->generatedAt->getTimestamp() - $oldest->getTimestamp()) / 3600, 1),
            'packages_checked' => $this->packagesChecked,
            'packages_flagged' => \count($graded),
            'packages_multi_flag' => \count($multi),
            'include_dev' => $this->includesDev,
            'not_from_composer_repository' => $this->notFromComposerRepository,
            'packages_unchecked_accepted' => \count(array_filter($this->findings, static fn (Finding $f): bool => $f->details()->uncheckedAccepted() !== [])),
            'network_failures' => $this->hadNetworkFailures(),
            'counts' => $this->byGrade(),
            'abandoned' => Report2Root::toAbandonedArray($sorted),
            'priorities' => Report2Root::toPrioritiesArray($sorted),
            'flags' => FlagTotals::of($sorted),
            'exposure' => $this->exposureList(),
            'exposure_rule' => ['max_fan_in' => TransitiveExposure::MAX_FAN_IN],
            'unattributed' => $this->unattributedList(),
            'rot_depth' => $this->rotDepth(),
            'libyears' => Report2Root::toLibyearsArray($this->libyears(), $sorted),
            'baseline' => $this->baseline === null ? null : $this->baseline->toArray(),
            'gate' => ($gate ?? Gate::ofFindings($this, FailOn::none()))->toArray($run),
            'security' => SecurityTotals::of($sorted, $this->updateNow(), $this->updateNowCommand()),
            'data_date' => Report2Root::dataDate($sorted),
            'notes' => $this->notes(),
            'note_details' => array_map(static fn (RunNote $note): array => $note->toArray(), $this->notes),
            'findings' => array_values($this->findingRows($gate)),
        ];
    }
}
