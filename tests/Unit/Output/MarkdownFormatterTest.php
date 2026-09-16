<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Baseline\Baseline;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Baseline\BaselineEntry;
use Lockrot\Config\LockrotConfig;
use Lockrot\Exception\ConfigException;
use Lockrot\Output\FormatContext;
use Lockrot\Output\Formatters;
use Lockrot\Output\MarkdownFormatter;
use Lockrot\Signal\Signal;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\TestCase;

final class MarkdownFormatterTest extends TestCase
{
    private const AT = '2026-09-14T06:00:00+00:00';

    private function report(): Report
    {
        $at = new \DateTimeImmutable(self::AT);

        return new Report([
            new Finding('doctrine/cache', '1.13.0', Verdict::ABANDONED, [new Signal('S1', 'high', 'marked abandoned by its repository')], ['doctrine/cache'], null, $at),
            new Finding('phpzip/phpzip', '2.0.8', Verdict::SILENT, [new Signal('S2', 'high', 'last release 2015-11-16 (10.8 years ago)')], ['wallabag/wallabag', 'grandt/phpepub', 'phpzip/phpzip'], null, $at),
            new Finding('vendor/ok', '1.0.0', Verdict::OK, [], ['vendor/ok'], null, $at),
        ], ['GitHub token not set: repository activity checked only for 2 candidate packages'], $at, 3, 0, false);
    }

    private function formatter(string $failOn = LockrotConfig::FAIL_ON_NONE): MarkdownFormatter
    {
        return new MarkdownFormatter(FormatContext::create(null, $failOn));
    }

    public function testHeadingAndTable(): void
    {
        $out = $this->formatter()->format($this->report());

        self::assertStringContainsString('### lockrot: dependency rot in 2 of 3 packages', $out);
        self::assertStringContainsString('| Priority | Package | Version | Verdict | Evidence | Via |', $out);
        self::assertStringContainsString('|---|---|---|---|---|---|', $out);
        self::assertStringContainsString('| critical | `doctrine/cache` | 1.13.0 |', $out);
        self::assertStringNotContainsString('vendor/ok', $out);
    }

    /**
     * Priority leads the row, so a reviewer scanning the comment reads the rows that apply most to
     * the project first — the table keeps the report's own order, which is by priority.
     */
    public function testPriorityIsTheFirstColumn(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([
            new Finding('doctrine/cache', '1.13.0', Verdict::ABANDONED, [new Signal('S1', 'high', 'marked abandoned by its repository')], ['doctrine/cache'], null, $at),
            // Transitive and development-only: two steps below critical.
            new Finding('acme/dev-only', '2.0.0', Verdict::ABANDONED, [new Signal('S1', 'high', 'marked abandoned by its repository')], ['a/parent', 'acme/dev-only'], null, $at, null, true),
            new Finding('vendor/ok', '1.0.0', Verdict::OK, [], ['vendor/ok'], null, $at),
        ], [], $at, 3, 0, false);

        $lines = explode("\n", trim($this->formatter(Verdict::SILENT)->format($report, true)));
        $rows = array_values(array_filter($lines, static fn (string $line): bool => strpos($line, '` |') !== false));

        self::assertSame([
            '| critical | `doctrine/cache` | 1.13.0 | **abandoned** | marked abandoned by its repository | direct |',
            '| medium | `acme/dev-only` | 2.0.0 | **abandoned** | marked abandoned by its repository | a/parent |',
            '| none | `vendor/ok` | 1.0.0 | ok |  | direct |',
        ], $rows);
    }

    public function testZeroFlaggedHeadingHasNoTable(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([new Finding('vendor/ok', '1.0.0', Verdict::OK, [], ['vendor/ok'], null, $at)], [], $at, 1, 0, false);

        $out = $this->formatter()->format($report);

        self::assertStringContainsString('### lockrot: no dependency rot found in 1 packages', $out);
        self::assertStringNotContainsString('| Package |', $out);
    }

    public function testShowAllIncludesUnflaggedRows(): void
    {
        $out = $this->formatter()->format($this->report(), true);
        self::assertStringContainsString('vendor/ok', $out);
        self::assertStringContainsString('| `vendor/ok` |', $out);
    }

    public function testViaColumnUsesTheChainSeparator(): void
    {
        $out = $this->formatter()->format($this->report());
        $line = self::findLine($out, '| `phpzip/phpzip`');
        self::assertStringContainsString('wallabag/wallabag › grandt/phpepub', $line);
    }

