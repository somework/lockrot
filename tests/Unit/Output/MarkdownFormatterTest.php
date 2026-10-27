<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Analyzer\MoveCheckRun;
use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunNote;
use Lockrot\Analyzer\UpdateNow;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Config\LockrotConfig;
use Lockrot\Data\Abandoned\AbandonedIgnoreRule;
use Lockrot\Data\Advisory\AdvisoryCoverage;
use Lockrot\Data\Advisory\AdvisoryRepository;
use Lockrot\Exception\ConfigException;
use Lockrot\Output\FormatContext;
use Lockrot\Output\Formatters;
use Lockrot\Output\MarkdownFormatter;
use Lockrot\Signal\AbandonedIgnored;
use Lockrot\Signal\Signal;
use Lockrot\Solver\MoveCheck;
use Lockrot\Solver\UncheckedMoves;
use Lockrot\Tests\Support\BaselineEntries;
use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\Notes;
use Lockrot\Tests\Support\SignalDataStub;
use Lockrot\Tests\Support\TextGolden;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\MoveChecks;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\TestCase;

/** The `markdown` format, and `--markdown-max-bytes`. */
final class MarkdownFormatterTest extends TestCase
{
    private const AT = '2026-09-14T06:00:00+00:00';
    private const ACTION_SUMMARY_LIMIT = 1000000;

    private static ?Report $composite = null;

    private static function composite(): Report
    {
        return self::$composite ??= CaseHydrator::composite();
    }

    private static function formatter(string $failOn = LockrotConfig::FAIL_ON_NONE, ?int $maxBytes = null): MarkdownFormatter
    {
        return new MarkdownFormatter(FormatContext::create(null, $failOn)->withMarkdownMaxBytes($maxBytes));
    }

    /** doctrine/cache, graded `stale`, whose abandoned marking the project's policy ignores. */
    private static function markedReport(): Report
    {
        $finding = (new FindingBuilder())->withPackage('doctrine/cache')->withVerdict(Verdict::STALE)->build();
        $ignored = new AbandonedIgnored('policy.abandoned', [new AbandonedIgnoreRule('doctrine/*', 'kept until 9.0', [])], AbandonedIgnored::MARKED_BY_REPOSITORY, null, null);

        return new Report([$finding->withDetails(FindingBuilder::detailsOf($finding, true, null, null, $ignored))], [], new \DateTimeImmutable('2026-10-01T00:00:00+00:00'), 1, 0);
    }

    public function testTheIgnoredAbandonedMarkingSetsItsLabelInItalics(): void
    {
        $out = (new MarkdownFormatter(FormatContext::create(null, 'none')))->format(self::markedReport(), true);

        self::assertStringContainsString('*abandoned marking ignored* (policy.abandoned: doctrine/\\*): kept until 9.0', $out);
    }

    public function testTheCompositeRendersAsItsGolden(): void
    {
        TextGolden::assertMatches('composite.markdown.md', self::formatter('high')->format(self::composite()), __METHOD__);
    }

    public function testABudgetKeepsEveryRowThatFitsAndNeverAByteMore(): void
    {
        $findings = [];
        for ($i = 0; $i < 30; ++$i) {
            $findings[] = (new FindingBuilder())->withPackage(\sprintf('vendor/p%02d', $i))->withVerdict(Verdict::STALE)->build();
        }
        $report = new Report($findings, [], new \DateTimeImmutable('2026-10-08T00:00:00+00:00'), 30, 0);
        $full = \strlen(self::formatter()->format($report));
        for ($budget = $full - 500; $budget < $full; $budget += 7) {
            self::assertLessThanOrEqual($budget, \strlen(self::formatter(LockrotConfig::FAIL_ON_NONE, $budget)->format($report)), 'budget '.$budget);
        }
        // 12 of 30 left out: the count has as many digits as the 30 that the cut reserves room for.
        $cut = self::formatter(LockrotConfig::FAIL_ON_NONE, $full - 1600)->format($report);

        self::assertStringContainsString('| … | 12 more findings: see the JSON report', $cut);
        self::assertSame($cut, self::formatter(LockrotConfig::FAIL_ON_NONE, \strlen($cut))->format($report), 'a budget of exactly its size keeps every row');
    }

