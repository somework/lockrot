<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Baseline;

use Lockrot\Analyzer\Report;
use Lockrot\Baseline\Baseline;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Baseline\BaselineEntry;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\TestCase;

final class BaselineComparisonTest extends TestCase
{
    private const AT = '2026-09-14T00:00:00+00:00';
    private const PATH = 'lockrot-baseline.json';

    /** @param list<array{0: string, 1: string, 2: string}> $rows package, version, verdict */
    private function report(array $rows): Report
    {
        $at = new \DateTimeImmutable(self::AT);
        $findings = [];
        foreach ($rows as [$package, $version, $verdict]) {
            $findings[] = new Finding($package, $version, $verdict, [], [$package], null, $at);
        }

        return new Report($findings, [], $at, \count($findings), 0, false);
    }

    /** @param list<array{0: string, 1: string, 2: string, 3: string}> $rows package, version, verdict, first seen */
    private function baseline(array $rows): Baseline
    {
        $entries = [];
        foreach ($rows as [$package, $version, $verdict, $firstSeen]) {
            $entries[] = new BaselineEntry($package, $version, $verdict, $firstSeen);
        }

        return Baseline::of($entries, self::AT);
    }

    /** @param list<string> $present */
    private function compare(Baseline $baseline, Report $report, ?array $present = null): BaselineComparison
    {
        $names = $present;
        if ($names === null) {
            $names = [];
            foreach ($report->findings() as $finding) {
                $names[] = $finding->package();
            }
        }

        return BaselineComparison::compare($baseline, $report, self::PATH, $names);
    }

    public function testAFindingAtTheBaselinedVerdictIsKnown(): void
    {
        $comparison = $this->compare(
            $this->baseline([['acme/abandoned', '1.0.0', Verdict::ABANDONED, '2026-01-15']]),
            $this->report([['acme/abandoned', '1.0.0', Verdict::ABANDONED]])
        );

        self::assertTrue($comparison->isKnown('acme/abandoned'));
        self::assertSame(BaselineComparison::KNOWN, $comparison->statusOf('acme/abandoned'));
        self::assertSame(1, $comparison->knownCount());
        self::assertSame(0, $comparison->newCount());
        self::assertSame(0, $comparison->worsenedCount());
    }

    public function testMatchingIsByPackageNameOnlySoAVersionBumpStaysKnown(): void
    {
        $comparison = $this->compare(
            $this->baseline([['acme/abandoned', '1.0.0', Verdict::ABANDONED, '2026-01-15']]),
            $this->report([['acme/abandoned', '9.9.9', Verdict::ABANDONED]])
        );

        self::assertTrue($comparison->isKnown('acme/abandoned'));
        self::assertSame(1, $comparison->knownCount());
    }

    public function testALowerSeverityThanTheBaselinedOneIsStillKnown(): void
    {
        $comparison = $this->compare(
            $this->baseline([['acme/pkg', '1.0.0', Verdict::ABANDONED, '2026-01-15']]),
            $this->report([['acme/pkg', '1.0.0', Verdict::STALE]])
        );

        self::assertSame(BaselineComparison::KNOWN, $comparison->statusOf('acme/pkg'));
    }

    public function testAFindingAbsentFromTheBaselineIsNew(): void
    {
        $comparison = $this->compare(
            $this->baseline([['acme/abandoned', '1.0.0', Verdict::ABANDONED, '2026-01-15']]),
            $this->report([
                ['acme/abandoned', '1.0.0', Verdict::ABANDONED],
                ['acme/fresh', '1.0.0', Verdict::SILENT],
            ])
        );

        self::assertFalse($comparison->isKnown('acme/fresh'));
        self::assertSame(BaselineComparison::NEW_FINDING, $comparison->statusOf('acme/fresh'));
        self::assertSame(1, $comparison->newCount());
        self::assertSame(1, $comparison->knownCount());
    }

    public function testAHigherSeverityThanTheBaselinedOneIsWorsened(): void
    {
        $comparison = $this->compare(
            $this->baseline([['acme/pkg', '1.0.0', Verdict::STALE, '2026-01-15']]),
            $this->report([['acme/pkg', '1.0.0', Verdict::ABANDONED]])
        );

        self::assertFalse($comparison->isKnown('acme/pkg'));
        self::assertSame(BaselineComparison::WORSENED, $comparison->statusOf('acme/pkg'));
        self::assertSame(Verdict::STALE, $comparison->previousVerdictOf('acme/pkg'));
        self::assertSame(1, $comparison->worsenedCount());
    }

