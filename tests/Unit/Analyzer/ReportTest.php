<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Analyzer;

use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\TransitiveExposure;
use Lockrot\Baseline\Baseline;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Baseline\BaselineEntry;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Priority;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\TestCase;

final class ReportTest extends TestCase
{
    private function finding(string $package, string $verdict): Finding
    {
        return new Finding($package, '1.0.0', $verdict, [], [$package], null, null);
    }

    /** A transitive finding: the chain is a root plus the package itself, so isDirect() is false. */
    private function transitive(string $package, string $verdict, bool $dev = false): Finding
    {
        return new Finding($package, '1.0.0', $verdict, [], ['vendor/root', $package], null, null, null, $dev);
    }

    private function report(Finding ...$findings): Report
    {
        return new Report(array_values($findings), [], new \DateTimeImmutable('2026-09-14T00:00:00+00:00'), \count($findings), 0, false);
    }

    /** @return list<string> */
    private function names(Report $report): array
    {
        return array_map(static fn (Finding $f): string => $f->package(), $report->findings());
    }

    public function testFindingsAreSortedByPriorityThenSeverityThenDirectThenName(): void
    {
        // abandoned transitive dev -> medium; pinned direct prod -> high, so the pinned row sorts first
        // even though abandoned outranks pinned on verdict severity.
        $report = $this->report(
            $this->transitive('vendor/abandoned-transitive-dev', Verdict::ABANDONED, true),
            $this->finding('vendor/pinned-direct', Verdict::PINNED),
            $this->finding('vendor/abandoned-direct', Verdict::ABANDONED),
            $this->finding('vendor/ok', Verdict::OK)
        );

        self::assertSame(
            ['vendor/abandoned-direct', 'vendor/pinned-direct', 'vendor/abandoned-transitive-dev', 'vendor/ok'],
            $this->names($report)
        );
    }

    public function testWithinOnePriorityTheHigherVerdictSeverityComesFirst(): void
    {
        // Both are high: silent transitive prod and pinned direct prod. Silent (50) outranks pinned (40).
        $report = $this->report(
            $this->finding('vendor/pinned-direct', Verdict::PINNED),
            $this->transitive('vendor/silent-transitive', Verdict::SILENT)
        );

        self::assertSame(['vendor/silent-transitive', 'vendor/pinned-direct'], $this->names($report));
    }

    public function testWithinOnePriorityAndVerdictDirectComesBeforeTransitive(): void
    {
        // Both dev and stale, so both land on the `low` floor: the only thing left to order them by
        // is direct-before-transitive, which has to beat the alphabetical package name.
        $report = $this->report(
            $this->transitive('vendor/aaa-transitive', Verdict::STALE, true),
            new Finding('vendor/zzz-direct', '1.0.0', Verdict::STALE, [], ['vendor/zzz-direct'], null, null, null, true)
        );

        self::assertSame(['vendor/zzz-direct', 'vendor/aaa-transitive'], $this->names($report));
    }

    public function testBySeverityStillBreaksTiesByPackageNameAscending(): void
    {
        $report = new Report(
            [
                $this->finding('vendor/ok-b', Verdict::OK),
                $this->finding('vendor/silent-b', Verdict::SILENT),
                $this->finding('vendor/abandoned-b', Verdict::ABANDONED),
                $this->finding('vendor/silent-a', Verdict::SILENT),
                $this->finding('vendor/abandoned-a', Verdict::ABANDONED),
                $this->finding('vendor/ok-a', Verdict::OK),
            ],
            [],
            new \DateTimeImmutable('2026-09-14T00:00:00+00:00'),
            6,
            0,
            false
        );

        self::assertSame(
            ['vendor/abandoned-a', 'vendor/abandoned-b', 'vendor/silent-a', 'vendor/silent-b', 'vendor/ok-a', 'vendor/ok-b'],
            array_map(static fn (Finding $f): string => $f->package(), $report->findings())
        );
    }

    public function testFlaggedExcludesOkFinishedAndUnknown(): void
    {
        $report = new Report(
            [
                $this->finding('vendor/a', Verdict::ABANDONED),
                $this->finding('vendor/b', Verdict::SILENT),
                $this->finding('vendor/c', Verdict::PINNED),
                $this->finding('vendor/d', Verdict::OLD_PROMISE),
                $this->finding('vendor/e', Verdict::STALE),
                $this->finding('vendor/f', Verdict::UNKNOWN),
                $this->finding('vendor/g', Verdict::FINISHED),
                $this->finding('vendor/h', Verdict::OK),
            ],
            [],
            new \DateTimeImmutable('2026-09-14T00:00:00+00:00'),
            8,
            0,
            false
        );

        self::assertSame(
            ['vendor/a', 'vendor/b', 'vendor/c', 'vendor/d', 'vendor/e'],
            array_map(static fn (Finding $f): string => $f->package(), $report->flagged())
        );
    }