    public function testABudgetBelowTheSkeletonCutsEveryRowAndKeepsTheSkeleton(): void
    {
        $out = self::formatter('high', 100)->format(self::composite());

        self::assertStringStartsWith('### lockrot: ', $out);
        self::assertStringContainsString("\n| … | 9 more findings: see the JSON report | | | | |\n", $out);
        self::assertStringNotContainsString('`twig/twig`', $out);
        self::assertGreaterThan(100, \strlen($out), 'the skeleton always prints');
    }

    public function testTheHeadingCountsTheGradesAndTheGateLineFollowsInBold(): void
    {
        $lines = explode("\n", self::formatter('high')->format(self::composite()));

        self::assertSame('### lockrot: 9 of 9 packages flagged — critical 4 · high 2 · low 3', $lines[0]);
        self::assertSame('**gate: fails** · --fail-on=high · 6 findings fail it · target php 8.4 (--target-php)', $lines[2]);
        self::assertSame('| Verdict | Package | Version | Flags | Why | Via |', $lines[4]);
    }

    public function testACleanRunHasNoTableAndSaysNothingIsFlagged(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $out = self::formatter()->format(new Report([(new FindingBuilder())->withPackage('a/fine')->withDataDate($at)->build()], [], $at, 1, 0));

        self::assertStringStartsWith("### lockrot: nothing flagged in 1 package\n\n**gate: off** · ", $out);
        self::assertStringNotContainsString('| Verdict |', $out);
        self::assertStringEndsWith("<sub>1 package checked — data as of 2026-09-14 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>\n", $out);
    }

    public function testAOnePackageRunCountsItsPackageInTheSingular(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([(new FindingBuilder())->withPackage('a/gone')->withDataDate($at)->withSignals([new Signal(Signal::LEVEL_HIGH, 'x', SignalDataStub::of(Signal::S1, []))])->build()], [], $at, 1, 0);

        self::assertStringStartsWith('### lockrot: 1 of 1 package flagged — ', self::formatter()->format($report));
    }

    public function testACleanOfflineRunSaysInItsHeadingThatTheAdvisoriesWereNotChecked(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $fine = [(new FindingBuilder())->withPackage('a/fine')->withDataDate($at)->build()];

        self::assertStringStartsWith("### lockrot: nothing flagged in 1 package — security advisories not checked (offline)\n", self::formatter()->format(new Report($fine, [RunNote::advisoriesNotChecked(RunNote::ADVISORIES_OFFLINE, 0)], $at, 1, 0)));
        self::assertStringStartsWith("### lockrot: nothing flagged in 1 package\n", self::formatter()->format(new Report($fine, [RunNote::advisoriesNotChecked(RunNote::INSTALL_TIME_BUDGET, 0)], $at, 1, 0)));
    }

    public function testTheVerdictIsBoldWhereTheFindingFailsTheGateAndTheBaselineDoesNotKnowIt(): void
    {
        $report = self::report();
        $known = $report->withBaseline(BaselineComparison::compare(BaselineEntries::baseline([BaselineEntries::of('a/gone', '2.2.0', Verdict::ABANDONED, '2026-01-15')]), $report, 'lockrot-baseline.json', ['a/gone', 'a/deep']));

        self::assertStringContainsString('| **critical** | `a/gone` |', self::formatter('high')->format($report));
        self::assertStringContainsString('| critical | `a/gone` |', self::formatter('none')->format($report));
        self::assertStringContainsString('| critical | `a/gone` | 2.2.0 | abandoned (baseline) | `32 = abandoned 32 (baseline: known)`<br>`gate 0 = min(32, 2 × 0) (the new facts bound it); new: 0`', self::formatter('high')->format($known));
    }

