<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Allowlist\AcceptedCheck;
use Lockrot\Allowlist\UncheckedAcceptanceEntry;
use Lockrot\Analyzer\MoveCheckRun;
use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunSettings;
use Lockrot\Analyzer\UpdateNow;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Config\LockrotConfig;
use Lockrot\Data\Abandoned\AbandonedIgnoreRule;
use Lockrot\Output\ConsoleMarkup;
use Lockrot\Output\FormatContext;
use Lockrot\Output\TableFormatter;
use Lockrot\Signal\AbandonedIgnored;
use Lockrot\Signal\Data\Check;
use Lockrot\Signal\PhpFloor;
use Lockrot\Signal\Signal;
use Lockrot\Solver\MoveCheck;
use Lockrot\Solver\UncheckedMoves;
use Lockrot\Tests\Support\BaselineEntries;
use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\Notes;
use Lockrot\Tests\Support\SignalDataStub;
use Lockrot\Tests\Support\TextGolden;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\FindingDetails;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\Move;
use Lockrot\Verdict\MoveChecks;
use Lockrot\Verdict\MoveSubject;
use Lockrot\Verdict\ShellCommand;
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

    public function testThePullsInAndChecksAcceptedLinesWrapUnderTheBlockIndent(): void
    {
        $s7 = new Signal(Signal::LEVEL_INFO, 'pulls in 3 flagged packages: vendor/first (stale), vendor/second (stale), vendor/third (stale)', SignalDataStub::of(Signal::S7, ['flagged' => 3, 'packages' => []]));
        $s10 = new Signal(Signal::LEVEL_INFO, 'not checked', SignalDataStub::of(Signal::S10, ['unchecked' => [['check' => 'advisories', 'reason' => 'offline', 'blocks' => ['S9']], ['check' => 'repository_activity', 'reason' => 'host_not_supported', 'blocks' => ['S3', 'S4']]]]));
        $finding = (new FindingBuilder())->withPackage('vendor/pkg')->withVerdict(Verdict::STALE)->withSignals([$s7, $s10])->build();
        $accepted = new AcceptedCheck(new Check('repository_activity', 'host_not_supported', ['S3', 'S4']), UncheckedAcceptanceEntry::fromConfig(0, ['check' => 'repository_activity', 'host' => 'codeberg.org', 'reason' => 'we watch it by hand', 'expires' => '2027-03-31']));
        $details = FindingDetails::of(F::looked(F::facts(F::package(), F::metadata([['1.0.0', '2020-01-01T00:00:00+00:00']]))), null, new PhpFloor('8.4'), [], [], null, $finding->details()->age(), [$accepted]);
        $report = new Report([$finding->withDetails($details)], [], new \DateTimeImmutable('2026-10-01T00:00:00+00:00'), 1, 0);

        $lines = self::lines(self::formatter(50)->format($report, true));

        $pullsIn = array_search('      pulls in 3 flagged packages: vendor/first', $lines, true);
        self::assertIsInt($pullsIn, implode("\n", $lines));
        self::assertSame('      (stale), vendor/second (stale), vendor/third', $lines[$pullsIn + 1]);
        $accepted = array_search('      checks accepted: S3, S4 (host not supported;', $lines, true);
        self::assertIsInt($accepted, implode("\n", $lines));
        self::assertSame('      accepted by your accept-unchecked list: we', $lines[$accepted + 1]);
        $missing = array_values(preg_grep('/^      checks missing: /', $lines) ?: []);
        self::assertCount(1, $missing, implode("\n", $lines));
        $at = array_search($missing[0], $lines, true);
        self::assertIsInt($at);
        self::assertMatchesRegularExpression('/^      [^ ]/', $lines[$at + 1], 'a wrapped missing-checks line keeps the block indent');
    }

    public function testTheAbandonedMarkingLineWrapsUnderItsHang(): void
    {
        $finding = (new FindingBuilder())->withPackage('doctrine/cache')->withVerdict(Verdict::STALE)->build();
        $ignored = new AbandonedIgnored('policy.abandoned', [new AbandonedIgnoreRule('doctrine/*', 'the cache goes with the 9.0 upgrade', [])], AbandonedIgnored::MARKED_BY_REPOSITORY, null, null);
        $report = new Report([$finding->withDetails(FindingBuilder::detailsOf($finding, true, null, null, $ignored))], [], new \DateTimeImmutable('2026-10-01T00:00:00+00:00'), 1, 0);

        $lines = self::lines(self::formatter(50)->format($report, true));

        $marking = array_search('      abandoned marking ignored', $lines, true);
        self::assertIsInt($marking, implode("\n", $lines));
        self::assertSame(['        (policy.abandoned: doctrine/*): the cache', '        goes with the 9.0 upgrade'], \array_slice($lines, $marking + 1, 2));
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
        self::assertMatchesRegularExpression('/^Data as of \d{4}-\d{2}-\d{2} \(package repositories, repository hosts\) · score model 1\. Run composer lockrot --format=json for details\.$/', $lines[\count($lines) - 1]);
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

    /**
     * A command that the move text names prints whole on a line of its own, after `do: ` or
     * alone, at any width, so a copy of that line runs it and nothing else.
     */
    public function testEachCommandOfAMovePrintsWholeOnItsOwnLine(): void
    {
        $report = CaseHydrator::everyCase();
        $commands = [];
        foreach ($report->graded() as $finding) {
            $move = $finding->nextStep();
            if ($move === null) {
                continue;
            }
            foreach ($move->commands() as $argv) {
                $command = ShellCommand::posix($argv);
                if ($command !== null && strpos($move->text(), $command) !== false) {
                    $commands[$command] = true;
                }
            }
        }
        self::assertNotSame([], $commands);

        foreach ([40, 100, 400] as $width) {
            $lines = array_map(static fn (string $line): string => (string) preg_replace('/^\s*(do: )?/', '', $line), self::lines(self::formatter($width)->format($report)));
            foreach (array_keys($commands) as $command) {
                self::assertContains($command, $lines, 'width '.$width);
            }
        }
    }

    /**
     * @dataProvider movesAndTheirParts
     *
     * @param list<list<string>>              $commands
     * @param list<array{0: string, 1: bool}> $parts
     */
    #[DataProvider('movesAndTheirParts')]
    public function testTheMoveTextIsCutAroundTheCommandThatItNames(string $kind, string $source, ?string $override, array $commands, array $parts): void
    {
        $move = new Move($kind, $source, [], null, null, null, null, '2.x', '2.0.0', '2.0.0', null, null, null, false, [], [], [], [], [], null, [], $commands, new MoveSubject('acme/lib', '1.2.0', true, [], '8.4', null, null, $override));

        self::assertSame($parts, TableFormatter::commandParts($move));
    }

    /** @return iterable<string, array{string, string, ?string, list<list<string>>, list<array{0: string, 1: bool}>}> */
    public static function movesAndTheirParts(): iterable
    {
        $update = ['composer', 'update', 'acme/lib'];
        yield 'the command first' => [Move::UPDATE, FlagSet::VULNERABLE, null, [$update], [['composer update acme/lib', true], ['(gets 2.0.0)', false]]];
        yield 'a platform step before the command' => [Move::UPDATE, FlagSet::VULNERABLE, '7.4.29', [$update], [['First set config.platform.php to your PHP 8.4 release: the lock records 7.4.29.', false], ['composer update acme/lib', true], ['(gets 2.0.0)', false]]];
        yield 'a command that no shell takes, before the named one' => [Move::UPDATE, FlagSet::VULNERABLE, null, [['composer', 'update', 'acme/lib;x'], $update], [['composer update acme/lib', true], ['(gets 2.0.0)', false]]];
        yield 'no command in the text' => [Move::FIND_ALTERNATIVE, FlagSet::ABANDONED, null, [], [['find a replacement (none named)', false]]];
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

    /** A terminal obeys a control character from the lock (OSC 52 writes the clipboard), so each prints as an escape, on its own line. */
    public function testControlCharactersFromOutsidePrintAsEscapes(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $evil = "evil\x1b]52;c;cHduZWQ=\x07\x1b[2J\u{9B}31m\ntail";
        $report = new Report([
            (new FindingBuilder())->withPackage('vendor/'.$evil)->withVersion($evil)->withVerdict(Verdict::ABANDONED)->withChain(['vendor/'.$evil])->withDataDate($at)->build(),
        ], Notes::texts(['note '.$evil]), $at, 1, 0);

        $plain = ConsoleMarkup::render(self::formatter(400)->format($report), false);

        $shown = 'evil\x1B]52;c;cHduZWQ=\x07\x1B[2J\u{009B}31m\ntail';
        self::assertSame(0, preg_match('/[\x00-\x09\x0B-\x1F\x7F]|\xC2[\x80-\x9F]/', $plain), 'no control character but the line feed');
        self::assertStringContainsString('vendor/'.$shown.' '.$shown, $plain);
        self::assertStringContainsString("\nnote: note ".$shown."\n", $plain);
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
    public function testTheMovesLineNamesTheVariableThatTurnedTheBlockingOff(): void
    {
        $checks = new MoveChecks(new UncheckedMoves(MoveCheck::NO_COMPOSER_JSON), true);
        $report = self::report()->withMoveChecks(new MoveCheckRun('2.9.5', false, ['COMPOSER_NO_SECURITY_BLOCKING'], MoveCheck::resolves(MoveCheck::INLINE)), UpdateNow::of([], $checks));

        self::assertStringContainsString('against the lock, with advisory blocking turned off by COMPOSER_NO_SECURITY_BLOCKING; ext-* requirements not checked', self::formatter(400)->format($report));
    }

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

    /**
     * A fold counts ` · ` as 4 bytes and the closing ` ·` of every item but the last as 3.
     *
     * @return iterable<string, array{int, list<string>}>
     */
    public static function gateFolds(): iterable
    {
        yield 'one byte short of a third item and its closing separator' => [54, ['gate: fails · --fail-on=stale ·', '3 findings fail it · target php 8.4 (--target-php)']];
        yield 'a third item and its closing separator' => [55, ['gate: fails · --fail-on=stale · 3 findings fail it ·', 'target php 8.4 (--target-php)']];
        yield 'one byte short of the last item' => [84, ['gate: fails · --fail-on=stale · 3 findings fail it ·', 'target php 8.4 (--target-php)']];
        yield 'the last item, which closes with no separator' => [85, ['gate: fails · --fail-on=stale · 3 findings fail it · target php 8.4 (--target-php)']];
    }

    /**
     * @param list<string> $expected
     *
     * @dataProvider gateFolds
     */
    #[DataProvider('gateFolds')]
    public function testTheGateLineFoldsBetweenItemsAtTheWidth(int $width, array $expected): void
    {
        $told = self::report()->withRun(new RunSettings(null, null, '8.4', RunSettings::SOURCE_OPTION, null, FailOn::parse('stale'), RunSettings::SOURCE_OPTION, null));
        $lines = self::lines(self::formatter($width, 'high')->format($told));
        $gate = array_search('gate: fails', array_map(static fn (string $line): string => substr($line, 0, 11), $lines), true);
        self::assertIsInt($gate);

        self::assertSame($expected, \array_slice($lines, $gate, \count($expected)));
        self::assertStringStartsWith('Data as of ', $lines[$gate + \count($expected)]);
    }

    public function testTheContextFailOnStandsInForARunTheReportWasNotTold(): void
    {
        $report = self::report();
        $told = $report->withRun(new RunSettings(null, null, '8.4', RunSettings::SOURCE_OPTION, null, FailOn::parse('stale'), RunSettings::SOURCE_OPTION, null));

        self::assertContains('gate: fails · --fail-on=stale · 3 findings fail it · target php 8.4 (--target-php)', self::lines(self::formatter(200, 'high')->format($told)));
    }
}
