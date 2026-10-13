<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunSettings;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Config\LockrotConfig;
use Lockrot\Output\ConsoleMarkup;
use Lockrot\Output\FormatContext;
use Lockrot\Output\TableFormatter;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\BaselineEntries;
use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\Notes;
use Lockrot\Tests\Support\SignalDataStub;
use Lockrot\Tests\Support\TextGolden;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The `table` format: the finding block under its grade, then the run footer. */
final class TableFormatterTest extends TestCase
{
    private const AT = '2026-09-14T06:00:00+00:00';

    private static ?Report $composite = null;

    private static function formatter(int $width = 120, string $failOn = LockrotConfig::FAIL_ON_NONE): TableFormatter
    {
        return new TableFormatter(FormatContext::create(null, $failOn, '0.1.0', $width));
    }

    /** @return list<string> */
    private static function lines(string $out): array
    {
        return explode("\n", rtrim(ConsoleMarkup::render($out, false), "\n"));
    }

    private static function composite(): Report
    {
        return self::$composite ??= CaseHydrator::composite();
    }

    public function testTheCompositeRendersAsItsGolden(): void
    {
        TextGolden::assertMatches('composite.table.txt', ConsoleMarkup::render(self::formatter()->format(self::composite()), false), __METHOD__);
    }

    public function testAWrapNeverSplitsTheCommandThatTheDataLineNames(): void
    {
        $lines = self::lines(ConsoleMarkup::render(self::formatter(100, 'high')->format(self::composite()), false));

        self::assertContains('composer lockrot --format=json for details.', $lines);
    }

    public function testAFailingGateLineIsRed(): void
    {
        self::assertStringContainsString("\n<fg=red>gate: fails · --fail-on=high · ", self::formatter(120, 'high')->format(self::composite()));
        $passing = new Report([(new FindingBuilder())->withPackage('a/b')->withVerdict(Verdict::STALE)->build()], [], new \DateTimeImmutable(self::AT), 1, 0);
        self::assertStringContainsString("\ngate: off · ", self::formatter(120, 'none')->format($passing));
    }

    public function testAllOfTheCompositeAddsNoRowWhenEveryFindingIsGraded(): void
    {
        self::assertSame(self::formatter()->format(self::composite()), self::formatter()->format(self::composite(), true));
    }

    public function testGroupsComeInGradeOrderAndScoreZeroFindingsComeLastUnderAll(): void
    {
        $lines = self::lines(self::formatter()->format(self::report(), true));

        self::assertSame(['critical (1)', 'medium (1)', 'low (1)', 'not flagged (2)'], array_values(preg_grep('/^\S.* \(\d+\)$/', $lines) ?: []));
        self::assertSame(['critical (1)', 'medium (1)', 'low (1)'], array_values(preg_grep('/^\S.* \(\d+\)$/', self::lines(self::formatter()->format(self::report()))) ?: []));
    }

    public function testTheHeaderNamesThePackageItsReachAndItsCountedFlags(): void
    {
        $lines = self::lines(self::formatter()->format(self::report(), true));

        self::assertContains('  a/gone 2.2.0  direct  ·  abandoned', $lines);
        self::assertContains('  a/deep 1.0.0  via a/root  ·  stale', $lines);
        self::assertContains('  a/kept 3.0.0  direct  ·  finished', $lines);
        self::assertContains('  a/fine 1.0.0  direct  ·  ok', $lines);
    }

    public function testTheScoreLineFollowsTheHeader(): void
    {
        $lines = self::lines(self::formatter()->format(self::report()));
        $header = array_search('  a/gone 2.2.0  direct  ·  abandoned', $lines, true);

        self::assertNotFalse($header);
        self::assertSame('      32 = abandoned 32', $lines[$header + 1]);
    }

    public function testCriticalAndHighAreRedMediumIsYellowAndLowIsPlain(): void
    {
        $decorated = ConsoleMarkup::render(self::formatter()->format(self::report()), true);

        self::assertStringContainsString("\033[31mcritical (1)\033[39m", $decorated);
        self::assertStringContainsString("\033[33mmedium (1)\033[39m", $decorated);
        self::assertStringContainsString("\033[1mlow (1)\033[22m", $decorated);
    }

