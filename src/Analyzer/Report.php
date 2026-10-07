<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Lockrot\Baseline\BaselineComparison;
use Lockrot\Config\Gate;
use Lockrot\Signal\Signal;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Priority;
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
     * Descending keys take the other finding's value, ascending keys their own. Public so
     * {@see TransitiveExposure} lists a parent's descendants in the same order.
     */
    public static function compare(Finding $a, Finding $b): int
    {
        return [Priority::rank($b->priority()), Verdict::severity($b->verdict()), $b->isDirect(), $a->package()]
            <=> [Priority::rank($a->priority()), Verdict::severity($a->verdict()), $a->isDirect(), $b->package()];
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
        return array_values(array_filter($this->findings, static fn (Finding $f): bool => Verdict::flagged($f->verdict())));
    }

    /** @return array<string, int> */
    public function byVerdict(): array
    {
        $counts = array_fill_keys(Verdict::all(), 0);
        foreach ($this->findings as $finding) {
            ++$counts[$finding->verdict()];
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
        $counts = array_fill_keys(Priority::all(), 0);
        foreach ($this->findings as $finding) {
            ++$counts[$finding->priority()];
        }

        return $counts;
    }

    /**
     * Transitive exposure by direct requirement, most first and then by name
     * (docs/verdicts.md#transitive-exposure). {@see TransitiveExposure::attributable()} decides
     * which findings count, the rule that S7 uses, so this number equals the number on S7.
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
            if (Verdict::flagged($finding->verdict())) {
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
        foreach ([Priority::CRITICAL, Priority::HIGH, Priority::MEDIUM, Priority::LOW] as $level) {
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

    /** @return list<array{package: string, verdict: string, fan_in: int}> */
    private function unattributedList(): array
    {
        $list = [];
        foreach ($this->findings as $finding) {
            if (TransitiveExposure::sharedAboveCap($finding)) {
                $list[] = ['package' => $finding->package(), 'verdict' => $finding->verdict(), 'fan_in' => \count($finding->directDependents())];
            }
        }

        return $list;
    }

    /**
     * Null when the run read no baseline or the baseline has nothing on this package.
     *
     * @return array{status: string, previous_verdict: ?string}|null
     */
    private function baselineStateOf(Finding $finding): ?array
    {
        if ($this->baseline === null) {
            return null;
        }
        $status = $this->baseline->statusOf($finding->package());

        return $status === null ? null : [
            'status' => $status,
            'previous_verdict' => $this->baseline->previousVerdictOf($finding->package()),
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $counts = $this->byVerdict();
        $gate = $this->gate();
        $standings = $gate === null ? null : $gate->standings();

        return [
            'generated_at' => $this->generatedAt->format(\DATE_ATOM),
            'run' => $this->run === null ? null : $this->run->toArray(),
            'activity_cache_oldest_at' => $this->activityCacheOldestAt === null ? null : $this->activityCacheOldestAt->format(\DATE_ATOM),
            'packages_checked' => $this->packagesChecked,
            'include_dev' => $this->includesDev,
            'not_from_composer_repository' => $this->notFromComposerRepository,
            'network_failures' => $this->hadNetworkFailures(),
            'counts' => $counts,
            'abandoned' => ['total' => $counts[Verdict::ABANDONED], 'with_replacement' => $this->abandonedWithReplacement()],
            'priorities' => $this->byPriority(),
            'exposure' => $this->exposureList(),
            'exposure_rule' => ['max_fan_in' => TransitiveExposure::MAX_FAN_IN],
            'unattributed' => $this->unattributedList(),
            'libyears' => $this->libyears()->toArray(),
            'baseline' => $this->baseline === null ? null : $this->baseline->toArray(),
            'gate' => $gate === null ? null : $gate->toArray(),
            'notes' => $this->notes(),
            'note_details' => array_map(static fn (RunNote $note): array => $note->toArray(), $this->notes),
            'findings' => array_map(
                fn (Finding $f, int $at): array => $f->toArray() + [
                    'baseline' => $this->baselineStateOf($f),
                    'gate' => $standings === null ? null : $standings[$at]->toArray(),
                ],
                $this->findings,
                array_keys($this->findings)
            ),
        ];
    }
}