    public function testTheViaCellNamesTheDirectDependents(): void
    {
        self::assertStringContainsString('| low | `a/deep` | 1.0.0 | stale |', self::formatter()->format(self::report()));
        self::assertMatchesRegularExpression('/\| `a\/deep` .* \| a\/root, a\/other \|$/m', self::formatter()->format(self::report()));
    }

    public function testTheViaCellNamesAtMostThreeDirectDependents(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([(new FindingBuilder())->withPackage('a/leaf')->withVerdict(Verdict::STALE)->withChain(['a/r1', 'a/leaf'])->withDirectDependents(['a/r1', 'a/r2', 'a/r3', 'a/r4', 'a/r5'])->withDataDate($at)->build()], [], $at, 1, 0);

        self::assertMatchesRegularExpression('/\| `a\/leaf` .* \| a\/r1, a\/r2, a\/r3 and 2 more \|$/m', self::formatter()->format($report));
    }

    public function testTheViaCellOfAnUnreachedFindingSaysUnreached(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([(new FindingBuilder())->withPackage('a/orphan')->withVerdict(Verdict::STALE)->withChain([])->withDataDate($at)->build()], [], $at, 1, 0);

        self::assertMatchesRegularExpression('/\| `a\/orphan` .* \| unreached \|$/m', self::formatter()->format($report));
    }

    public function testShowAllAddsTheScoreZeroRows(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([(new FindingBuilder())->withPackage('a/fine')->withDataDate($at)->build()], [], $at, 1, 0);

        self::assertStringContainsString('| ok | `a/fine` | 1.0.0 | ok | `0` | direct |', self::formatter()->format($report, true));
    }

    /** docs/ci.md#unchecked-in-the-formats: `table` and `markdown` list a score-0 finding only with `--all`. */
    public function testAScoreZeroFindingThatFailsUncheckedHasNoRowWithoutShowAll(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([
            (new FindingBuilder())->withPackage('a/stale')->withVerdict(Verdict::STALE)->withDataDate($at)->build(),
            FindingBuilder::advisoriesUnchecked('a/offline', 'offline', true),
        ], [], $at, 2, 0);

        $out = self::formatter('high,unchecked')->format($report);

        self::assertStringContainsString('| `a/stale` |', $out);
        self::assertStringNotContainsString('`a/offline`', $out);
        self::assertStringContainsString('| `a/offline` |', self::formatter('high,unchecked')->format($report, true));
    }

    public function testNotesAreABulletListBeforeTheSubLine(): void
    {
        $out = self::formatter()->format(self::report());

        self::assertStringContainsString("\n\n- note: GitHub token not set\n\n<sub>", $out);
    }

    /**
     * A composer.lock is under the control of whoever opens the pull request, and the report is
     * posted into that pull request or its job summary: nothing a package carries can render as
     * markup there.
     */
    public function testPackageMetadataCannotInjectMarkup(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([
            (new FindingBuilder())->withPackage('acme/ev`il')->withVersion('1.0.0 <b>x</b>')->withVerdict(Verdict::ABANDONED)->withChain(['acme/ev`il'])->withDataDate($at)->build(),
            (new FindingBuilder())->withPackage('acme/kept')->withSignals([new Signal(Signal::LEVEL_WARN, 'x', SignalDataStub::of(Signal::S2, []))])->withAllowlistReason('<img src=x onerror=alert(1)> [fine](https://example.com) *ok* &lt;b&gt; #1 a|b')->withDataDate($at)->build(),
        ], Notes::texts(['GitHub unreachable: <script>alert(1)</script> [x](y)']), $at, 2, 0);

        $out = self::formatter()->format($report, true);

        self::assertStringContainsString('| `` acme/ev`il `` | 1.0.0 \<b\>x\</b\> |', $out);
        self::assertStringContainsString('\<img src=x onerror=alert(1)\> \[fine\](https\://example.com) \*ok\* \&lt;b\&gt; \#1 a\|b', $out);
        self::assertStringContainsString('- note: GitHub unreachable: \<script\>alert(1)\</script\> \[x\](y)', $out);
        self::assertSame(0, preg_match('/(?<!\\\\)<(img|script|b>)/', $out), 'every < from package metadata is escaped');
    }