    public function testByVerdictHasAllNineKeysWithZeros(): void
    {
        $report = new Report([], [], new \DateTimeImmutable('2026-09-14T00:00:00+00:00'), 0, 0, false);
        self::assertSame(
            [
                Verdict::ABANDONED => 0,
                Verdict::SILENT => 0,
                Verdict::PINNED => 0,
                Verdict::LEFT_BEHIND => 0,
                Verdict::OLD_PROMISE => 0,
                Verdict::STALE => 0,
                Verdict::UNKNOWN => 0,
                Verdict::FINISHED => 0,
                Verdict::OK => 0,
            ],
            $report->byVerdict()
        );
    }

    public function testByVerdictCountsFindings(): void
    {
        $report = new Report(
            [$this->finding('vendor/a', Verdict::SILENT), $this->finding('vendor/b', Verdict::SILENT), $this->finding('vendor/c', Verdict::OK)],
            [],
            new \DateTimeImmutable('2026-09-14T00:00:00+00:00'),
            3,
            0,
            false
        );
        $counts = $report->byVerdict();
        self::assertSame(2, $counts[Verdict::SILENT]);
        self::assertSame(1, $counts[Verdict::OK]);
        self::assertSame(0, $counts[Verdict::ABANDONED]);
    }

    public function testByPriorityHasAllFiveKeysWithZeros(): void
    {
        $report = new Report([], [], new \DateTimeImmutable('2026-09-14T00:00:00+00:00'), 0, 0, false);
        self::assertSame(
            [
                Priority::CRITICAL => 0,
                Priority::HIGH => 0,
                Priority::MEDIUM => 0,
                Priority::LOW => 0,
                Priority::NONE => 0,
            ],
            $report->byPriority()
        );
    }

    public function testByPriorityCountsFindings(): void
    {
        $report = $this->report(
            $this->finding('vendor/a', Verdict::ABANDONED),
            $this->transitive('vendor/b', Verdict::ABANDONED),
            $this->transitive('vendor/c', Verdict::STALE),
            $this->finding('vendor/d', Verdict::OK)
        );
        self::assertSame(
            [Priority::CRITICAL => 1, Priority::HIGH => 1, Priority::MEDIUM => 0, Priority::LOW => 1, Priority::NONE => 1],
            $report->byPriority()
        );
    }

    public function testPrioritySummaryLineNamesTheFourFlaggedLevelsOnly(): void
    {
        $report = $this->report(
            $this->finding('vendor/a', Verdict::ABANDONED),
            $this->transitive('vendor/b', Verdict::ABANDONED),
            $this->finding('vendor/c', Verdict::STALE),
            $this->transitive('vendor/d', Verdict::STALE),
            $this->finding('vendor/e', Verdict::OK)
        );
        self::assertSame('priority: critical 1 · high 1 · medium 1 · low 1', $report->prioritySummaryLine());
    }

    public function testPrioritySummaryLineOnAnEmptyReport(): void
    {
        $report = new Report([], [], new \DateTimeImmutable('2026-09-14T00:00:00+00:00'), 0, 0, false);
        self::assertSame('priority: critical 0 · high 0 · medium 0 · low 0', $report->prioritySummaryLine());
    }

    public function testToArrayKeys(): void
    {
        $report = new Report(
            [$this->finding('vendor/a', Verdict::SILENT)],
            ['a note'],
            new \DateTimeImmutable('2026-09-14T00:00:00+00:00'),
            5,
            1,
            true
        );
        $array = $report->toArray();
        self::assertSame(
            ['generated_at', 'activity_cache_oldest_at', 'packages_checked', 'not_from_composer_repository', 'network_failures', 'counts', 'priorities', 'exposure', 'baseline', 'notes', 'findings'],
            array_keys($array)
        );
        self::assertIsArray($array['priorities']);
        self::assertSame(
            [Priority::CRITICAL, Priority::HIGH, Priority::MEDIUM, Priority::LOW, Priority::NONE],
            array_keys($array['priorities'])
        );
        self::assertSame(1, $array['priorities'][Priority::CRITICAL]);
        self::assertSame('2026-09-14T00:00:00+00:00', $array['generated_at']);
        self::assertNull($array['activity_cache_oldest_at']);
        self::assertSame(5, $array['packages_checked']);
        self::assertSame(1, $array['not_from_composer_repository']);
        self::assertTrue($array['network_failures']);
        self::assertNull($array['baseline']);
        self::assertSame(['a note'], $array['notes']);
        self::assertIsArray($array['findings']);
        self::assertCount(1, $array['findings']);
    }

