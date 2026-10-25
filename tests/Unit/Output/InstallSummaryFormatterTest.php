<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Analyzer\PhpWindow;
use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunSettings;
use Lockrot\Baseline\Baseline;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Clock;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Output\ConsoleMarkup;
use Lockrot\Output\InstallSummaryFormatter;
use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\Notes;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The install-time block: at most ten lines, the gate line when the gate fails. */
final class InstallSummaryFormatterTest extends TestCase
{
    private const NOW = '2026-09-14T00:00:00+00:00';

    /** @return list<string> */
    private static function lines(Report $report): array
    {
        return array_map(static fn (string $line): string => ConsoleMarkup::render($line, false), (new InstallSummaryFormatter())->format($report));
    }

    /**
     * @param list<Finding> $findings
     * @param list<string>  $notes
     */
    private static function report(array $findings, array $notes = [], ?int $packagesChecked = null, bool $hadNetworkFailures = false): Report
    {
        return new Report($findings, Notes::texts($notes, $hadNetworkFailures), new \DateTimeImmutable(self::NOW), $packagesChecked ?? \count($findings), 0);
    }

    /** @param RunSettings::SOURCE_* $targetSource */
    private static function settings(string $failOn, string $targetSource = RunSettings::SOURCE_OPTION): RunSettings
    {
        return new RunSettings(null, null, $targetSource === RunSettings::SOURCE_RUNTIME ? null : '8.4', $targetSource, null, FailOn::parse($failOn), RunSettings::SOURCE_OPTION, null);
    }

    public function testTheCompositeAtHighShowsFiveFindingsTheUpdateLineAndTheGateInTenLines(): void
    {
        $lines = self::lines(CaseHydrator::composite());

        self::assertCount(10, $lines);
        self::assertSame('lockrot: 9 of 9 changed packages flagged — critical 4 · high 2 · low 3', $lines[0]);
        self::assertStringStartsWith('  critical [32] twig/twig v2.16.1: vulnerable (critical advisory; 15 in all) — raise require.php to >=8.1, then require twig/twig ^3.27.0', $lines[1]);
        self::assertSame('  critical [36] symfony/debug v3.4.47: abandoned · old-promise — replace with symfony/error-handler (named by its registry)', $lines[4]);
        self::assertSame('  … and 4 more', $lines[6]);
        self::assertSame('  update now: composer update symfony/yaml symfony/polyfill-intl-idn (2 not shown above)', $lines[7]);
        self::assertSame('  gate: fails · --fail-on=high · 6 findings fail it · target php 8.4 (--target-php)', $lines[8]);
        self::assertSame('Run composer lockrot for details.', $lines[9]);
    }

    public function testWithoutABaselineTheRuntimeTargetAddsNoNote(): void
    {
        $lines = self::lines(CaseHydrator::composite()->withRun(self::settings('high', RunSettings::SOURCE_RUNTIME)));

        self::assertSame([], preg_grep('/^  note: /', $lines));
    }

    public function testTheRuntimeTargetNoteTakesASlotWhenABaselineIsRead(): void
    {
        $report = CaseHydrator::composite();
        $report = $report->withBaseline(BaselineComparison::compare(Baseline::of([], '2026-09-14T00:00:00+00:00', '8.4', RunSettings::SOURCE_OPTION), $report, 'lockrot-baseline.json', []))->withRun(self::settings('high', RunSettings::SOURCE_RUNTIME));
        $lines = self::lines($report);

        $notes = array_values(preg_grep('/^  note: /', $lines) ?: []);
        $first = \is_string($notes[0] ?? null) ? $notes[0] : '';

        self::assertCount(10, $lines);
        self::assertMatchesRegularExpression('/^  note: target php from the running PHP \\(\\d+\\.\\d+\\); /', $first);
        self::assertStringEndsWith('); fix changes against the baseline not compared', $first);
        self::assertContains('  … and 5 more', $lines, 'the note takes the slot of one finding');
    }

