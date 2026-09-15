<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Analyzer;

use Lockrot\Analyzer\Report;
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

    public function testByVerdictHasAllEightKeysWithZeros(): void
    {
        $report = new Report([], [], new \DateTimeImmutable('2026-09-14T00:00:00+00:00'), 0, 0, false);
        self::assertSame(
            [
                Verdict::ABANDONED => 0,
                Verdict::SILENT => 0,
                Verdict::PINNED => 0,
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
            ['generated_at', 'packages_checked', 'not_from_composer_repository', 'network_failures', 'counts', 'priorities', 'baseline', 'notes', 'findings'],
            array_keys($array)
        );
        self::assertIsArray($array['priorities']);
        self::assertSame(
            [Priority::CRITICAL, Priority::HIGH, Priority::MEDIUM, Priority::LOW, Priority::NONE],
            array_keys($array['priorities'])
        );
        self::assertSame(1, $array['priorities'][Priority::CRITICAL]);
        self::assertSame('2026-09-14T00:00:00+00:00', $array['generated_at']);
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
}