    /** A job summary or a terminal that shows the comment can obey a control character, so each prints as an escape. */
    public function testControlCharactersFromOutsidePrintAsEscapes(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $evil = "evil\x1b]52;c;cHduZWQ=\x07\x1b[2J\u{9B}31m";
        $report = new Report([
            (new FindingBuilder())->withPackage('vendor/'.$evil)->withVerdict(Verdict::ABANDONED)->withChain(['vendor/'.$evil])->withDataDate($at)->build(),
        ], Notes::texts(['note '.$evil]), $at, 1, 0);

        $out = self::formatter()->format($report);

        self::assertSame(0, preg_match('/[\x00-\x09\x0B-\x1F\x7F]|\xC2[\x80-\x9F]/', $out), 'no control character but the line feed');
        self::assertStringContainsString('- note: note evil\\\\x1B\\]52;c;cHduZWQ=\\\\x07\\\\x1B\\[2J\\\\u{009B}31m', $out);
    }

    /**
     * In a fork's pull request the lock is the author's: a mention notifies a user or a team, and a
     * bare URL renders as a link inside lockrot's comment. A zero-width space after `@` breaks a
     * mention and an email link, and an escaped `:` or `.` breaks a URL link.
     */
    public function testLockTextCannotMentionOrLink(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([
            (new FindingBuilder())->withPackage('acme/kept')->withSignals([new Signal(Signal::LEVEL_WARN, 'x', SignalDataStub::of(Signal::S2, []))])->withAllowlistReason('@github/security-team see www.evil.example/login or https://evil.example/x and mail me@evil.example')->withDataDate($at)->build(),
        ], Notes::texts(['ask @octocat at http://evil.example']), $at, 1, 0);

        $out = self::formatter()->format($report, true);

        self::assertStringContainsString('@&#8203;github/security-team see www\\.evil.example/login or https\\://evil.example/x and mail me@&#8203;evil.example', $out);
        self::assertStringContainsString('- note: ask @&#8203;octocat at http\\://evil.example', $out);
        self::assertSame(0, preg_match('/@(?!&#8203;)|www\\.|(?<!\\\\):\/\//i', $out), 'no live mention, www link or scheme link');
    }

    /**
     * GitHub renders `$…$` as math and `:name:` as an emoji, and a backslash or an entity stops
     * neither. A `$` in a span and a zero-width space after the first `:` print the text as written.
     */
    public function testLockTextCannotRenderAsMathOrAnEmoji(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([
            (new FindingBuilder())->withPackage('acme/kept')->withSignals([new Signal(Signal::LEVEL_WARN, 'x', SignalDataStub::of(Signal::S2, []))])->withAllowlistReason('see $x^{10}$ or $$y$$ :rotating_light: at 10:30:00')->withDataDate($at)->build(),
        ], Notes::texts(['run now :x: for $prefix']), $at, 1, 0);

        $out = self::formatter()->format($report, true);

        self::assertStringContainsString('see <span>$</span>x^{10}<span>$</span> or <span>$</span><span>$</span>y<span>$</span><span>$</span> :&#8203;rotating\_light: at 10:&#8203;30:00', $out);
        self::assertStringContainsString('- note: run now :&#8203;x: for <span>$</span>prefix', $out);
        self::assertSame(0, preg_match('/(?<!<span>)\$|:(?:[A-Za-z0-9+-]|\\\\_)+:/', $out), 'no live $ and no shortcode: '.$out);
    }