    public function testTwentyFindingsThreeNotesAndAFailingGateFillTenLinesAndCountTheThirdNote(): void
    {
        $findings = [];
        for ($i = 0; $i < 20; ++$i) {
            $findings[] = (new FindingBuilder())->withPackage(\sprintf('vendor/p%02d', $i))->withVerdict(Verdict::ABANDONED)->build();
        }
        $report = self::report($findings, ['one', 'two', 'three'])->withRun(self::settings('critical'));
        $lines = self::lines($report);

        self::assertCount(10, $lines);
        self::assertSame('  … and 16 more', $lines[5]);
        self::assertSame('  gate: fails · --fail-on=critical · 20 findings fail it · target php 8.4 (--target-php)', $lines[6]);
        self::assertSame(['  note: one', '  note: two'], \array_slice($lines, 7, 2));
        self::assertSame('Run composer lockrot for details; 1 more note.', $lines[9]);
    }

    public function testAPassingGatePrintsNoGateLine(): void
    {
        $report = self::report([(new FindingBuilder())->withPackage('vendor/a')->withVerdict(Verdict::STALE)->build()])->withRun(self::settings('critical'));

        self::assertSame(['lockrot: 1 of 1 changed package flagged — medium 1', '  medium   [ 8] vendor/a 1.0.0: stale', 'Run composer lockrot for details.'], self::lines($report));
    }

    public function testExactlyAsManyFindingsAsThereAreSlotsAreAllShownWithoutACountLine(): void
    {
        $findings = [];
        for ($i = 0; $i < 8; ++$i) {
            $findings[] = (new FindingBuilder())->withPackage(\sprintf('vendor/p%d', $i))->withVerdict(Verdict::STALE)->build();
        }
        $lines = self::lines(self::report($findings));

        self::assertCount(10, $lines);
        self::assertSame([], preg_grep('/more$/', $lines));
    }

    public function testOneFindingMoreThanTheSlotsLeavesOneSlotForTheCountLine(): void
    {
        $findings = [];
        for ($i = 0; $i < 9; ++$i) {
            $findings[] = (new FindingBuilder())->withPackage(\sprintf('vendor/p%d', $i))->withVerdict(Verdict::STALE)->build();
        }
        $lines = self::lines(self::report($findings));

        self::assertCount(10, $lines);
        self::assertSame('  medium   [ 8] vendor/p6 1.0.0: stale', $lines[7]);
        self::assertSame('  … and 2 more', $lines[8]);
    }

    public function testTheUpdateLineFirstSetsAPlatformPhpOutsideTheTargetMinor(): void
    {
        $composite = CaseHydrator::composite();
        $findings = array_values(array_filter($composite->findings(), static fn (Finding $finding): bool => \in_array($finding->package(), ['symfony/yaml', 'symfony/polyfill-intl-idn'], true)));
        $window = PhpWindow::of(LockFile::fromArray(['packages' => [], 'packages-dev' => [], 'platform-overrides' => ['php' => '7.4.29']]), ProjectConfig::empty(), '8.4', PhpReleaseDates::load()->minors(Clock::fixed(self::NOW)));

        $lines = self::lines(self::report($findings)->withPhpWindow($window));

        self::assertContains('  update now: First set config.platform.php to your PHP 8.4 release: the lock records 7.4.29. composer update symfony/yaml symfony/polyfill-intl-idn', $lines);
    }

    public function testTheUpdateLineCountsOnlyThePackagesThatTheBlockDoesNotShow(): void
    {
        $composite = CaseHydrator::composite();
        $findings = array_values(array_filter($composite->findings(), static fn (Finding $finding): bool => \in_array($finding->package(), ['symfony/yaml', 'symfony/polyfill-intl-idn'], true)));
        $lines = self::lines(self::report($findings));

        self::assertContains('  update now: composer update symfony/yaml symfony/polyfill-intl-idn', $lines);
    }

    public function testOneAdvisoryNamesItsSeverityAndSeveralLowOnesAreCounted(): void
    {
        $composite = CaseHydrator::composite();
        $findings = array_values(array_filter($composite->findings(), static fn (Finding $finding): bool => \in_array($finding->package(), ['symfony/yaml'], true)));
        $joomla = array_values(array_filter(CaseHydrator::everyCase()->findings(), static fn (Finding $finding): bool => $finding->package() === 'joomla/filter'));

        self::assertStringStartsWith('  low      [ 2] symfony/yaml v5.4.23: vulnerable (3 low advisories) — ', self::lines(self::report($findings))[1]);
        self::assertStringContainsString(': vulnerable (1 medium advisory)', self::lines(self::report($joomla))[1]);
    }