    public function testDirectDependencyViaCell(): void
    {
        $out = $this->formatter()->format($this->report());
        $line = self::findLine($out, 'doctrine/cache');
        self::assertStringContainsString('| direct |', $line);
    }

    public function testViaCellNamesTheOtherDirectDependentsAndTheExposureLineFollowsTheTable(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([
            new Finding('acme/leaf', '1.0.0', Verdict::STALE, [new Signal('S2', 'warn', 'old')], ['a/parent', 'acme/leaf'], null, $at, null, false, ['a/parent', 'b/parent']),
            new Finding('acme/twice', '1.0.0', Verdict::STALE, [new Signal('S2', 'warn', 'old')], ['acme/twice'], null, $at, null, false, ['acme/twice', 'b/parent']),
        ], ['a note'], $at, 2, 0, false);
        $out = $this->formatter()->format($report);
        $lines = explode("\n", $out);

        self::assertStringContainsString('| a/parent, also via b/parent |', self::findLine($out, '| `acme/leaf`'));
        self::assertStringContainsString('| direct, also via b/parent |', self::findLine($out, '| `acme/twice`'));
        $exposure = array_search('pulled in by: b/parent 2 · a/parent 1', $lines, true);
        self::assertNotFalse($exposure);
        self::assertSame('', $lines[$exposure - 1], 'a blank line separates it from the table');
        self::assertSame('', $lines[$exposure + 1]);
        self::assertSame('- note: a note', $lines[$exposure + 2]);
    }

    public function testNoExposureLineWhenNothingIsPulledInThroughAnotherPackage(): void
    {
        self::assertStringNotContainsString('pulled in by:', $this->formatter()->format($this->report()));
    }

    public function testNotesAreListedAsBullets(): void
    {
        $out = $this->formatter()->format($this->report());
        self::assertStringContainsString('- note: GitHub token not set: repository activity checked only for 2 candidate packages', $out);
    }

    public function testFooterCarriesTheSummaryLineAndDataDate(): void
    {
        $out = $this->formatter()->format($this->report());
        self::assertStringContainsString(
            '<sub>'.$this->report()->summaryLine().' — data as of 2026-09-14. Run `composer lockrot --format=json` for details.</sub>',
            $out
        );
    }

    public function testCellEscapesPipesAndNewlines(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([
            new Finding('acme/pkg', '1.0.0', Verdict::STALE, [new Signal('S2', 'warn', "evidence with a | pipe\nand a second line")], ['acme/pkg'], null, $at),
        ], [], $at, 1, 0, false);

        $out = $this->formatter()->format($report);
        $line = self::findLine($out, 'acme/pkg');

        self::assertStringContainsString('evidence with a \\| pipe and a second line', $line);
        self::assertStringNotContainsString("\n\n", "\n".$line);
    }

    public function testVerdictIsBoldOnlyWhenNotANoteLevel(): void
    {
        $outAtFailOn = $this->formatter(Verdict::SILENT)->format($this->report());
        self::assertStringContainsString('**abandoned**', $outAtFailOn);
        self::assertStringContainsString('**silent**', $outAtFailOn);

        $outAtNone = $this->formatter(LockrotConfig::FAIL_ON_NONE)->format($this->report(), true);
        self::assertStringNotContainsString('**ok**', $outAtNone);
    }

    public function testBaselinedFindingIsNotBold(): void
    {
        $report = $this->report();
        $names = [];
        foreach ($report->findings() as $finding) {
            $names[] = $finding->package();
        }
        $baseline = Baseline::of([
            new BaselineEntry('doctrine/cache', '1.13.0', Verdict::ABANDONED, '2026-01-15'),
        ], self::AT);
        $withBaseline = $report->withBaseline(BaselineComparison::compare($baseline, $report, 'lockrot-baseline.json', $names));

        $out = $this->formatter(Verdict::SILENT)->format($withBaseline);
        $line = self::findLine($out, 'doctrine/cache');

        self::assertStringNotContainsString('**abandoned**', $line);
        self::assertStringContainsString('| abandoned |', $line);
    }

