<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Analyzer;

use Lockrot\Analyzer\Report;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\TestCase;

final class ReportTest extends TestCase
{
    private function finding(string $package, string $verdict): Finding
    {
        return new Finding($package, '1.0.0', $verdict, [], [$package], null, null);
    }

    public function testFindingsAreSortedBySeverityDescThenPackageAsc(): void
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
            ['generated_at', 'packages_checked', 'not_from_composer_repository', 'network_failures', 'counts', 'notes', 'findings'],
            array_keys($array)
        );
        self::assertSame('2026-09-14T00:00:00+00:00', $array['generated_at']);
        self::assertSame(5, $array['packages_checked']);
        self::assertSame(1, $array['not_from_composer_repository']);
        self::assertTrue($array['network_failures']);
        self::assertSame(['a note'], $array['notes']);
        self::assertIsArray($array['findings']);
        self::assertCount(1, $array['findings']);
    }
}