    public function testWithBaselineLeavesTheOriginalReportUntouched(): void
    {
        $report = new Report(
            [$this->finding('vendor/a', Verdict::SILENT)],
            [],
            new \DateTimeImmutable('2026-09-14T00:00:00+00:00'),
            1,
            0,
            false
        );
        $comparison = BaselineComparison::compare(
            Baseline::of([new BaselineEntry('vendor/a', '1.0.0', Verdict::SILENT, '2026-01-15')], '2026-09-14T00:00:00+00:00'),
            $report,
            'lockrot-baseline.json',
            ['vendor/a']
        );

        $withBaseline = $report->withBaseline($comparison);

        self::assertNull($report->baseline());
        self::assertNotSame($report, $withBaseline);
        self::assertSame($comparison, $withBaseline->baseline());
        self::assertSame(
            ['path' => 'lockrot-baseline.json', 'known' => 1, 'new' => 0, 'worsened' => 0, 'stale' => []],
            $withBaseline->toArray()['baseline']
        );
        self::assertSame($report->summaryLine(), $withBaseline->summaryLine());
    }

    /** A finding reachable from the named roots; the chain starts at the first of them, or is empty without any. */
    private function reachedFrom(string $package, string $verdict, string ...$roots): Finding
    {
        $chain = $roots === [] ? [] : ($roots[0] === $package ? [$package] : [$roots[0], $package]);

        return new Finding($package, '1.0.0', $verdict, [], $chain, null, null, null, false, array_values($roots));
    }

    public function testExposureCountsFlaggedFindingsPerParentMostFirstThenByName(): void
    {
        $report = $this->report(
            $this->reachedFrom('vendor/a', Verdict::ABANDONED, 'root/one', 'root/two'),
            $this->reachedFrom('vendor/b', Verdict::STALE, 'root/two'),
            $this->reachedFrom('vendor/c', Verdict::SILENT, 'root/three'),
            // Direct, and also reached through another root: the project's own choice, counted nowhere.
            $this->reachedFrom('root/three', Verdict::PINNED, 'root/three', 'root/one'),
            // Unflagged rows never count, whoever pulls them in.
            $this->reachedFrom('vendor/ok', Verdict::OK, 'root/one'),
            $this->reachedFrom('vendor/unreached', Verdict::ABANDONED)
        );
        self::assertSame(['root/two' => 2, 'root/one' => 1, 'root/three' => 1], $report->exposure());
        self::assertSame('pulled in by: root/two 2 · root/one 1 · root/three 1', $report->exposureSummaryLine());
        self::assertSame(
            [['package' => 'root/two', 'flagged' => 2], ['package' => 'root/one', 'flagged' => 1], ['package' => 'root/three', 'flagged' => 1]],
            $report->toArray()['exposure']
        );
    }

    public function testExposureIsEmptyWhenNothingFlaggedIsReachedThroughAnotherPackage(): void
    {
        $report = $this->report($this->reachedFrom('vendor/a', Verdict::ABANDONED, 'vendor/a'), $this->finding('vendor/b', Verdict::OK));
        self::assertSame([], $report->exposure());
        self::assertSame('', $report->exposureSummaryLine());
        self::assertSame([], $report->toArray()['exposure']);
    }

    public function testExposureSummaryLineNamesFiveParentsThenCountsTheRest(): void
    {
        $findings = [];
        for ($i = 1; $i <= 7; ++$i) {
            $findings[] = $this->reachedFrom(\sprintf('vendor/p%02d', $i), Verdict::STALE, \sprintf('root/r%02d', $i));
        }
        $line = $this->report(...$findings)->exposureSummaryLine();
        self::assertStringStartsWith('pulled in by: root/r01 1 · root/r02 1 · ', $line);
        self::assertStringEndsWith(' · root/r05 1 · … and 2 more', $line);
        self::assertStringNotContainsString('root/r06', $line);
    }

