<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Lockrot\Baseline\BaselineComparison;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Priority;
use Lockrot\Verdict\Verdict;

final class Report
{
    /** Parents named on the `pulled in by:` line before the rest is counted. */
    public const EXPOSURE_NAMES = 5;

    /** @var list<Finding> */
    private array $findings;
    /** @var list<string> */
    private array $notes;
    private \DateTimeImmutable $generatedAt;
    private int $packagesChecked;
    private int $notFromComposerRepository;
    private bool $hadNetworkFailures;
    /** Null when the project has no baseline file, which is every run until one is generated. */
    private ?BaselineComparison $baseline;
    /**
     * When the oldest repository-activity answer served from lockrot's cache was fetched — usually
     * within the last day, older after a failed refetch fell back to a stale entry or under
     * `--offline`; null when every answer was fetched in this run (or none was needed). Repository metadata is
     * revalidated on every run, so this is the one source whose age the report has to state.
     */
    private ?\DateTimeImmutable $activityCacheOldestAt;

    /**
     * @param list<Finding> $findings
     * @param list<string> $notes
     */
    public function __construct(array $findings, array $notes, \DateTimeImmutable $generatedAt, int $packagesChecked, int $notFromComposerRepository, bool $hadNetworkFailures, ?BaselineComparison $baseline = null, ?\DateTimeImmutable $activityCacheOldestAt = null)
    {
        usort($findings, [self::class, 'compare']);
        $this->findings = $findings;
        $this->notes = $notes;
        $this->generatedAt = $generatedAt;
        $this->packagesChecked = $packagesChecked;
        $this->notFromComposerRepository = $notFromComposerRepository;
        $this->hadNetworkFailures = $hadNetworkFailures;
        $this->baseline = $baseline;
        $this->activityCacheOldestAt = $activityCacheOldestAt;
    }

    /**
     * The report's order: priority first, then the verdict's own severity, then direct dependencies
     * ahead of transitive ones, then the package name — a total order, so the report reads the same
     * way on every run. Descending keys take the other finding's value, ascending ones take their
     * own. Public so the transitive-exposure pass lists a parent's descendants the same way.
     */
    public static function compare(Finding $a, Finding $b): int
    {
        return [Priority::rank($b->priority()), Verdict::severity($b->verdict()), $b->isDirect(), $a->package()]
            <=> [Priority::rank($a->priority()), Verdict::severity($a->verdict()), $a->isDirect(), $b->package()];
    }

    /**
     * The same report, seen next to the project's baseline. A new instance rather than a mutation,
     * so a caller that already handed the report somewhere else keeps the report it handed over.
     */
    public function withBaseline(BaselineComparison $baseline): self
    {
        return new self(
            $this->findings,
            $this->notes,
            $this->generatedAt,
            $this->packagesChecked,
            $this->notFromComposerRepository,
            $this->hadNetworkFailures,
            $baseline,
            $this->activityCacheOldestAt
        );
    }

    public function baseline(): ?BaselineComparison
    {
        return $this->baseline;
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
     * Transitive exposure by direct requirement: each root require that pulls in a flagged
     * transitive package, with how many, most first and then by name. Which findings count is
     * {@see TransitiveExposure::attributable()} — the rule S7 uses, so the number here is the number
     * on the parent's signal. A flagged package the project requires directly is its own
     * responsibility and counts under nobody, and so is one reached from more direct requirements
     * than anyone could remove. Derived from the findings' {@see Finding::directDependents()}, so it
     * is as complete as the analysed set — the whole lock for `composer lockrot`, the transaction at
     * install time.
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
        // Count descending, then name ascending — strcmp, the same byte order chainsTo() sorts by.
        uksort($counts, static function (string $a, string $b) use ($counts): int {
            return $counts[$b] <=> $counts[$a] ?: strcmp($a, $b);
        });

        return $counts;
    }

    /**
     * `pulled in by: acme/a 16 · acme/b 3`, naming at most
     * {@see EXPOSURE_NAMES} parents before counting the rest — in a framework application a
     * transitive core package is reached from every bundle, and the long tail of equal counts that
     * makes is what `--format=json` is for; the empty string when no flagged package is transitive.
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

    /** @return list<string> */
    public function notes(): array
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
        return $this->hadNetworkFailures;
    }

    public function activityCacheOldestAt(): ?\DateTimeImmutable
    {
        return $this->activityCacheOldestAt;
    }

    /**
     * How the footer describes the sources behind the report: the plain pair when everything was
     * fetched in this run, otherwise how old the oldest cached activity answer is, in whole hours
     * rounded up and never below one (a minutes-old answer and a clock that ran backwards both read
     * as one hour). A fresh hit is under a day old, so this usually reads up to 24; it goes past
     * that when a refetch failed and lockrot fell back to a stale entry, or when `--offline` served
     * the cache however old it was. The wording is shared by the table and markdown footers.
     */
    public function dataSourcesClause(): string
    {
        if ($this->activityCacheOldestAt === null) {
            return 'package repositories, repository hosts';
        }
        $seconds = $this->generatedAt->getTimestamp() - $this->activityCacheOldestAt->getTimestamp();

        return \sprintf('package repositories; repository activity from lockrot\'s cache, up to %d h old', max(1, (int) ceil($seconds / 3600)));
    }

    /** One-line totals, e.g. `200 packages checked · abandoned 19 · silent 1 · …`, shared by the table and GitHub formats. */
    public function summaryLine(): string
    {
        $parts = [\sprintf('%d packages checked', $this->packagesChecked)];
        foreach ($this->byVerdict() as $verdict => $count) {
            $parts[] = $verdict.' '.$count;
        }

        return implode(' · ', $parts);
    }

    /**
     * The four flagged levels, e.g. `priority: critical 2 · high 12 · medium 5 · low 3`. `none` is left
     * out: it counts the rows the report does not flag, which {@see summaryLine()} already totals.
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
     * {@see exposure()} as a list of objects, so an empty one encodes as `[]` rather than as a
     * PHP array that would encode as `[]` when empty and as an object otherwise.
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

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'generated_at' => $this->generatedAt->format(\DATE_ATOM),
            'activity_cache_oldest_at' => $this->activityCacheOldestAt === null ? null : $this->activityCacheOldestAt->format(\DATE_ATOM),
            'packages_checked' => $this->packagesChecked,
            'not_from_composer_repository' => $this->notFromComposerRepository,
            'network_failures' => $this->hadNetworkFailures,
            'counts' => $this->byVerdict(),
            'priorities' => $this->byPriority(),
            'exposure' => $this->exposureList(),
            'baseline' => $this->baseline === null ? null : $this->baseline->toArray(),
            'notes' => $this->notes,
            'findings' => array_map(static fn (Finding $f): array => $f->toArray(), $this->findings),
        ];
    }
}
