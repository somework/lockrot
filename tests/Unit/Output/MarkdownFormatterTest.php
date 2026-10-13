<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Config\LockrotConfig;
use Lockrot\Exception\ConfigException;
use Lockrot\Output\FormatContext;
use Lockrot\Output\Formatters;
use Lockrot\Output\MarkdownFormatter;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\BaselineEntries;
use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\Notes;
use Lockrot\Tests\Support\SignalDataStub;
use Lockrot\Tests\Support\TextGolden;
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
        self::assertStringContainsString("\n| … | 7 more findings: see the JSON report | | | | |\n", $out);
        self::assertStringNotContainsString('`twig/twig`', $out);
        self::assertGreaterThan(100, \strlen($out), 'the skeleton always prints');
    }

    public function testTheHeadingCountsTheGradesAndTheGateLineFollowsInBold(): void
    {
        $lines = explode("\n", self::formatter('high')->format(self::composite()));

        self::assertSame('### lockrot: 7 of 7 packages flagged — critical 3 · high 2 · low 2', $lines[0]);
        self::assertSame('**gate: fails** · --fail-on=high · 5 findings fail it · target php 8.4 (--target-php)', $lines[2]);
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

    public function testShowAllAddsTheScoreZeroRows(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([(new FindingBuilder())->withPackage('a/fine')->withDataDate($at)->build()], [], $at, 1, 0);

        self::assertStringContainsString('| ok | `a/fine` | 1.0.0 | ok | `0` | direct |', self::formatter()->format($report, true));
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
        self::assertStringContainsString('\<img src=x onerror=alert(1)\> \[fine\](https://example.com) \*ok\* \&lt;b\&gt; \#1 a\|b', $out);
        self::assertStringContainsString('- note: GitHub unreachable: \<script\>alert(1)\</script\> \[x\](y)', $out);
        self::assertSame(0, preg_match('/(?<!\\\\)<(img|script|b>)/', $out), 'every < from package metadata is escaped');
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
        self::assertSame(6, \count($rows));
        self::assertStringContainsString('| … | 1 more finding: see the JSON report | | | | |', $cut);
        self::assertStringStartsWith('### lockrot: 7 of 7 packages flagged', $cut);
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

    private static function report(): Report
    {
        $at = new \DateTimeImmutable(self::AT);

        return new Report([
            (new FindingBuilder())->withPackage('a/gone')->withVersion('2.2.0')->withVerdict(Verdict::ABANDONED)->withDataDate($at)->build(),
            (new FindingBuilder())->withPackage('a/deep')->withVerdict(Verdict::STALE)->withChain(['a/root', 'a/deep'])->withDirectDependents(['a/root', 'a/other'])->withDataDate($at)->build(),
        ], Notes::texts(['GitHub token not set']), $at, 2, 0);
    }
}