    /** The finding line of a medium grade holds 152 characters after the grade, then it is cut. */
    public function testAFindingLineIsCutOnlyPastItsLimit(): void
    {
        $fits = (new FindingBuilder())->withPackage('vendor/'.str_repeat('a', 124))->withVerdict(Verdict::STALE)->build();
        $over = (new FindingBuilder())->withPackage('vendor/'.str_repeat('a', 125))->withVerdict(Verdict::STALE)->build();

        self::assertSame('  medium   [ 8] vendor/'.str_repeat('a', 124).' 1.0.0: stale', self::lines(self::report([$fits]))[1]);
        self::assertSame('  medium   [ 8] vendor/'.str_repeat('a', 125).' 1.0.0:…', self::lines(self::report([$over]))[1]);
    }

    public function testALongMoveIsCutAtAWordBoundary(): void
    {
        foreach (self::lines(CaseHydrator::composite()) as $line) {
            self::assertLessThanOrEqual(InstallSummaryFormatter::MAX_FINDING_LINE + \strlen('…'), \strlen($line), $line);
        }
        self::assertStringEndsWith(' clears 15…', self::lines(CaseHydrator::composite())[1]);
    }

    public function testNothingGradedAndNothingFailedProducesNoLinesAtAll(): void
    {
        self::assertSame([], (new InstallSummaryFormatter())->format(self::report([(new FindingBuilder())->withPackage('vendor/a')->build()])));
    }

    public function testNothingGradedButAFailedLookupReportsTheUncheckedPackages(): void
    {
        $report = self::report([(new FindingBuilder())->withPackage('vendor/a')->withVerdict(Verdict::UNKNOWN)->build()], ['metadata unavailable', 'two', 'three'], 1, true);

        self::assertSame(['lockrot: 1 of 1 changed package could not be checked', '  note: metadata unavailable', '  note: two', 'Run composer lockrot for details.'], self::lines($report));
    }

    public function testNothingGradedButAFailingGatePrintsTheGateLine(): void
    {
        $report = self::report([FindingBuilder::advisoriesUnchecked('vendor/vcs', 'not_from_composer_repository', true)])->withRun(self::settings('unchecked'));
        $lines = self::lines($report);

        self::assertSame('lockrot: 1 changed package checked, one check incomplete', $lines[0]);
        self::assertStringStartsWith('  gate: fails · --fail-on=unchecked · ', $lines[1]);
        self::assertSame('Run composer lockrot for details.', $lines[\count($lines) - 1]);
    }

    public function testALookupThatFailedWithNothingUnknownSaysSoInsteadOfCountingZero(): void
    {
        $report = self::report([(new FindingBuilder())->withPackage('vendor/a')->build()], ['advisories unavailable'], 3, true);

        self::assertSame('lockrot: 3 changed packages checked, one check incomplete', self::lines($report)[0]);
    }

    /**
     * Text from the lock prints as written and obeys nothing in it.
     *
     * @dataProvider textsThatLookLikeMarkup
     */
    #[DataProvider('textsThatLookLikeMarkup')]
    public function testTextThatLooksLikeMarkupPrintsAsWrittenAndLeavesNoStyleBehind(string $text): void
    {
        $lines = (new InstallSummaryFormatter())->format(self::report([(new FindingBuilder())->withPackage('vendor/'.$text)->withVerdict(Verdict::ABANDONED)->build()], ['note '.$text]));
        $plain = implode("\n", array_map(static fn (string $line): string => ConsoleMarkup::render($line, false), $lines));

        self::assertStringContainsString('vendor/'.$text.' 1.0.0: abandoned', $plain);
        self::assertStringContainsString('  note: note '.$text, $plain);
    }

    /** @return iterable<string, array{string}> */
    public static function textsThatLookLikeMarkup(): iterable
    {
        yield 'a style tag' => ['<<fg=red>>'];
        yield 'an escaped tag' => ['a\\<b'];
    }

    public function testControlCharactersInTheProjectsTextAreShownRatherThanObeyed(): void
    {
        $plain = implode("\n", self::lines(self::report([(new FindingBuilder())->withPackage("vendor/\x1b[31mred")->withVerdict(Verdict::ABANDONED)->build()])));

        self::assertStringNotContainsString("\x1b", $plain);
    }
}
