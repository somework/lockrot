<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Baseline\Baseline;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Baseline\BaselineEntry;
use Lockrot\Config\LockrotConfig;
use Lockrot\Output\FormatContext;
use Lockrot\Output\TableFormatter;
use Lockrot\Signal\Signal;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter;

final class TableFormatterTest extends TestCase
{
    private const AT = '2026-09-14T06:00:00+00:00';

    private function formatter(int $width = 120): TableFormatter
    {
        return new TableFormatter(FormatContext::create(null, LockrotConfig::FAIL_ON_NONE, '0.1.0', $width));
    }

    /** The text a terminal shows: every style tag resolved away, every `\<` escape undone. */
    private function plain(string $out): string
    {
        $plain = (new OutputFormatter(false))->format($out);
        self::assertIsString($plain);

        return $plain;
    }

    /** @return list<string> */
    private function plainLines(string $out): array
    {
        return explode("\n", rtrim($this->plain($out), "\n"));
    }

    /**
     * One row per priority level plus two unflagged ones, so every group header and every label
     * style has a row to sit on.
     */
    private function report(): Report
    {
        $at = new \DateTimeImmutable(self::AT);

        return new Report([
            new Finding('doctrine/cache', '2.2.0', Verdict::ABANDONED, [new Signal('S1', 'high', 'flagged abandoned by its repository'), new Signal('S2', 'high', 'last release 2022-05-20 (4.3 years ago)')], ['doctrine/cache'], null, $at),
            new Finding('hoa/compiler', '3.17.08.08', Verdict::ABANDONED, [new Signal('S1', 'high', 'flagged abandoned by its repository'), new Signal('S4', 'high', 'repository archived on GitHub; last push 2021-04-29 (5.4 years ago)')], ['wallabag/rulerz', 'hoa/ruler', 'hoa/compiler'], null, $at),
            new Finding('vendor/stale-direct', '2.1.0', Verdict::STALE, [new Signal('S2', 'warn', 'last release 2022-05-20 (4.3 years ago)')], ['vendor/stale-direct'], null, $at),
            new Finding('vendor/stale-deep', '1.0.0', Verdict::STALE, [new Signal('S2', 'warn', 'last release 2022-01-04 (4.7 years ago)')], ['vendor/root', 'vendor/stale-deep'], null, $at),
            new Finding('psr/cache', '3.0.0', Verdict::FINISHED, [], ['psr/cache'], 'interfaces', $at),
            new Finding('vendor/ok', '1.0.0', Verdict::OK, [], ['vendor/ok'], null, $at),
        ], ['GitHub token not set: repository activity checked only for 2 candidate packages (0 skipped); set GITHUB_TOKEN to check all'], $at, 6, 0, false);
    }

    public function testGroupsComeInPriorityOrderWithTheirCountInTheHeader(): void
    {
        $lines = $this->plainLines($this->formatter()->format($this->report()));
        $headers = array_values(array_filter($lines, static fn (string $l): bool => preg_match('/^\S.*\(\d+\)$/', $l) === 1));

        self::assertSame(['critical (1)', 'high (1)', 'medium (1)', 'low (1)'], $headers);
    }

    public function testOnlyNonEmptyGroupsGetAHeader(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([
            new Finding('vendor/stale-deep', '1.0.0', Verdict::STALE, [new Signal('S2', 'warn', 'last release 2022-01-04 (4.7 years ago)')], ['vendor/root', 'vendor/stale-deep'], null, $at),
        ], [], $at, 1, 0, false);
        $out = $this->plain($this->formatter()->format($report));

        self::assertStringContainsString('low (1)', $out);
        foreach (['critical (', 'high (', 'medium (', 'not flagged ('] as $absent) {
            self::assertStringNotContainsString($absent, $out);
        }
    }

    public function testShowAllAddsTheNotFlaggedGroupLast(): void
    {
        $lines = $this->plainLines($this->formatter()->format($this->report(), true));
        $headers = array_values(array_filter($lines, static fn (string $l): bool => preg_match('/^\S.*\(\d+\)$/', $l) === 1));

        self::assertSame(['critical (1)', 'high (1)', 'medium (1)', 'low (1)', 'not flagged (2)'], $headers);
    }