    public function testBaselineLineUnderTheHeading(): void
    {
        $report = $this->report();
        $names = [];
        foreach ($report->findings() as $finding) {
            $names[] = $finding->package();
        }
        $baseline = Baseline::of([
            new BaselineEntry('doctrine/cache', '1.13.0', Verdict::ABANDONED, '2026-01-15'),
        ], self::AT);
        $withBaseline = $report->withBaseline(BaselineComparison::compare($baseline, $report, 'lockrot-baseline.json', $names));

        $lines = explode("\n", trim($this->formatter()->format($withBaseline)));
        $comparison = $withBaseline->baseline();
        self::assertNotNull($comparison);

        self::assertStringStartsWith('### lockrot:', $lines[0]);
        self::assertSame($comparison->summaryLine(), $lines[1]);
    }

    public function testBaselineStaleEntriesBecomeANoteBullet(): void
    {
        $report = $this->report();
        $baseline = Baseline::of([
            new BaselineEntry('vendor/departed', '1.0.0', Verdict::SILENT, '2026-01-15'),
        ], self::AT);
        $withBaseline = $report->withBaseline(BaselineComparison::compare($baseline, $report, 'lockrot-baseline.json', []));

        self::assertStringContainsString(
            '- note: baseline lists 1 package no longer in composer.lock: vendor/departed',
            $this->formatter()->format($withBaseline)
        );
    }

    public function testWithoutABaselineNoBaselineLineIsEmitted(): void
    {
        $out = $this->formatter()->format($this->report());
        self::assertStringNotContainsString('baseline:', $out);
    }

    /**
     * A composer.lock is under the control of whoever opens the pull request, and the report is
     * posted into that pull request or its job summary: nothing a package carries may render as
     * markup there.
     */
    public function testPackageMetadataCannotInjectMarkup(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([
            new Finding('acme/ev`il', '1.0.0 <b>x</b>', Verdict::ABANDONED, [new Signal('S1', 'high', 'marked abandoned, replacement: <img src=x onerror=alert(1)> [fine](https://example.com) *ok* &lt;b&gt; #1')], ['acme/ev`il'], null, $at),
        ], ['GitHub unreachable: <script>alert(1)</script> [x](y)'], $at, 1, 0, false);

        $out = $this->formatter()->format($report);

        self::assertStringContainsString('| `` acme/ev`il `` | 1.0.0 \<b\>x\</b\> |', $out);
        self::assertStringContainsString('replacement: \<img src=x onerror=alert(1)\> \[fine\](https://example.com) \*ok\* \&lt;b\&gt; \#1', $out);
        self::assertStringContainsString('- note: GitHub unreachable: \<script\>alert(1)\</script\> \[x\](y)', $out);
        self::assertSame(0, preg_match('/(?<!\\\\)<(img|script|b)/', $out), 'every < from package metadata is escaped');
    }

    public function testCodeSpanFencesGrowPastTheLongestBacktickRun(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([
            new Finding('acme/a``b', '1.0.0', Verdict::ABANDONED, [new Signal('S1', 'high', 'x')], ['acme/a``b'], null, $at),
            new Finding('acme/plain', '1.0.0', Verdict::ABANDONED, [new Signal('S1', 'high', 'x')], ['acme/plain'], null, $at),
        ], [], $at, 2, 0, false);

        $out = $this->formatter()->format($report);

        self::assertStringContainsString('| ``` acme/a``b ``` |', $out);
        self::assertStringContainsString('| `acme/plain` |', $out);
    }

    public function testWordingAvoidsBannedTerms(): void
    {
        $out = strtolower($this->formatter(Verdict::SILENT)->format($this->report(), true));
        foreach (['vulnerable', 'broken', 'insecure', 'dead'] as $banned) {
            self::assertStringNotContainsString($banned, $out);
        }
    }

    public function testFactory(): void
    {
        self::assertInstanceOf(
            MarkdownFormatter::class,
            Formatters::for('markdown', FormatContext::create(null, LockrotConfig::FAIL_ON_NONE))
        );
    }

    public function testFactoryStillRejectsAnUnknownFormat(): void
    {
        $this->expectException(ConfigException::class);
        Formatters::for('xml', FormatContext::create(null, LockrotConfig::FAIL_ON_NONE));
    }

    private static function findLine(string $text, string $needle): string
    {
        foreach (explode("\n", $text) as $line) {
            if (strpos($line, $needle) !== false) {
                return $line;
            }
        }
        self::fail('no line contains "'.$needle.'"');
    }
}