    public function testTheGateLineIsRedOnlyWhenTheGateFails(): void
    {
        $failing = ConsoleMarkup::render(self::formatter(120, 'high')->format(self::report()), true);
        $passing = ConsoleMarkup::render(self::formatter(120, 'unchecked')->format(self::report()), true);
        $off = self::lines(self::formatter()->format(self::report()));

        self::assertStringContainsString("\033[31mgate: fails · --fail-on=high · 1 finding fails it · target php ", $failing);
        self::assertStringContainsString("\ngate: fails · --fail-on=critical,stale · 3 findings fail it (critical 1, stale 3)", ConsoleMarkup::render(self::formatter(200, 'critical,stale')->format(self::report()), false));
        self::assertStringNotContainsString("\033[31mgate:", ConsoleMarkup::render(self::formatter(120, 'none')->format(self::report()), true));
        self::assertNotEmpty(preg_grep('/^gate: off · --fail-on=none \(default\) · target php /', $off));
        self::assertStringContainsString("\ngate: passes · --fail-on=unchecked · ", $passing);
        self::assertStringNotContainsString("\033[31mgate:", $passing);
    }

    public function testTheFooterKeepsTheOrderOfTheSpec(): void
    {
        $lines = self::lines(self::formatter(400, 'high')->format(self::composite()));
        $order = [];
        foreach ($lines as $line) {
            if (preg_match('/^(?:\d+ )?(packages checked|flags:|depth:|security:|fix, by package:|update now:|libyears:|pulled in by:|advisories:|gate:|Data as of)/', $line, $m) === 1) {
                $order[] = $m[1];
            }
        }

        self::assertSame(['packages checked', 'flags:', 'depth:', 'security:', 'fix, by package:', 'update now:', 'libyears:', 'pulled in by:', 'advisories:', 'gate:', 'Data as of'], $order);
        self::assertSame('Data as of 2026-10-08 (package repositories, repository hosts) · score model 1. Run composer lockrot --format=json for details.', end($lines));
    }

    public function testACleanRunSaysNothingIsFlaggedAndStillPrintsTheGateAndTheDataLine(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([(new FindingBuilder())->withPackage('a/fine')->withDataDate($at)->build()], [], $at, 1, 0);
        $lines = self::lines(self::formatter(200)->format($report));

        self::assertSame('1 package checked · nothing flagged', $lines[0]);
        self::assertStringStartsWith('advisories: ', $lines[2]);
        self::assertStringStartsWith('gate: off · --fail-on=none (default) · target php ', $lines[3]);
        self::assertSame('Data as of 2026-09-14 (package repositories, repository hosts) · score model 1. Run composer lockrot --format=json for details.', $lines[4]);
    }

    /**
     * The header and the score line are never folded: the score line is one formula. A
     * footer item wider than the terminal stays whole.
     */
    public function testEveryOtherLineFitsTheWidthUnlessOneWordIsWider(): void
    {
        $wide = [];
        foreach ([80, 120] as $width) {
            foreach (self::lines(self::formatter($width, 'high')->format(self::composite())) as $line) {
                $kept = preg_match('/^(      \d+ = |  \S)/', $line) === 1 || (preg_match('/^\S/', $line) === 1 && preg_match('/ · ./', $line) !== 1);
                if (\strlen($line) > $width && !$kept && preg_match('/  \S{'.(int) ($width / 3).',}/', $line) !== 1) {
                    $wide[] = $width.': '.$line;
                }
            }
        }

        self::assertSame([], $wide);
    }

    public function testAKnownFlagReadsBaselineAndTheScoreLineCarriesTheSuffix(): void
    {
        $report = self::report();
        $known = $report->withBaseline(BaselineComparison::compare(BaselineEntries::baseline([BaselineEntries::of('a/gone', '2.2.0', Verdict::ABANDONED, '2026-01-15')]), $report, 'lockrot-baseline.json', ['a/gone', 'a/deep', 'a/mid', 'a/kept', 'a/fine']));
        $lines = self::lines(self::formatter(200, 'high')->format($known));

        self::assertContains('  a/gone 2.2.0  direct  ·  abandoned (baseline)', $lines);
        self::assertContains('      32 = abandoned 32 (baseline: known)', $lines);
        self::assertContains('      gate 0 = min(32, 2 × 0) (the new facts bound it); new: 0', $lines);
        self::assertContains('      8 = stale 8 (baseline: new package)', $lines);
        self::assertContains('baseline: 1 known · 2 new · 0 worsened · 0 stale (lockrot-baseline.json)', $lines);
    }