    public function testOneBlankLineSeparatesGroupsAndTheSummaryButNoneComesFirst(): void
    {
        $lines = $this->plainLines($this->formatter()->format($this->report()));

        self::assertSame('critical (1)', $lines[0]);
        $blanks = array_keys($lines, '', true);
        // three between the four groups, one before the summary block
        self::assertCount(4, $blanks);
        foreach ($blanks as $index) {
            self::assertNotSame('', $lines[$index - 1], 'no two blank lines in a row');
        }
    }

    public function testARowPutsTheLabelPackageVersionAndChainOnOneLineAndIndentsTheEvidence(): void
    {
        $lines = $this->plainLines($this->formatter()->format($this->report()));

        // label width is the 11-character minimum here, so the indent is 2 + 11 + 2 = 15
        self::assertSame('  abandoned    doctrine/cache 2.2.0  direct', $lines[1]);
        self::assertSame('               flagged abandoned by its repository; last release 2022-05-20 (4.3 years ago)', $lines[2]);
        self::assertSame('', $lines[3]);
        self::assertSame('high (1)', $lines[4]);
        self::assertSame('  abandoned    hoa/compiler 3.17.08.08  via wallabag/rulerz › hoa/ruler', $lines[5]);
    }

    public function testAnUnplaceablePackageShowsAQuestionMarkInsteadOfAChain(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([
            new Finding('vendor/orphan', '1.0.0', Verdict::PINNED, [new Signal('S6', 'warn', 'pinned to branch snapshot dev-master')], [], null, $at),
        ], [], $at, 1, 0, false);

        self::assertStringContainsString('  pinned       vendor/orphan 1.0.0  ?', $this->plain($this->formatter()->format($report)));
    }

    public function testTheLabelColumnGrowsWithTheLongestLabel(): void
    {
        $lines = $this->plainLines($this->formatter()->format($this->baselinedReport()));
        $row = null;
        foreach ($lines as $line) {
            if (strpos($line, 'hoa/compiler') !== false) {
                $row = $line;
            }
        }
        self::assertNotNull($row);
        // "abandoned (was stale)" is 21 characters, so every label is padded to 21 and the indent is 25
        self::assertSame('  abandoned (baseline)   doctrine/cache 2.2.0  direct', $lines[1]);
        self::assertSame('  abandoned (was stale)  hoa/compiler 3.17.08.08  via wallabag/rulerz › hoa/ruler', $row);
    }

    /**
     * Every rendered row of every group: everything above the blank line that separates the last
     * group from the summary block. Only the rows are wrapped — the summary block is one line per
     * fact by design, the way it has always been, and a terminal soft-wraps those itself — so the
     * blank lines *between* groups have to stay inside the region, which means slicing at the last
     * one rather than the first.
     *
     * @return list<string>
     */
    private function rowRegion(string $out): array
    {
        $lines = $this->plainLines($out);
        $blanks = array_keys($lines, '', true);
        self::assertNotSame([], $blanks, 'the summary block is always preceded by a blank line');

        return \array_slice($lines, 0, $blanks[\count($blanks) - 1]);
    }

    public function testEvidenceAndTheFirstLineAreWrappedToTheTerminalWidth(): void
    {
        $out = $this->formatter(60)->format($this->report(), true);
        $rows = $this->rowRegion($out);

        // every row of every group, not just the first one: the tail of line 1 only wraps on a row
        // with a long chain, and those sit below the critical group
        foreach (['critical (1)', 'high (1)', 'medium (1)', 'low (1)', 'not flagged (2)'] as $header) {
            self::assertContains($header, $rows, 'the measured region has to span every group');
        }
        foreach ($rows as $line) {
            self::assertLessThanOrEqual(60, \strlen($line), 'line wider than the terminal: '.$line);
        }
        // the chain on line 1 wrapped, and the continuation carries the 15-character indent
        self::assertStringContainsString(
            "  abandoned    hoa/compiler 3.17.08.08  via wallabag/rulerz\n".str_repeat(' ', 15)."› hoa/ruler\n",
            $this->plain($out)
        );
        // and so did the evidence underneath it
        self::assertStringContainsString(
            str_repeat(' ', 15)."flagged abandoned by its repository; last\n".str_repeat(' ', 15).'release 2022-05-20',
            $this->plain($out)
        );
    }