    /**
     * A workflow can print the comment to the job log, where the runner reads a legacy `##[`
     * command anywhere in a line. A code span carries a name from the lock as written.
     */
    public function testACodeSpanBreaksTheLegacyLogCommand(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([(new FindingBuilder())->withPackage('evil/a##[error]x')->withVerdict(Verdict::ABANDONED)->withChain(['evil/a##[error]x'])->withDataDate($at)->build()], [], $at, 1, 0);

        $out = self::formatter()->format($report);

        self::assertStringContainsString('| `evil/a##\x5Berror]x` |', $out);
        self::assertStringNotContainsString('##[', $out);
    }

    /**
     * A footer line names packages as code spans. Text from a repository or the lock on any footer
     * line cannot stand in for one of them.
     */
    public function testFooterTextCannotForgeAPackageCodeSpan(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $findings = array_values(array_filter(CaseHydrator::composite()->findings(), static fn (Finding $finding): bool => $finding->package() === 'symfony/polyfill-intl-idn'));
        $forged = AdvisoryRepository::of('composer repo (https://repo.packagist.org)', AdvisoryRepository::FAILED, AdvisoryCoverage::TRANSPORT, "\\x000\\x00 \\x001\\x00 \x000\x00");
        $report = new Report($findings, [], $at, 1, 0, null, null, false, new AdvisoryCoverage(AdvisoryCoverage::SCOPE_ALL, AdvisoryCoverage::SOURCE_DEFAULT, [$forged], 0, []));

        $out = self::formatter()->format($report, true);

        self::assertStringContainsString('deepest: `symfony/polyfill-intl-idn`', $out, 'the footer names a package as a span');
        $advisories = array_values(array_filter(explode("\n", $out), static fn (string $line): bool => strncmp($line, 'advisories: ', 12) === 0));
        self::assertCount(1, $advisories, $out);
        self::assertStringContainsString('lookup failed (network error: \\\\x000\\\\x00 \\\\x001\\\\x00 \\\\x000\\\\x00)', $advisories[0]);
        self::assertStringNotContainsString('`', $advisories[0]);
    }

    /**
     * An `@` after a letter or a digit starts no mention, and with no dotted domain after it no
     * email link (https://github.github.com/gfm/#extended-email-autolink). A constraint keeps its
     * stability flag as written, so a reader can copy it.
     */
    public function testAStabilityFlagInAConstraintStaysAsWritten(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([
            (new FindingBuilder())->withPackage('acme/kept')->withSignals([new Signal(Signal::LEVEL_WARN, 'x', SignalDataStub::of(Signal::S2, []))])->withAllowlistReason('requires ~0.4@dev, (@octocat), a@b.example and ^1.0@beta.1')->withDataDate($at)->build(),
        ], Notes::texts([]), $at, 1, 0);

        $out = self::formatter()->format($report, true);

        self::assertStringContainsString('requires \\~0.4@dev, (@&#8203;octocat), a@&#8203;b.example and ^1.0@&#8203;beta.1', $out);
    }

    public function testEveryKindOfLineBreakInACellFoldsIntoOneSpace(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $package = "acme/pk\r\ng|x";
        $report = new Report([
            (new FindingBuilder())->withPackage($package)->withVerdict(Verdict::ABANDONED)->withChain([$package])->withDataDate($at)->build(),
        ], Notes::texts(["note\r\nline"]), $at, 1, 0);

        $out = self::formatter()->format($report);

        self::assertStringContainsString('| critical | `acme/pk g\|x` | 1.0.0 |', $out);
        self::assertStringContainsString('- note: note line', $out);
    }