    public function testBaselineEntriesNoLongerInTheLockAreStaleAndSorted(): void
    {
        $comparison = $this->compare(
            $this->baseline([
                ['zzz/gone', '1.0.0', Verdict::ABANDONED, '2026-01-15'],
                ['acme/gone', '1.0.0', Verdict::SILENT, '2026-01-15'],
                ['acme/here', '1.0.0', Verdict::STALE, '2026-01-15'],
            ]),
            $this->report([['acme/here', '1.0.0', Verdict::STALE]])
        );

        self::assertSame(['acme/gone', 'zzz/gone'], $comparison->stale());
    }

    public function testAnUnflaggedPackageIsNeitherKnownNorNew(): void
    {
        $comparison = $this->compare(
            $this->baseline([]),
            $this->report([['acme/fine', '1.0.0', Verdict::OK]])
        );

        self::assertNull($comparison->statusOf('acme/fine'));
        self::assertSame(0, $comparison->newCount());
    }

    public function testPresentPackagesDriveStalenessRatherThanTheFindingsList(): void
    {
        $comparison = $this->compare(
            $this->baseline([['acme/elsewhere', '1.0.0', Verdict::ABANDONED, '2026-01-15']]),
            $this->report([['acme/here', '1.0.0', Verdict::STALE]]),
            ['acme/here', 'acme/elsewhere']
        );

        self::assertSame([], $comparison->stale());
    }

    public function testToArrayShape(): void
    {
        $comparison = $this->compare(
            $this->baseline([
                ['acme/known', '1.0.0', Verdict::ABANDONED, '2026-01-15'],
                ['acme/worse', '1.0.0', Verdict::STALE, '2026-01-15'],
                ['acme/gone', '1.0.0', Verdict::SILENT, '2026-01-15'],
            ]),
            $this->report([
                ['acme/known', '1.0.0', Verdict::ABANDONED],
                ['acme/worse', '1.0.0', Verdict::SILENT],
                ['acme/fresh', '1.0.0', Verdict::PINNED],
            ])
        );

        self::assertSame([
            'path' => self::PATH,
            'known' => 1,
            'new' => 1,
            'worsened' => 1,
            'stale' => ['acme/gone'],
        ], $comparison->toArray());
    }

    public function testSummaryLine(): void
    {
        $comparison = $this->compare(
            $this->baseline([['acme/known', '1.0.0', Verdict::ABANDONED, '2026-01-15']]),
            $this->report([['acme/known', '1.0.0', Verdict::ABANDONED]])
        );

        self::assertSame(
            'baseline: 1 known · 0 new · 0 worsened · 0 stale (lockrot-baseline.json)',
            $comparison->summaryLine()
        );
    }

    public function testStaleNoteListsAtMostFiveNames(): void
    {
        $rows = [];
        for ($i = 1; $i <= 7; ++$i) {
            $rows[] = ['acme/gone'.$i, '1.0.0', Verdict::ABANDONED, '2026-01-15'];
        }
        $comparison = $this->compare($this->baseline($rows), $this->report([]));

        self::assertSame(
            'baseline lists 7 packages no longer in composer.lock: acme/gone1, acme/gone2, acme/gone3, acme/gone4, acme/gone5 … and 2 more',
            $comparison->staleNote()
        );
    }

    public function testNoStaleEntriesMeansNoNote(): void
    {
        $comparison = $this->compare(
            $this->baseline([['acme/known', '1.0.0', Verdict::ABANDONED, '2026-01-15']]),
            $this->report([['acme/known', '1.0.0', Verdict::ABANDONED]])
        );

        self::assertNull($comparison->staleNote());
    }

    public function testASingleStalePackageIsNamedInTheSingular(): void
    {
        $comparison = $this->compare(
            $this->baseline([['acme/gone', '1.0.0', Verdict::ABANDONED, '2026-01-15']]),
            $this->report([])
        );

        self::assertSame(
            'baseline lists 1 package no longer in composer.lock: acme/gone',
            $comparison->staleNote()
        );
    }
}