    /**
     * Baseline annotations push the label column to 21, so the indent is 25 and the narrowest
     * terminal lockrot accepts would leave 15 columns for the text. The floor takes over at 20 and
     * the row overruns the terminal, rather than the text being squeezed into nothing.
     */
    public function testAVeryNarrowTerminalStillLeavesTwentyColumnsForTheText(): void
    {
        $out = (new TableFormatter(FormatContext::create(null, LockrotConfig::FAIL_ON_NONE, '0.1.0', 40)))->format($this->baselinedReport());
        $rows = $this->rowRegion($out);

        self::assertStringContainsString('doctrine/cache', implode("\n", $rows));
        foreach ($rows as $line) {
            self::assertLessThanOrEqual(25 + 20, \strlen($line), 'line wider than indent plus the wrap floor: '.$line);
        }
        self::assertNotSame(
            [],
            array_filter($rows, static fn (string $line): bool => \strlen($line) > 40),
            'the floor, not the terminal width, is what decided the wrap here'
        );
    }

    public function testCriticalAndHighLabelsAreRedMediumIsYellowAndLowIsUnstyled(): void
    {
        $out = $this->formatter()->format($this->report(), true);

        self::assertStringContainsString('<fg=red>abandoned</fg=red>', $out);
        self::assertStringContainsString('<fg=yellow>stale</fg=yellow>', $out);
        self::assertStringNotContainsString('<fg=red>stale</fg=red>', $out);
        self::assertStringNotContainsString('<fg=yellow>abandoned</fg=yellow>', $out);
        // the low row and the unflagged rows carry no colour at all
        self::assertSame(2, substr_count($out, '<fg=red>'));
        self::assertSame(1, substr_count($out, '<fg=yellow>'));
        self::assertStringContainsString('<options=bold>critical (1)</options=bold>', $out);
        self::assertStringNotContainsString('</>', $out);
    }

    /**
     * A php constraint is the one piece of evidence that routinely looks like a console tag. The
     * text has to come out of a *decorated* formatter byte-for-byte, styles aside: the label is
     * coloured, the constraint is not touched.
     */
    public function testAPhpConstraintInEvidenceSurvivesADecoratedFormatter(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $evidence = 'released 2017-05-02, before PHP 8.4 GA (2024-11-21); php constraint ">=7.2" has no upper bound; sibling pins "<8.0"';
        $report = new Report([
            new Finding('vendor/constraint', '1.0.0', Verdict::OLD_PROMISE, [new Signal('S5', 'warn', $evidence)], ['vendor/constraint'], null, $at),
        ], [], $at, 1, 0, false);

        $raw = (new TableFormatter(FormatContext::create(null, LockrotConfig::FAIL_ON_NONE, '0.1.0', 200)))->format($report);

        foreach ([new OutputFormatter(true), new OutputFormatter(false)] as $formatter) {
            $rendered = $formatter->format($raw);
            self::assertIsString($rendered);
            self::assertStringContainsString($evidence, $rendered);
            self::assertStringContainsString('>=7.2', $rendered);
            self::assertStringContainsString('<8.0', $rendered);
        }
    }

    public function testAPackageNameThatLooksLikeATagIsEscaped(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([
            new Finding('vendor/<info>weird', '1.0.0', Verdict::ABANDONED, [new Signal('S1', 'high', 'flagged abandoned by its repository')], ['vendor/<info>weird'], null, $at),
        ], ['note with <comment> in it'], $at, 1, 0, false);

        $out = (new TableFormatter(FormatContext::create(null, LockrotConfig::FAIL_ON_NONE, '0.1.0', 200)))->format($report);

        self::assertStringContainsString('vendor/<info>weird', $this->plain($out));
        self::assertStringContainsString('note with <comment> in it', $this->plain($out));
        // and the raw string carries symfony's own escaping of them, whichever form this console
        // version produces — 5.4 escapes `>` as well as `<`, 2.8 only `<`
        self::assertStringContainsString(OutputFormatter::escape('vendor/<info>weird'), $out);
        self::assertStringContainsString(OutputFormatter::escape('note with <comment> in it'), $out);
        self::assertStringNotContainsString('vendor/<info>weird', $out);
    }