    public function testABudgetKeepsTheHeadAndTheFooterAndCutsRowsWholeInRankOrder(): void
    {
        $full = self::formatter('high')->format(self::composite());
        $cut = self::formatter('high', \strlen($full) - 1)->format(self::composite());
        $rows = preg_grep('/^\| (\*\*)?(critical|high|medium|low)/', explode("\n", $cut)) ?: [];

        self::assertLessThanOrEqual(\strlen($full) - 1, \strlen($cut));
        self::assertStringContainsString("\n| … | ", $cut);
        self::assertSame(8, \count($rows));
        self::assertStringContainsString('| … | 1 more finding: see the JSON report | | | | |', $cut);
        self::assertStringStartsWith('### lockrot: 9 of 9 packages flagged', $cut);
        self::assertStringEndsWith("for details.</sub>\n", $cut);
        self::assertSame($full, self::formatter('high', \strlen($full))->format(self::composite()), 'a document that fits is written whole');
    }

    public function testTwoThousandGradedFindingsStayWithinTheJobSummaryLimitAndCountWhatIsLeftOut(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $findings = [];
        for ($i = 0; $i < 2000; ++$i) {
            $findings[] = (new FindingBuilder())->withPackage(\sprintf('vendor/package-%04d', $i))->withVersion('1.0.0-'.str_repeat('x', 600))->withVerdict(Verdict::ABANDONED)->withDataDate($at)->build();
        }
        $report = new Report($findings, [], $at, 2000, 0);
        $out = self::formatter('high', self::ACTION_SUMMARY_LIMIT)->format($report);
        $rows = \count(preg_grep('/^\| \*\*critical\*\* \| `vendor\//', explode("\n", $out)) ?: []);

        self::assertLessThanOrEqual(self::ACTION_SUMMARY_LIMIT, \strlen($out));
        self::assertSame(1, preg_match('/^\| … \| (\d+) more findings: see the JSON report \| \| \| \| \|$/m', $out, $m));
        self::assertSame(2000, $rows + (int) ($m[1] ?? 0));
        self::assertGreaterThan(0, $rows);
    }

    public function testNoBudgetWritesEveryRow(): void
    {
        self::assertStringNotContainsString('more findings: see the JSON report', self::formatter('high')->format(self::composite()));
    }

    public function testTheDocumentEndsWithExactlyOneNewline(): void
    {
        $out = self::formatter()->format(self::report());

        self::assertStringEndsWith("</sub>\n", $out);
        self::assertStringEndsNotWith("\n\n", $out);
    }

    public function testFactory(): void
    {
        self::assertInstanceOf(MarkdownFormatter::class, Formatters::for('markdown', FormatContext::create(null, LockrotConfig::FAIL_ON_NONE)));
    }

    public function testFactoryStillRejectsAnUnknownFormat(): void
    {
        $this->expectException(ConfigException::class);
        Formatters::for('xml', FormatContext::create(null, LockrotConfig::FAIL_ON_NONE));
    }

    public function testTheMovesLineNamesTheVariableThatTurnedTheBlockingOff(): void
    {
        $checks = new MoveChecks(new UncheckedMoves(MoveCheck::NO_COMPOSER_JSON), true);
        $report = self::report()->withMoveChecks(new MoveCheckRun('2.9.5', false, ['COMPOSER_NO_SECURITY_BLOCKING'], MoveCheck::resolves(MoveCheck::INLINE)), UpdateNow::of([], $checks));

        self::assertStringContainsString('against the lock, with advisory blocking turned off by `COMPOSER_NO_SECURITY_BLOCKING`; ext-\\* requirements not checked', self::formatter()->format($report));
    }

    private static function report(): Report
    {
        $at = new \DateTimeImmutable(self::AT);

        return new Report([
            (new FindingBuilder())->withPackage('a/gone')->withVersion('2.2.0')->withVerdict(Verdict::ABANDONED)->withDataDate($at)->build(),
            (new FindingBuilder())->withPackage('a/deep')->withVerdict(Verdict::STALE)->withChain(['a/root', 'a/deep'])->withDirectDependents(['a/root', 'a/other'])->withDataDate($at)->build(),
        ], Notes::texts(['GitHub token not set']), $at, 2, 0);
    }
}
