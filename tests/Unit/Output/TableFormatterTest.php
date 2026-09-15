<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Baseline\Baseline;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Baseline\BaselineEntry;
use Lockrot\Output\TableFormatter;
use Lockrot\Signal\Signal;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\TestCase;

final class TableFormatterTest extends TestCase
{
    private function report(): Report
    {
        $at = new \DateTimeImmutable('2026-09-14T06:00:00+00:00');
        return new Report([
            new Finding('phpzip/phpzip', '2.0.8', Verdict::SILENT, [new Signal('S2', 'high', 'last release 2015-11-16 (10.8 years ago)'), new Signal('S4', 'high', 'last push 2015-11-16 (10.8 years ago)')], ['grandt/phpepub', 'phpzip/phpzip'], null, $at),
            new Finding('doctrine/cache', '1.13.0', Verdict::ABANDONED, [new Signal('S1', 'high', 'flagged abandoned by its repository')], ['doctrine/cache'], null, $at),
            new Finding('psr/cache', '3.0.0', Verdict::FINISHED, [], ['psr/cache'], 'interfaces', $at),
            new Finding('vendor/ok', '1.0.0', Verdict::OK, [], ['vendor/ok'], null, $at),
        ], ['GitHub token not set: repository activity checked only for 2 candidate packages (0 skipped); set GITHUB_TOKEN to check all'], $at, 4, 0, false);
    }

    public function testFlaggedRowsSummaryAndNotes(): void
    {
        $out = (new TableFormatter())->format($this->report());
        self::assertStringContainsString('doctrine/cache', $out);
        self::assertStringContainsString('phpzip/phpzip', $out);
        self::assertStringContainsString('grandt/phpepub', $out);
        self::assertStringContainsString('direct', $out);
        self::assertStringNotContainsString('vendor/ok', $out);
        self::assertStringNotContainsString('psr/cache', $out);
        self::assertStringContainsString('4 packages checked', $out);
        self::assertStringContainsString('abandoned 1', $out);
        self::assertStringContainsString('finished 1', $out);
        self::assertStringContainsString('Data as of 2026-09-14', $out);
        self::assertStringContainsString('note: GitHub token not set', $out);
        self::assertLessThan(strpos($out, 'phpzip/phpzip'), strpos($out, 'doctrine/cache'), 'abandoned sorts before silent');
    }

    public function testShowAllIncludesOkRows(): void
    {
        $out = (new TableFormatter())->format($this->report(), true);
        self::assertStringContainsString('vendor/ok', $out);
        self::assertStringContainsString('psr/cache', $out);
    }

    public function testCleanReport(): void
    {
        $report = new Report([new Finding('vendor/ok', '1.0.0', Verdict::OK, [], ['vendor/ok'], null, new \DateTimeImmutable('2026-09-14T00:00:00+00:00'))], [], new \DateTimeImmutable('2026-09-14T00:00:00+00:00'), 1, 0, false);
        $out = (new TableFormatter())->format($report);
        self::assertStringContainsString('No dependency rot found in 1 packages', $out);
    }

    public function testWordingAvoidsBannedTerms(): void
    {
        $out = strtolower((new TableFormatter())->format($this->allVerdictsReport(), true));
        foreach (['vulnerable', 'broken', 'insecure', 'dead'] as $banned) {
            self::assertStringNotContainsString($banned, $out);
        }
    }

    /**
     * The four-finding report of {@see report()} compared against a baseline that knows
     * doctrine/cache as abandoned, phpzip/phpzip as only stale (so it is worsened now) and one
     * package that has since left the lock.
     */
    private function baselinedReport(): Report
    {
        $report = $this->report();
        $names = [];
        foreach ($report->findings() as $finding) {
            $names[] = $finding->package();
        }
        $baseline = Baseline::of([
            new BaselineEntry('doctrine/cache', '1.13.0', Verdict::ABANDONED, '2026-01-15'),
            new BaselineEntry('phpzip/phpzip', '2.0.8', Verdict::STALE, '2026-01-15'),
            new BaselineEntry('vendor/departed', '1.0.0', Verdict::SILENT, '2026-01-15'),
        ], '2026-09-14T06:00:00+00:00');

        return $report->withBaseline(BaselineComparison::compare($baseline, $report, 'lockrot-baseline.json', $names));
    }

    public function testBaselineSummaryLineFollowsTheCountsLine(): void
    {
        $out = (new TableFormatter())->format($this->baselinedReport());
        $lines = array_values(array_filter(explode("\n", $out), static fn (string $line): bool => $line !== ''));
        $index = null;
        foreach ($lines as $i => $line) {
            if (strpos($line, ' packages checked') !== false) {
                $index = $i;
            }
        }

        self::assertNotNull($index);
        self::assertSame(
            'baseline: 1 known · 0 new · 1 worsened · 1 stale (lockrot-baseline.json)',
            $lines[$index + 1]
        );
    }

    public function testBaselineStaleEntriesBecomeANote(): void
    {
        $out = (new TableFormatter())->format($this->baselinedReport());

        self::assertStringContainsString(
            'note: baseline lists 1 package no longer in composer.lock: vendor/departed',
            $out
        );
    }

    public function testVerdictColumnMarksKnownAndWorsenedRows(): void
    {
        $out = (new TableFormatter())->format($this->baselinedReport());

        self::assertStringContainsString('abandoned (baseline)', $out);
        self::assertStringContainsString('silent (was stale)', $out);
    }

    public function testWithoutABaselineTheVerdictColumnIsUnchanged(): void
    {
        $out = (new TableFormatter())->format($this->report());

        self::assertStringNotContainsString('(baseline)', $out);
        self::assertStringNotContainsString('baseline:', $out);
    }

    private function allVerdictsReport(): Report
    {
        $at = new \DateTimeImmutable('2026-09-14T06:00:00+00:00');
        $findings = array_merge($this->report()->findings(), [
            new Finding('vendor/pinned', '1.2.3', Verdict::PINNED, [new Signal('S6', 'warn', 'pinned to branch snapshot dev-master')], ['vendor/pinned'], null, $at),
            new Finding('vendor/old-promise', '0.9.0', Verdict::OLD_PROMISE, [new Signal('S5', 'warn', 'released 2015-11-16, before PHP 8.4 GA (2024-11-21); php constraint ">=5.3.0" has no upper bound')], ['vendor/old-promise'], null, $at),
            new Finding('vendor/stale', '2.1.0', Verdict::STALE, [new Signal('S2', 'warn', 'last release 2022-05-20 (4.3 years ago)')], ['vendor/stale'], null, $at),
            new Finding('vendor/unknown', '1.0.0', Verdict::UNKNOWN, [new Signal('S3', 'info', 'not from a Composer repository, not checked')], ['vendor/unknown'], null, $at),
        ]);

        return new Report($findings, $this->report()->notes(), $at, \count($findings), 1, false);
    }
}