    /** Rendered wide enough that no summary line folds, so the order can be asserted line for line. */
    public function testTheSummaryBlockKeepsItsOrderWithThePriorityLineAfterTheCounts(): void
    {
        $lines = $this->plainLines($this->formatter(200)->format($this->report()));
        $tail = \array_slice($lines, -4);

        self::assertSame('6 packages checked · abandoned 2 · silent 0 · pinned 0 · old-promise 0 · stale 2 · unknown 0 · finished 1 · ok 1', $tail[0]);
        self::assertSame('priority: critical 1 · high 1 · medium 1 · low 1', $tail[1]);
        self::assertSame('Data as of 2026-09-14 (package repositories, GitHub). Run composer lockrot --format=json for details.', $tail[2]);
        self::assertSame('note: GitHub token not set: repository activity checked only for 2 candidate packages (0 skipped); set GITHUB_TOKEN to check all', $tail[3]);
    }

    /**
     * The summary block is part of a width-aware report too: its lines fold at the full width with
     * no indent, since each is a fact of its own rather than a continuation hanging under a label.
     */
    public function testTheSummaryBlockIsWrappedToTheTerminalWidth(): void
    {
        $lines = $this->plainLines($this->formatter(60)->format($this->report()));

        foreach ($lines as $line) {
            self::assertLessThanOrEqual(60, \strlen($line), 'line wider than the terminal: '.$line);
        }
        // the counts line and the note folded, and each continuation starts in column 1
        $plain = implode("\n", $lines);
        self::assertStringContainsString("6 packages checked · abandoned 2 · silent 0 · pinned 0 ·\nold-promise 0 ·", $plain);
        self::assertStringContainsString("note: GitHub token not set: repository activity checked only\nfor 2 candidate packages", $plain);
    }

    public function testCleanReport(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([new Finding('vendor/ok', '1.0.0', Verdict::OK, [], ['vendor/ok'], null, $at)], [], $at, 1, 0, false);
        $lines = $this->plainLines($this->formatter()->format($report));

        self::assertSame('No dependency rot found in 1 packages.', $lines[0]);
        // the same blank line a grouped report puts before its summary block
        self::assertSame('', $lines[1]);
        self::assertStringContainsString(' packages checked', $lines[2]);
        // and no `priority: critical 0 · high 0 · medium 0 · low 0` under it
        self::assertStringStartsWith('Data as of ', $lines[3]);
    }

    /**
     * The priority totals count flagged findings, so a report with none has nothing to total. Under
     * --all the rows are still listed, in their `not flagged` group, and the line is still absent.
     */
    public function testThePriorityTotalsAppearOnlyWhenSomethingIsFlagged(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $clean = new Report([
            new Finding('vendor/ok', '1.0.0', Verdict::OK, [], ['vendor/ok'], null, $at),
            new Finding('psr/cache', '3.0.0', Verdict::FINISHED, [], ['psr/cache'], 'interfaces', $at),
        ], [], $at, 2, 0, false);

        self::assertStringNotContainsString('priority:', $this->plain($this->formatter()->format($clean)));
        self::assertStringNotContainsString('priority:', $this->plain($this->formatter()->format($clean, true)));
        self::assertStringContainsString('not flagged (2)', $this->plain($this->formatter()->format($clean, true)));
        self::assertStringContainsString('priority:', $this->plain($this->formatter()->format($this->report())));
    }

    /**
     * The report is written straight to the output with no trailing newline of its own, so the
     * document has to end with one or the shell prompt lands on the last line.
     */
    public function testEveryRenderedReportEndsWithASingleNewline(): void
    {
        foreach ([$this->formatter()->format($this->report()), $this->formatter()->format($this->report(), true)] as $out) {
            self::assertStringEndsWith("\n", $out);
            self::assertStringEndsNotWith("\n\n", $out);
        }
    }

    /**
     * wordwrap() breaks at the first of a run of spaces and leaves the rest at the end of the line,
     * so the two-space gutter before `direct` would otherwise trail invisibly off several rows.
     */
    public function testNoRenderedLineEndsWithWhitespace(): void
    {
        foreach ([40, 60, 120] as $width) {
            foreach ($this->plainLines($this->formatter($width)->format($this->report(), true)) as $line) {
                self::assertSame(rtrim($line), $line, 'trailing whitespace at width '.$width.': "'.$line.'"');
            }
        }
    }