    public function testAnAllowlistEntryThatAcceptsEveryFlagGetsItsLine(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([(new FindingBuilder())->withPackage('a/kept')->withSignals([new Signal(Signal::LEVEL_WARN, 'x', SignalDataStub::of(Signal::S2, []))])->withAllowlistReason('replaced upstream')->withDataDate($at)->build()], [], $at, 1, 0);
        $lines = self::lines(self::formatter()->format($report, true));

        self::assertContains('  a/kept 1.0.0  direct  ·  finished · stale (accepted)', $lines);
        self::assertContains('      maintenance accepted (your ignore list)  replaced upstream', $lines);
    }

    public function testNotesFollowTheDataLine(): void
    {
        $lines = self::lines(self::formatter(200)->format(self::report()));

        self::assertSame('note: GitHub token not set', end($lines));
        self::assertStringStartsWith('Data as of ', $lines[\count($lines) - 2]);
    }

    /**
     * Text from the lock prints as written, and the only colours on the page are lockrot's own.
     *
     * @dataProvider textsThatLookLikeMarkup
     */
    #[DataProvider('textsThatLookLikeMarkup')]
    public function testTextThatLooksLikeMarkupPrintsAsWrittenAndLeavesNoStyleBehind(string $text): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([
            (new FindingBuilder())->withPackage('vendor/'.$text)->withVersion($text)->withVerdict(Verdict::ABANDONED)->withChain(['vendor/'.$text])->withDataDate($at)->build(),
        ], Notes::texts(['note: '.$text]), $at, 1, 0);

        $out = self::formatter(200)->format($report);
        $plain = ConsoleMarkup::render($out, false);
        $decorated = ConsoleMarkup::render($out, true);

        $squeezed = static fn (string $text): string => (string) preg_replace('/\s+/', '', $text);
        self::assertStringContainsString($squeezed('vendor/'.$text.' '.$text), $squeezed($plain));
        self::assertStringContainsString($squeezed('note: note: '.$text), $squeezed($plain));
        self::assertSame($plain, str_replace(["\033[31m", "\033[39m", "\033[1m", "\033[22m"], '', $decorated), 'nothing but lockrot\'s styles');
    }

    /** @return iterable<string, array{string}> */
    public static function textsThatLookLikeMarkup(): iterable
    {
        yield 'a style tag' => ['<<fg=red>>'];
        yield 'a link tag' => ['<<href=x>>'];
        yield 'an escaped tag' => ['a\\<b'];
        yield 'a trailing backslash' => ['a\\'];
    }

    public function testEveryRenderedReportEndsWithOneNewlineAndNoLineEndsWithWhitespace(): void
    {
        $out = ConsoleMarkup::render(self::formatter()->format(self::composite()), false);

        self::assertStringEndsWith(".\n", $out);
        self::assertSame([], preg_grep('/\s$/', explode("\n", rtrim($out, "\n"))));
    }

    /** A critical, a medium and a low finding, and two score-0 ones that only `--all` shows. */
    private static function report(): Report
    {
        $at = new \DateTimeImmutable(self::AT);

        return new Report([
            (new FindingBuilder())->withPackage('a/gone')->withVersion('2.2.0')->withVerdict(Verdict::ABANDONED)->withDataDate($at)->build(),
            (new FindingBuilder())->withPackage('a/mid')->withVerdict(Verdict::STALE)->withSignals([new Signal(Signal::LEVEL_WARN, 'x', SignalDataStub::of(Signal::S2, [])), new Signal(Signal::LEVEL_WARN, 'x', SignalDataStub::of(Signal::S4, []))])->withDataDate($at)->build(),
            (new FindingBuilder())->withPackage('a/deep')->withVerdict(Verdict::STALE)->withChain(['a/root', 'a/deep'])->withDirectDependents(['a/root'])->withDataDate($at)->build(),
            (new FindingBuilder())->withPackage('a/kept')->withVersion('3.0.0')->withVerdict(Verdict::FINISHED)->withAllowlistReason('interfaces')->withDataDate($at)->build(),
            (new FindingBuilder())->withPackage('a/fine')->withDataDate($at)->build(),
        ], Notes::texts(['GitHub token not set']), $at, 5, 0, null, null, false);
    }

    public function testTheContextFailOnStandsInForARunTheReportWasNotTold(): void
    {
        $report = self::report();
        $told = $report->withRun(new RunSettings(null, null, '8.4', RunSettings::SOURCE_OPTION, null, FailOn::parse('stale'), RunSettings::SOURCE_OPTION, null));

        self::assertContains('gate: fails · --fail-on=stale · 3 findings fail it · target php 8.4 (--target-php)', self::lines(self::formatter(200, 'high')->format($told)));
    }
}
