<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Analyzer\Libyears;
use Lockrot\Analyzer\LibyearsMeasurement;
use Lockrot\Analyzer\Report;
use Lockrot\Baseline\Baseline;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Baseline\BaselineEntry;
use Lockrot\Config\LockrotConfig;
use Lockrot\Output\ConsoleMarkup;
use Lockrot\Output\FormatContext;
use Lockrot\Output\TableFormatter;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\Notes;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\Attributes\DataProvider;
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
     * At 74 columns `see composer` fits and `audit` does not. At 80, `Run composer` fits and
     * `lockrot --format=json` does not. A command is one thing to copy, so it moves down whole.
     */
    public function testACommandTheFooterNamesIsNeverSplitAcrossLines(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $advisories = array_fill(0, 3, ['id' => 'x']);
        $report = new Report([
            (new FindingBuilder())->withPackage('vendor/ok')->withSignals([new Signal('S9', 'warn', '3 security advisories affect 1.0.0 (a, b, c)', ['advisories' => $advisories])])->withChain(['vendor/ok'])->withDataDate($at)->build(),
        ], [], $at, 1, 0, null, null, true);

        $at74 = $this->plainLines($this->formatter(74)->format($report));
        self::assertContains('3 security advisories on 1 package the report does not flag; see', $at74);
        self::assertContains('composer audit', $at74);

        $at80 = $this->plainLines($this->formatter(80)->format($report));
        self::assertContains('Data as of 2026-09-14 (package repositories, repository hosts). Run', $at80);
        self::assertContains('composer lockrot --format=json for details.', $at80);
        self::assertSame([], preg_grep('/\x1F/', $at80), 'the glue never reaches the terminal');
    }

    /**
     * One row per priority level plus two unflagged ones, so every group header and every label
     * style has a row to sit on.
     */
    private function report(): Report
    {
        $at = new \DateTimeImmutable(self::AT);

        return new Report([
            (new FindingBuilder())->withPackage('doctrine/cache')->withVersion('2.2.0')->withVerdict(Verdict::ABANDONED)->withSignals([new Signal('S1', 'high', 'marked abandoned by its repository'), new Signal('S2', 'high', 'last release 2022-05-20 (4.3 years ago)')])->withChain(['doctrine/cache'])->withDataDate($at)->build(),
            (new FindingBuilder())->withPackage('hoa/compiler')->withVersion('3.17.08.08')->withVerdict(Verdict::ABANDONED)->withSignals([new Signal('S1', 'high', 'marked abandoned by its repository'), new Signal('S4', 'high', 'repository archived on GitHub; last push 2021-04-29 (5.4 years ago)')])->withChain(['wallabag/rulerz', 'hoa/ruler', 'hoa/compiler'])->withDataDate($at)->build(),
            (new FindingBuilder())->withPackage('vendor/stale-direct')->withVersion('2.1.0')->withVerdict(Verdict::STALE)->withSignals([new Signal('S2', 'warn', 'last release 2022-05-20 (4.3 years ago)')])->withChain(['vendor/stale-direct'])->withDataDate($at)->build(),
            (new FindingBuilder())->withPackage('vendor/stale-deep')->withVerdict(Verdict::STALE)->withSignals([new Signal('S2', 'warn', 'last release 2022-01-04 (4.7 years ago)')])->withChain(['vendor/root', 'vendor/stale-deep'])->withDataDate($at)->build(),
            (new FindingBuilder())->withPackage('psr/cache')->withVersion('3.0.0')->withVerdict(Verdict::FINISHED)->withChain(['psr/cache'])->withAllowlistReason('interfaces')->withDataDate($at)->build(),
            (new FindingBuilder())->withPackage('vendor/ok')->withChain(['vendor/ok'])->withDataDate($at)->build(),
        ], Notes::texts(['GitHub token not set: repository activity checked only for 2 candidate packages (0 skipped); set GITHUB_TOKEN to check all']), $at, 6, 0);
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
            (new FindingBuilder())->withPackage('vendor/stale-deep')->withVerdict(Verdict::STALE)->withSignals([new Signal('S2', 'warn', 'last release 2022-01-04 (4.7 years ago)')])->withChain(['vendor/root', 'vendor/stale-deep'])->withDataDate($at)->build(),
        ], [], $at, 1, 0);
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
        self::assertSame('               marked abandoned by its repository; last release 2022-05-20 (4.3 years ago)', $lines[2]);
        self::assertSame('', $lines[3]);
        self::assertSame('high (1)', $lines[4]);
        self::assertSame('  abandoned    hoa/compiler 3.17.08.08  via wallabag/rulerz › hoa/ruler', $lines[5]);
    }

    public function testAnUnplaceablePackageShowsAQuestionMarkInsteadOfAChain(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([
            (new FindingBuilder())->withPackage('vendor/orphan')->withVerdict(Verdict::PINNED)->withSignals([new Signal('S6', 'warn', 'pinned to branch snapshot dev-master')])->withChain([])->withDataDate($at)->build(),
        ], [], $at, 1, 0);

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
     * Every rendered row of every group: everything before the blank line that separates the last
     * group from the summary block. The blank lines between groups stay inside the region, so the
     * slice ends at the last blank line, not the first.
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

        // every row of every group, not only the first one: the tail of line 1 only wraps on a row
        // with a long chain, and those are not in the critical group
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
        self::assertStringContainsString(
            str_repeat(' ', 15)."marked abandoned by its repository; last\n".str_repeat(' ', 15).'release 2022-05-20',
            $this->plain($out)
        );
    }

    /**
     * Baseline annotations push the label column to 21, so the indent is 25, and the narrowest
     * terminal lockrot accepts has 15 columns after it. The floor raises that to 20 and
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
     * text must come out of a *decorated* formatter byte-for-byte, styles aside: the label is
     * coloured, the constraint is not touched.
     */
    public function testAPhpConstraintInEvidenceSurvivesADecoratedFormatter(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $evidence = 'released 2017-05-02, before PHP 8.4 GA (2024-11-21); php constraint ">=7.2" has no upper bound; sibling pins "<8.0"';
        $report = new Report([
            (new FindingBuilder())->withPackage('vendor/constraint')->withVerdict(Verdict::OLD_PROMISE)->withSignals([new Signal('S5', 'warn', $evidence)])->withChain(['vendor/constraint'])->withDataDate($at)->build(),
        ], [], $at, 1, 0);

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
            (new FindingBuilder())->withPackage('vendor/<info>weird')->withVerdict(Verdict::ABANDONED)->withSignals([new Signal('S1', 'high', 'marked abandoned by its repository')])->withChain(['vendor/<info>weird'])->withDataDate($at)->build(),
        ], Notes::texts(['note with <comment> in it']), $at, 1, 0);

        $out = (new TableFormatter(FormatContext::create(null, LockrotConfig::FAIL_ON_NONE, '0.1.0', 200)))->format($report);

        self::assertStringContainsString('vendor/<info>weird', $this->plain($out));
        self::assertStringContainsString('note with <comment> in it', $this->plain($out));
        // and the raw string carries symfony's own escaping of them, whichever form this console
        // version produces — 5.4 escapes `>` as well as `<`, 2.8 only `<`
        self::assertStringContainsString(OutputFormatter::escape('vendor/<info>weird'), $out);
        self::assertStringContainsString(OutputFormatter::escape('note with <comment> in it'), $out);
        self::assertStringNotContainsString('vendor/<info>weird', $out);
    }

    /**
     * A package name, a version and evidence are the lock's and the repository's text. Rendered by
     * {@see ConsoleMarkup} — what stdout and an `--output` file get — they print as written, and
     * the only colours on the page are lockrot's own: the red label and the bold group header, each
     * closed where lockrot closed it, nothing running on into the text after them. symfony/console
     * 5.4's own escape() leaves the second `<` of `<<fg=red>>` live, which throws from the formatter or
     * opens a style or a link, and loses the backslash of `a\<b`.
     *
     * @dataProvider textsThatLookLikeMarkup
     */
    #[DataProvider('textsThatLookLikeMarkup')]
    public function testTextThatLooksLikeMarkupPrintsAsWrittenAndLeavesNoStyleBehind(string $text): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([
            (new FindingBuilder())->withPackage('vendor/'.$text)->withVersion($text)->withVerdict(Verdict::ABANDONED)->withSignals([new Signal('S1', 'high', 'marked abandoned: '.$text)])->withChain(['vendor/'.$text])->withDataDate($at)->build(),
        ], Notes::texts(['note: '.$text]), $at, 1, 0);

        $out = $this->formatter(200)->format($report);
        $plain = ConsoleMarkup::render($out, false);
        $decorated = ConsoleMarkup::render($out, true);

        // Compared without whitespace: the rows wrap a long run, and not only at its spaces.
        $squeezed = static fn (string $text): string => (string) preg_replace('/\s+/', '', $text);
        self::assertStringContainsString($squeezed('vendor/'.$text.' '.$text), $squeezed($plain));
        self::assertStringContainsString($squeezed('marked abandoned: '.$text), $squeezed($plain));
        self::assertStringContainsString($squeezed('note: note: '.$text), $squeezed($plain));
        self::assertSame(1, substr_count($decorated, "\033[31m"), 'one red label');
        self::assertSame(1, substr_count($decorated, "\033[1m"), 'one bold header');
        self::assertSame($plain, str_replace(["\033[31m", "\033[39m", "\033[1m", "\033[22m"], '', $decorated), 'nothing but those two styles');
        self::assertStringContainsString("\033[1mcritical (1)\033[22m\n  \033[31mabandoned\033[39m", $decorated);
    }

    /** @return iterable<string, array{string}> */
    public static function textsThatLookLikeMarkup(): iterable
    {
        yield 'a style tag' => ['<<fg=red>>'];
        yield 'a link tag' => ['<<href=x>>'];
        yield 'an escaped tag' => ['a\\<b'];
        yield 'a trailing backslash' => ['a\\'];
        yield 'a long run of <b' => [str_repeat('<b', 4000)];
    }

    /** Rendered wide enough that no summary line folds, so the order can be asserted line for line. */
    public function testTheSummaryBlockKeepsItsOrderWithThePriorityLineAfterTheCounts(): void
    {
        $lines = $this->plainLines($this->formatter(200)->format($this->report()));
        $tail = \array_slice($lines, -5);

        self::assertSame('6 packages checked · abandoned 2 · silent 0 · pinned 0 · left-behind 0 · old-promise 0 · stale 2 · unknown 0 · finished 1 · ok 1', $tail[0]);
        self::assertSame('priority: critical 1 · high 1 · medium 1 · low 1', $tail[1]);
        // the fixture findings carry no libyears value, so the line says so rather than inventing a zero
        self::assertSame('libyears: none of the 6 packages could be measured', $tail[2]);
        self::assertSame('Data as of 2026-09-14 (package repositories, repository hosts). Run composer lockrot --format=json for details.', $tail[3]);
        self::assertSame('note: GitHub token not set: repository activity checked only for 2 candidate packages (0 skipped); set GITHUB_TOKEN to check all', $tail[4]);
    }

    public function testAnEmptyRunStillEndsWithTheLibyearsLine(): void
    {
        $lines = $this->plainLines($this->formatter(200)->format(new Report([], [], new \DateTimeImmutable(self::AT), 0, 0)));

        self::assertSame('No dependency rot found in 0 packages.', $lines[0]);
        self::assertContains('libyears: nothing to measure', $lines);
    }

    /** The libyears line sits between the priority totals and the exposure line, and folds between its items like the counts line. */
    public function testTheLibyearsLineFollowsThePriorityTotals(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([
            (new FindingBuilder())->withPackage('smalot/pdfparser')->withVersion('v1.1.0')->withVerdict(Verdict::LEFT_BEHIND)->withSignals([new Signal('S8', 'warn', 'branch 1.x last released 2022-01-03')])->withChain(['smalot/pdfparser'])->withDataDate($at)->withDirectDependents(['smalot/pdfparser'])->withLibyears(LibyearsMeasurement::of(4.7123))->build(),
            (new FindingBuilder())->withPackage('psr/log')->withVersion('1.1.4')->withVerdict(Verdict::FINISHED)->withChain(['smalot/pdfparser', 'psr/log'])->withAllowlistReason('interfaces')->withDataDate($at)->withDirectDependents(['smalot/pdfparser'])->withLibyears(LibyearsMeasurement::of(3.36))->build(),
            (new FindingBuilder())->withPackage('wallabag/rulerz')->withVersion('dev-master')->withVerdict(Verdict::PINNED)->withSignals([new Signal('S6', 'warn', 'pinned')])->withChain(['wallabag/rulerz'])->withDataDate($at)->withDirectDependents(['wallabag/rulerz'])->withLibyears(LibyearsMeasurement::unmeasured(Libyears::BRANCH_SNAPSHOT))->build(),
        ], [], $at, 3, 0);
        $lines = $this->plainLines($this->formatter(200)->format($report));
        $priority = array_search('priority: critical 0 · high 2 · medium 0 · low 0', $lines, true);

        self::assertNotFalse($priority);
        self::assertSame('libyears: 8.1 behind across 2 of 3 packages · 4.7 from direct requirements · furthest behind smalot/pdfparser v1.1.0 at 4.7', $lines[$priority + 1]);
        self::assertStringStartsWith('Data as of ', $lines[$priority + 2]);

        // narrow: the line folds between items, and every fold ends on the separator
        $narrow = $this->plainLines($this->formatter(60)->format($report));
        $first = array_search('libyears: 8.1 behind across 2 of 3 packages ·', $narrow, true);
        self::assertNotFalse($first, implode("\n", $narrow));
        self::assertSame('4.7 from direct requirements ·', $narrow[$first + 1]);
        self::assertSame('furthest behind smalot/pdfparser v1.1.0 at 4.7', $narrow[$first + 2]);
    }

    public function testTheFooterStatesTheAgeOfCachedActivity(): void
    {
        $at = new \DateTimeImmutable('2026-09-14T00:00:00+00:00');
        $report = new Report([], [], $at, 0, 0, null, new \DateTimeImmutable('2026-09-13T05:00:00+00:00'));

        self::assertStringContainsString("Data as of 2026-09-14 (package repositories; repository activity from lockrot's cache, up to 19 h old). Run composer lockrot --format=json for details.", implode("\n", $this->plainLines($this->formatter(200)->format($report))));
    }

    /**
     * The counts line folds between items, by byte length as {@see TableFormatter::wrap()} counts:
     * `6 packages checked · abandoned 2 · silent 0 · pinned 0` is 57 bytes (three two-byte dots),
     * ` · left-behind 0` adds 17 and the ` ·` that closes the line 3 more, so the 77-byte line
     * `… · left-behind 0 ·` is emitted at 77 and `left-behind 0` starts the next line at 76. A line
     * that closes with its separator is never wider than the terminal.
     */
    public function testTheCountsLineFoldsBetweenItemsAtTheExactByteWidth(): void
    {
        $fits = $this->plainLines($this->formatter(77)->format($this->report()));
        $wraps = $this->plainLines($this->formatter(76)->format($this->report()));

        self::assertContains('6 packages checked · abandoned 2 · silent 0 · pinned 0 · left-behind 0 ·', $fits);
        foreach ($fits as $line) {
            self::assertLessThanOrEqual(77, \strlen($line), $line);
        }
        self::assertContains('6 packages checked · abandoned 2 · silent 0 · pinned 0 ·', $wraps);
        self::assertNotEmpty(array_filter($wraps, static fn (string $line): bool => strpos($line, 'left-behind 0 · old-promise 0 ·') === 0), 'the item that did not fit opens the next line');
        foreach ($wraps as $line) {
            self::assertLessThanOrEqual(76, \strlen($line), $line);
        }
    }

    /**
     * The last item closes no line, so nothing is reserved after it: the 137-byte counts line is
     * one line at 137, where a closing separator does not fit, and `ok 1` folds at 136.
     */
    public function testTheLastItemNeedsNoRoomForAClosingSeparator(): void
    {
        $counts = '6 packages checked · abandoned 2 · silent 0 · pinned 0 · left-behind 0 · old-promise 0 · stale 2 · unknown 0 · finished 1 · ok 1';
        self::assertSame(137, \strlen($counts));

        self::assertContains($counts, $this->plainLines($this->formatter(137)->format($this->report())));
        $folded = $this->plainLines($this->formatter(136)->format($this->report()));
        self::assertContains('6 packages checked · abandoned 2 · silent 0 · pinned 0 · left-behind 0 · old-promise 0 · stale 2 · unknown 0 · finished 1 ·', $folded);
        self::assertContains('ok 1', $folded);
    }

    /** Summary lines fold at the full width with no indent: each is a fact of its own, not a continuation under a label. */
    public function testTheSummaryBlockIsWrappedToTheTerminalWidth(): void
    {
        $lines = $this->plainLines($this->formatter(60)->format($this->report()));

        foreach ($lines as $line) {
            self::assertLessThanOrEqual(60, \strlen($line), 'line wider than the terminal: '.$line);
        }
        // the counts line and the note folded, and each continuation starts in column 1
        $plain = implode("\n", $lines);
        self::assertStringContainsString("6 packages checked · abandoned 2 · silent 0 · pinned 0 ·\nleft-behind 0 · old-promise 0 ·", $plain);
        self::assertStringContainsString("note: GitHub token not set: repository activity checked only\nfor 2 candidate packages", $plain);
    }

    /**
     * A summary line folds at spaces only: a single token longer than the width — an absolute
     * baseline path in a note — is kept whole on its own line rather than cut mid-path, so the
     * reader can copy it. Rows keep cutting long tokens, so they never exceed the terminal. The
     * summary block trades that guarantee for an intact path.
     */
    public function testALongTokenInTheSummaryBlockIsNeverCut(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $path = '/Users/someone/projects/with/a/rather/long/directory/name/lockrot-baseline.json';
        $report = new Report([(new FindingBuilder())->withPackage('vendor/ok')->withChain(['vendor/ok'])->withDataDate($at)->build()], Notes::texts(['baseline written to '.$path]), $at, 1, 0);
        $lines = $this->plainLines($this->formatter(60)->format($report));

        self::assertContains($path, $lines, 'the path stays one whole line');
        self::assertContains('note: baseline written to', $lines);
    }

    /** A row never exceeds the terminal, whatever token it carries. */
    public function testALongTokenInARowIsCutSoTheRowNeverExceedsTheWidth(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $package = 'vendor/'.str_repeat('a', 60);
        $evidence = 'marked abandoned, replacement: https://example.com/'.str_repeat('b', 60);
        $report = new Report([(new FindingBuilder())->withPackage($package)->withVerdict(Verdict::ABANDONED)->withSignals([new Signal('S1', 'high', $evidence)])->withChain([$package])->withDataDate($at)->build()], [], $at, 1, 0);
        $lines = $this->plainLines($this->formatter(40)->format($report));

        $rows = array_values(array_filter($lines, static fn (string $line): bool => strpos($line, '  ') === 0));
        self::assertNotEmpty($rows);
        foreach ($rows as $row) {
            self::assertLessThanOrEqual(40, \strlen($row), $row);
        }
        self::assertStringContainsString($package, implode('', array_map('trim', $rows)));
        self::assertStringContainsString(str_repeat('b', 60), implode('', array_map('trim', $rows)));
    }

    public function testCleanReport(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([(new FindingBuilder())->withPackage('vendor/ok')->withChain(['vendor/ok'])->withDataDate($at)->build()], [], $at, 1, 0);
        // wide enough that the nine-verdict counts line does not fold
        $lines = $this->plainLines($this->formatter(200)->format($report));

        self::assertSame('No dependency rot found in 1 packages.', $lines[0]);
        // the same blank line a grouped report puts before its summary block
        self::assertSame('', $lines[1]);
        self::assertStringContainsString(' packages checked', $lines[2]);
        // and no `priority: critical 0 · high 0 · medium 0 · low 0` under it — the libyears line, yes:
        // a clean lock can still be years behind
        self::assertSame('libyears: the one package could not be measured', $lines[3]);
        self::assertStringStartsWith('Data as of ', $lines[4]);
    }

    /**
     * The priority totals count flagged findings, so a report with none has nothing to total. Under
     * --all the rows are still listed, in their `not flagged` group, and the line is still absent.
     */
    public function testThePriorityTotalsAppearOnlyWhenSomethingIsFlagged(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $clean = new Report([
            (new FindingBuilder())->withPackage('vendor/ok')->withChain(['vendor/ok'])->withDataDate($at)->build(),
            (new FindingBuilder())->withPackage('psr/cache')->withVersion('3.0.0')->withVerdict(Verdict::FINISHED)->withChain(['psr/cache'])->withAllowlistReason('interfaces')->withDataDate($at)->build(),
        ], [], $at, 2, 0);

        self::assertStringNotContainsString('priority:', $this->plain($this->formatter()->format($clean)));
        self::assertStringNotContainsString('priority:', $this->plain($this->formatter()->format($clean, true)));
        self::assertStringContainsString('not flagged (2)', $this->plain($this->formatter()->format($clean, true)));
        self::assertStringContainsString('priority:', $this->plain($this->formatter()->format($this->report())));
    }

    /**
     * The report is written straight to the output with no trailing newline of its own, so the
     * document must end with one, or the shell prompt lands on the last line.
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
     * so the two-space gutter before `direct` otherwise trails invisibly off several rows.
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
     * abandoned, hoa/compiler as only stale (worsened), and vendor/departed, which the lock lacks.
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
        // the libyears line sits between the two
        self::assertStringStartsWith('libyears: ', $lines[$index + 1]);
        self::assertSame(
            'baseline: 1 known · 2 new · 1 worsened · 1 stale (lockrot-baseline.json)',
            $lines[$index + 2]
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
            (new FindingBuilder())->withPackage('vendor/allowed')->withVerdict(Verdict::ABANDONED)->withSignals([new Signal('S1', 'high', 'marked abandoned by its repository')])->withChain(['vendor/allowed'])->withAllowlistReason('replaced upstream')->withDataDate($at)->build(),
        ], [], $at, 1, 0);

        self::assertStringContainsString(
            'marked abandoned by its repository; allowlisted: replaced upstream',
            $this->plain($this->formatter()->format($report))
        );
    }

    private function allVerdictsReport(): Report
    {
        $at = new \DateTimeImmutable(self::AT);
        $findings = array_merge($this->report()->findings(), [
            (new FindingBuilder())->withPackage('vendor/pinned')->withVersion('1.2.3')->withVerdict(Verdict::PINNED)->withSignals([new Signal('S6', 'warn', 'pinned to branch snapshot dev-master')])->withChain(['vendor/pinned'])->withDataDate($at)->build(),
            (new FindingBuilder())->withPackage('vendor/old-promise')->withVersion('0.9.0')->withVerdict(Verdict::OLD_PROMISE)->withSignals([new Signal('S5', 'warn', 'released 2015-11-16, before PHP 8.4 GA (2024-11-21); php constraint ">=5.3.0" has no upper bound')])->withChain(['vendor/old-promise'])->withDataDate($at)->build(),
            (new FindingBuilder())->withPackage('vendor/silent')->withVersion('2.0.8')->withVerdict(Verdict::SILENT)->withSignals([new Signal('S2', 'high', 'last release 2015-11-16 (10.8 years ago)')])->withChain(['vendor/root', 'vendor/silent'])->withDataDate($at)->build(),
            (new FindingBuilder())->withPackage('vendor/unknown')->withVerdict(Verdict::UNKNOWN)->withSignals([new Signal('S3', 'info', 'not from a Composer repository, not checked')])->withChain(['vendor/unknown'])->withDataDate($at)->build(),
        ]);

        return new Report($findings, $this->report()->runNotes(), $at, \count($findings), 1);
    }

    public function testARowNamesTheOtherDirectDependentsAndTheSummaryGetsThePulledInByLine(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([
            (new FindingBuilder())->withPackage('hoa/ruler')->withVersion('2.17.05.16')->withVerdict(Verdict::ABANDONED)->withSignals([new Signal('S1', 'high', 'marked abandoned by its repository')])->withChain(['wallabag/rulerz', 'hoa/ruler'])->withDataDate($at)->withDirectDependents(['wallabag/rulerz', 'wallabag/rulerz-bundle'])->build(),
            (new FindingBuilder())->withPackage('vendor/twice')->withVerdict(Verdict::STALE)->withSignals([new Signal('S2', 'warn', 'last release 2022-05-20 (4.3 years ago)')])->withChain(['vendor/twice'])->withDataDate($at)->withDirectDependents(['vendor/other', 'vendor/twice'])->build(),
            (new FindingBuilder())->withPackage('vendor/many')->withVerdict(Verdict::STALE)->withSignals([new Signal('S2', 'warn', 'last release 2022-05-20 (4.3 years ago)')])->withChain(['r/a', 'vendor/many'])->withDataDate($at)->withDirectDependents(['r/a', 'r/b', 'r/c', 'r/d', 'r/e'])->build(),
        ], [], $at, 3, 0);
        $lines = $this->plainLines($this->formatter(200)->format($report));

        self::assertSame('  abandoned    hoa/ruler 2.17.05.16  via wallabag/rulerz, also via wallabag/rulerz-bundle', $lines[1]);
        self::assertContains('  stale        vendor/twice 1.0.0  direct', $lines);
        self::assertContains('  stale        vendor/many 1.0.0  via r/a, also via r/b, r/c, r/d and 1 more', $lines);
        self::assertContains('pulled in by: r/a 1 · r/b 1 · r/c 1 · r/d 1 · r/e 1 · … and 2 more', $lines);
        $priority = array_search('priority: critical 0 · high 1 · medium 1 · low 1', $lines, true);
        self::assertNotFalse($priority);
        self::assertStringStartsWith('libyears: ', $lines[$priority + 1]);
        self::assertStringStartsWith('pulled in by: ', $lines[$priority + 2]);
    }

    public function testNoPulledInByLineWhenNothingIsReachedThroughAnotherPackage(): void
    {
        self::assertStringNotContainsString('pulled in by:', $this->plain($this->formatter()->format($this->report())));
    }
}