    public function testExposureLeavesOutAPackageReachedFromMoreRootsThanTheCap(): void
    {
        $many = [];
        for ($i = 1; $i <= TransitiveExposure::MAX_FAN_IN + 1; ++$i) {
            $many[] = \sprintf('root/r%02d', $i);
        }
        $report = $this->report(
            $this->reachedFrom('vendor/shared', Verdict::ABANDONED, ...$many),
            $this->reachedFrom('vendor/leaf', Verdict::STALE, 'root/r01')
        );

        self::assertSame(['root/r01' => 1], $report->exposure());
        self::assertSame('pulled in by: root/r01 1', $report->exposureSummaryLine());
    }

    public function testExposureSummaryLineWithExactlyFiveParentsNamesThemAllAndCountsNothing(): void
    {
        $findings = [];
        for ($i = 1; $i <= 5; ++$i) {
            $findings[] = $this->reachedFrom(\sprintf('vendor/p%02d', $i), Verdict::STALE, \sprintf('root/r%02d', $i));
        }

        self::assertSame('pulled in by: root/r01 1 · root/r02 1 · root/r03 1 · root/r04 1 · root/r05 1', $this->report(...$findings)->exposureSummaryLine());
    }

    public function testExposureKeepsAPackageReachedFromExactlyAsManyRootsAsTheCap(): void
    {
        $roots = [];
        for ($i = 1; $i <= TransitiveExposure::MAX_FAN_IN; ++$i) {
            $roots[] = \sprintf('root/r%02d', $i);
        }
        $report = $this->report($this->reachedFrom('vendor/shared', Verdict::ABANDONED, ...$roots));

        self::assertSame(array_fill_keys($roots, 1), $report->exposure());
    }

    /**
     * The footer's source clause: the plain pair when every activity answer was fetched in this
     * run, otherwise the age of the oldest cached one in whole hours rounded up, never below one.
     */
    public function testTheDataSourcesClauseStatesTheAgeOfCachedActivity(): void
    {
        $at = new \DateTimeImmutable('2026-09-14T12:00:00+00:00');
        $report = static fn (?string $oldest): Report => new Report([], [], $at, 0, 0, false, null, $oldest === null ? null : new \DateTimeImmutable($oldest));

        self::assertSame('package repositories, repository hosts', $report(null)->dataSourcesClause());
        self::assertSame("package repositories; repository activity from lockrot's cache, up to 1 h old", $report('2026-09-14T11:30:00+00:00')->dataSourcesClause(), '30 minutes rounds up to one hour');
        self::assertSame("package repositories; repository activity from lockrot's cache, up to 1 h old", $report('2026-09-14T12:00:00+00:00')->dataSourcesClause(), 'fetched this second, still at least one hour');
        self::assertSame("package repositories; repository activity from lockrot's cache, up to 23 h old", $report('2026-09-13T13:00:00+00:00')->dataSourcesClause(), 'exactly 23 hours');
        self::assertSame("package repositories; repository activity from lockrot's cache, up to 24 h old", $report('2026-09-13T12:59:00+00:00')->dataSourcesClause(), '23 hours and a minute rounds up to 24');
        self::assertSame("package repositories; repository activity from lockrot's cache, up to 1 h old", $report('2026-09-14T11:00:00+00:00')->dataSourcesClause(), 'exactly one hour is one hour');
        self::assertSame("package repositories; repository activity from lockrot's cache, up to 2 h old", $report('2026-09-14T10:59:59+00:00')->dataSourcesClause(), 'one second past the hour rounds up');
        self::assertSame("package repositories; repository activity from lockrot's cache, up to 1 h old", $report('2026-09-14T13:00:00+00:00')->dataSourcesClause(), 'a clock that ran backwards is not a negative age');
        self::assertSame("package repositories; repository activity from lockrot's cache, up to 3601 h old", $report('2026-04-17T11:00:00+00:00')->dataSourcesClause(), 'an --offline run serves the cache however old it is; 3601 hours here');
    }

    public function testTheCacheDateIsCarriedIntoTheArrayAndAcrossWithBaseline(): void
    {
        $at = new \DateTimeImmutable('2026-09-14T12:00:00+00:00');
        $oldest = new \DateTimeImmutable('2026-09-13T20:00:00+00:00');
        $report = new Report([], [], $at, 0, 0, false, null, $oldest);

        self::assertSame($oldest, $report->activityCacheOldestAt());
        self::assertSame('2026-09-13T20:00:00+00:00', $report->toArray()['activity_cache_oldest_at']);
        $withBaseline = $report->withBaseline(BaselineComparison::compare(Baseline::of([], '2026-09-14T00:00:00+00:00'), $report, 'lockrot-baseline.json', []));
        self::assertSame($oldest, $withBaseline->activityCacheOldestAt());
    }
}