    public function testWordingAvoidsBannedTerms(): void
    {
        $out = strtolower($this->plain($this->formatter()->format($this->allVerdictsReport(), true)));
        foreach (['vulnerable', 'broken', 'insecure', 'dead'] as $banned) {
            self::assertStringNotContainsString($banned, $out);
        }
    }

    /**
     * The report of {@see report()} compared against a baseline that knows doctrine/cache as
     * abandoned, vendor/stale-direct as worse than it was, and one package that has since left the
     * lock.
     */
    private function baselinedReport(): Report
    {
        $report = $this->report();
        $names = [];
        foreach ($report->findings() as $finding) {
            $names[] = $finding->package();
        }
        $baseline = Baseline::of([
            new BaselineEntry('doctrine/cache', '2.2.0', Verdict::ABANDONED, '2026-01-15'),
            new BaselineEntry('hoa/compiler', '3.17.08.08', Verdict::STALE, '2026-01-15'),
            new BaselineEntry('vendor/departed', '1.0.0', Verdict::SILENT, '2026-01-15'),
        ], '2026-09-14T06:00:00+00:00');

        return $report->withBaseline(BaselineComparison::compare($baseline, $report, 'lockrot-baseline.json', $names));
    }

    public function testBaselineSummaryLineFollowsThePriorityLine(): void
    {
        $lines = $this->plainLines($this->formatter()->format($this->baselinedReport()));
        $index = null;
        foreach ($lines as $i => $line) {
            if (strpos($line, 'priority: ') === 0) {
                $index = $i;
            }
        }

        self::assertNotNull($index);
        self::assertSame(
            'baseline: 1 known · 2 new · 1 worsened · 1 stale (lockrot-baseline.json)',
            $lines[$index + 1]
        );
    }

    public function testBaselineStaleEntriesBecomeANote(): void
    {
        $out = $this->plain($this->formatter()->format($this->baselinedReport()));

        self::assertStringContainsString(
            'note: baseline lists 1 package no longer in composer.lock: vendor/departed',
            $out
        );
    }

    public function testTheLabelMarksKnownAndWorsenedRows(): void
    {
        $out = $this->plain($this->formatter()->format($this->baselinedReport()));

        self::assertStringContainsString('abandoned (baseline)', $out);
        self::assertStringContainsString('abandoned (was stale)', $out);
    }

    public function testWithoutABaselineTheLabelIsTheBareVerdict(): void
    {
        $out = $this->plain($this->formatter()->format($this->report()));

        self::assertStringNotContainsString('(baseline)', $out);
        self::assertStringNotContainsString('baseline:', $out);
    }

    public function testAllowlistedRowsSayWhy(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([
            new Finding('vendor/allowed', '1.0.0', Verdict::ABANDONED, [new Signal('S1', 'high', 'flagged abandoned by its repository')], ['vendor/allowed'], 'replaced upstream', $at),
        ], [], $at, 1, 0, false);

        self::assertStringContainsString(
            'flagged abandoned by its repository; allowlisted: replaced upstream',
            $this->plain($this->formatter()->format($report))
        );
    }

    private function allVerdictsReport(): Report
    {
        $at = new \DateTimeImmutable(self::AT);
        $findings = array_merge($this->report()->findings(), [
            new Finding('vendor/pinned', '1.2.3', Verdict::PINNED, [new Signal('S6', 'warn', 'pinned to branch snapshot dev-master')], ['vendor/pinned'], null, $at),
            new Finding('vendor/old-promise', '0.9.0', Verdict::OLD_PROMISE, [new Signal('S5', 'warn', 'released 2015-11-16, before PHP 8.4 GA (2024-11-21); php constraint ">=5.3.0" has no upper bound')], ['vendor/old-promise'], null, $at),
            new Finding('vendor/silent', '2.0.8', Verdict::SILENT, [new Signal('S2', 'high', 'last release 2015-11-16 (10.8 years ago)')], ['vendor/root', 'vendor/silent'], null, $at),
            new Finding('vendor/unknown', '1.0.0', Verdict::UNKNOWN, [new Signal('S3', 'info', 'not from a Composer repository, not checked')], ['vendor/unknown'], null, $at),
        ]);

        return new Report($findings, $this->report()->notes(), $at, \count($findings), 1, false);
    }
}
