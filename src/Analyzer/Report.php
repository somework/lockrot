<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Lockrot\Baseline\BaselineComparison;
use Lockrot\Config\Gate;
use Lockrot\Legacy\Compare013;
use Lockrot\Legacy\Priority013;
use Lockrot\Legacy\Verdict013;
use Lockrot\Signal\Signal;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\ScoreModel;
use Lockrot\Verdict\Verdict;

/** @internal */
final class Report
{
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

    /**
     * @param list<Finding> $findings
     * @param list<RunNote> $notes    what the run could not see, in the order that the run noted it
     */
    public function __construct(array $findings, array $notes, \DateTimeImmutable $generatedAt, int $packagesChecked, int $notFromComposerRepository, ?BaselineComparison $baseline = null, ?\DateTimeImmutable $activityCacheOldestAt = null, bool $includesDev = false)
    {
        usort($findings, [self::class, 'compare']);
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
     * The order of {@see findings()} and {@see flagged()}. Public so {@see TransitiveExposure} lists a
     * parent's descendants in the same order.
     */
    public static function compare(Finding $a, Finding $b): int
    {
        return Compare013::compare($a, $b);
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
            if (\in_array($finding->grade(), $verdicts, true)) {
                return $group;
            }
        }

        throw new \LogicException(\sprintf('%s has a verdict outside the verdict order: %s', $finding->package(), $finding->grade()));
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
            $this->includesDev
        );
        $copy->run = $this->run;

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
            $this->includesDev
        );
        $copy->run = $run;

        return $copy;
    }

    /** @return list<Finding> */
    public function findings(): array
    {
        return $this->findings;
    }

    /** @return list<Finding> */
    public function flagged(): array
    {
        return array_values(array_filter($this->findings, static fn (Finding $f): bool => Verdict013::flagged($f->verdict())));
    }

    /** @return list<Finding> every finding in the order of {@see compareGraded()} */
    public function sorted(): array
    {
        $sorted = $this->findings;
        usort($sorted, [self::class, 'compareGraded']);

        return $sorted;
    }

    /** @return list<Finding> the findings with a grade, in the order of {@see sorted()} */
    public function graded(): array
    {
        return array_values(array_filter($this->sorted(), static fn (Finding $f): bool => $f->isGraded()));
    }

    /** @return array<string, int> */
    public function byVerdict(): array
    {
        $counts = array_fill_keys(Verdict013::all(), 0);
        foreach ($this->findings as $finding) {
            ++$counts[$finding->verdict()];
        }

        return $counts;
    }

    /** @return array<string, int> the findings by {@see Finding::grade()}, every verdict in sort order */
    public function byGrade(): array
    {
        $counts = array_fill_keys(array_merge(...ScoreModel::VERDICT_ORDER), 0);
        foreach ($this->findings as $finding) {
            ++$counts[$finding->grade()];
        }

        return $counts;
    }

    /**
     * The `abandoned` findings that name a package to move to ({@see Finding::successor()}):
     * docs/verdicts.md#abandoned-and-where-to.
     */
    public function abandonedWithReplacement(): int
    {
        $count = 0;
        foreach ($this->findings as $finding) {
            if ($finding->successor() !== null) {
                ++$count;
            }
        }

        return $count;
    }

    /** @return array<string, int> */
    public function byPriority(): array
    {
        $counts = array_fill_keys(Priority013::all(), 0);
        foreach ($this->findings as $finding) {
            ++$counts[$finding->priority()];
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

    /**
     * The `pulled in by:` line, empty when {@see exposure()} is empty:
     * docs/verdicts.md#transitive-exposure.
     */
    public function exposureSummaryLine(): string
    {
        $exposure = $this->exposure();
        if ($exposure === []) {
            return '';
        }
        $parts = [];
        foreach (\array_slice($exposure, 0, self::EXPOSURE_NAMES, true) as $parent => $count) {
            $parts[] = $parent.' '.$count;
        }
        if (\count($exposure) > self::EXPOSURE_NAMES) {
            $parts[] = \sprintf('… and %d more', \count($exposure) - self::EXPOSURE_NAMES);
        }

        return 'pulled in by: '.implode(' · ', $parts);
    }

    /**
     * The footer line for S9 advisories on packages that the report does not flag, empty when there
     * are none. A footer that totals every verdict and omits them reads as "nothing to report".
     * Without `--dev` the line says that `composer audit` counts `packages-dev` too, so the two
     * totals differ by scope, not by a miss in one tool.
     */
    public function unflaggedAdvisoriesLine(): string
    {
        $packages = 0;
        $advisories = 0;
        foreach ($this->findings as $finding) {
            if (Verdict013::flagged($finding->verdict())) {
                continue;
            }
            foreach ($finding->signals() as $signal) {
                if ($signal->id() === Signal::S9) {
                    ++$packages;
                    $advisories += \count((array) ($signal->data()['advisories'] ?? []));
                }
            }
        }
        if ($packages === 0) {
            return '';
        }

        return \sprintf(
            '%d security %s on %d %s the report does not flag; see composer audit%s',
            $advisories,
            $advisories === 1 ? 'advisory' : 'advisories',
            $packages,
            $packages === 1 ? 'package' : 'packages',
            $this->includesDev ? '' : ' (it counts packages-dev too, which this run skipped; pass --dev to include them)'
        );
    }

    /** Derived from the findings on every call, so the block cannot disagree with them. */
    public function libyears(): Libyears
    {
        return Libyears::fromFindings($this->findings);
    }

    /**
     * Decided on every call, over the findings, the baseline comparison and the network failures,
     * so it cannot go stale when a baseline is attached. Null without a run or without a fail-on.
     */
    public function gate(): ?Gate
    {
        if ($this->run === null) {
            return null;
        }
        $failOn = $this->run->failOn();

        return $failOn === null ? null : Gate::decide($this, $failOn, $this->run->strictNetwork(), $this->run->mode());
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
     * One-line totals, shared by the table and GitHub formats. The replacement count shows only
     * when it is not zero.
     */
    public function summaryLine(): string
    {
        $parts = [\sprintf('%d packages checked', $this->packagesChecked)];
        $withReplacement = $this->abandonedWithReplacement();
        foreach ($this->byVerdict() as $verdict => $count) {
            $parts[] = $verdict.' '.$count.($verdict === Verdict::ABANDONED && $withReplacement > 0 ? \sprintf(' (%d with a replacement)', $withReplacement) : '');
        }

        return implode(' · ', $parts);
    }

    /**
     * The four flagged priorities. `none` is omitted: it counts the unflagged rows, which
     * {@see summaryLine()} totals.
     */
    public function prioritySummaryLine(): string
    {
        $counts = $this->byPriority();
        $parts = [];
        foreach ([Priority013::CRITICAL, Priority013::HIGH, Priority013::MEDIUM, Priority013::LOW] as $level) {
            $parts[] = $level.' '.$counts[$level];
        }

        return 'priority: '.implode(' · ', $parts);
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
        foreach ($this->findings as $finding) {
            if (TransitiveExposure::sharedAboveCap($finding)) {
                $list[] = ['package' => $finding->package(), 'verdict' => $finding->grade(), 'lead' => $finding->lead(), 'flag_ids' => $finding->flagIds(), 'fan_in' => \count($finding->directDependents())];
            }
        }

        return $list;
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
        $standings = [];
        foreach ($gate === null ? [] : $gate->standings() as $at => $standing) {
            $standings[$this->findings[$at]->package()] = $standing;
        }
        $rows = [];
        foreach ($this->sorted() as $at => $finding) {
            $row = $finding->toArray();
            $standing = $standings[$finding->package()] ?? null;
            $lead = array_search('lead', array_keys($row), true) + 1;
            $rows[$finding->package()] = \array_slice($row, 0, $lead, true) + ['rank' => $at + 1] + \array_slice($row, $lead, null, true) + [
                'baseline' => null,
                'gate' => ['reaches_fail_on' => $standing !== null && $standing->reachesFailOn(), 'fails' => $standing !== null && $standing->fails(), 'exempt_by' => $standing === null ? null : $standing->exemptBy(), 'by' => [], 'basis' => null],
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
        $findings = array_values($this->findingRows($gate));
        $run = $this->run ?? new RunSettings(null, null, null, RunSettings::SOURCE_RUNTIME, null, null, RunSettings::SOURCE_DEFAULT, null);
        $graded = array_filter($this->findings, static fn (Finding $f): bool => $f->isGraded());
        $multi = array_filter($graded, static fn (Finding $f): bool => \count($f->flagIds()) >= 2);
        $flags = Report2Root::flags($findings);
        $oldest = $this->activityCacheOldestAt;

        return [
            'generated_at' => $this->generatedAt->format(\DATE_ATOM),
            'run' => $run->toArray(ScoreRulesUsed::of($findings), $this->includesDev),
            'activity_cache_oldest_at' => $oldest === null ? null : $oldest->format(\DATE_ATOM),
            'activity_cache_age_hours' => $oldest === null ? null : round(max(0, $this->generatedAt->getTimestamp() - $oldest->getTimestamp()) / 3600, 1),
            'packages_checked' => $this->packagesChecked,
            'packages_flagged' => \count($graded),
            'packages_multi_flag' => \count($multi),
            'include_dev' => $this->includesDev,
            'not_from_composer_repository' => $this->notFromComposerRepository,
            'network_failures' => $this->hadNetworkFailures(),
            'counts' => $this->byGrade(),
            'abandoned' => Report2Root::abandoned($findings, $flags),
            'priorities' => Report2Root::priorities($findings),
            'flags' => $flags,
            'exposure' => $this->exposureList(),
            'exposure_rule' => ['max_fan_in' => TransitiveExposure::MAX_FAN_IN],
            'unattributed' => $this->unattributedList(),
            'libyears' => Report2Root::libyears($this->libyears()->toArray(), $findings),
            'baseline' => $this->baseline === null ? null : $this->baseline->toArray(),
            'gate' => Report2Root::gate($gate === null ? ['fails' => false, 'tripped_by' => [], 'fail_on_applied' => $run->mode() === Gate::MODE_CHECK] : $gate->toArray(), $findings),
            'security' => Report2Root::security($findings),
            'data_date' => Report2Root::dataDate($findings),
            'notes' => $this->notes(),
            'note_details' => array_map(static fn (RunNote $note): array => $note->toArray(), $this->notes),
            'findings' => $findings,
        ];
    }
}
