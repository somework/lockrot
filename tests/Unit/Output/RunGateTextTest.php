<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunSettings;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Config\Gate;
use Lockrot\Output\RunGateText;
use Lockrot\Signal\Data\Check;
use Lockrot\Signal\Data\NotCheckedData;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\BaselineEntries;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\Notes;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\FindingDetails;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\TestCase;

/** The `gate:` line: the grammar and goldens of SPEC §6.2, "RunGateText". */
final class RunGateTextTest extends TestCase
{
    private const AT = '2026-09-14T00:00:00+00:00';

    public function testANoneRunIsOffAndNamesTheDefault(): void
    {
        self::assertSame('gate: off · --fail-on=none (default) · target php 8.4 (--target-php)', self::text(self::report(), null, RunSettings::SOURCE_DEFAULT));
        self::assertSame('gate: off · --fail-on=none · target php 8.4 (--target-php)', self::text(self::report(), 'none'));
    }

    public function testAFailingRunCountsTheFindingsAndNamesEachValueWithTwoOrMore(): void
    {
        $report = self::report(self::finding('a/left', Verdict::LEFT_BEHIND));

        self::assertSame('gate: fails · --fail-on=left-behind,vulnerable-high · 1 finding fails it (left-behind 1) · target php 8.4 (--target-php)', self::text($report, 'left-behind,vulnerable-high'));
        self::assertSame('gate: fails · --fail-on=stale · 1 finding fails it · target php 8.4 (--target-php)', self::text($report, 'stale'));
        self::assertSame('gate: fails · --fail-on=stale · 2 findings fail it · target php 8.4 (--target-php)', self::text(self::report(self::finding('a/left', Verdict::LEFT_BEHIND), self::finding('a/old', Verdict::STALE)), 'stale'));
    }

    public function testAPassingRunNamesTheFindingsTheBaselineKnows(): void
    {
        $report = self::report(self::finding('a/stale', Verdict::STALE));
        $known = $report->withBaseline(BaselineComparison::compare(BaselineEntries::baseline([BaselineEntries::of('a/stale', '1.0.0', Verdict::STALE, '2026-01-01')]), $report, 'lockrot-baseline.json', ['a/stale']));

        self::assertSame('gate: passes · --fail-on=stale · no finding fails it; 1 reaches it and is known to the baseline · target php 8.4 (--target-php)', self::text($known, 'stale'));
    }

    public function testAGenerateRunSaysHowManyFindingsReachIt(): void
    {
        self::assertSame('gate: not applied (--generate-baseline) · --fail-on=high · 1 finding reaches it · target php 8.4 (--target-php)', self::text(self::report(self::finding('a/gone', Verdict::ABANDONED)), 'high', RunSettings::SOURCE_OPTION, Gate::MODE_GENERATE_BASELINE));
    }

    public function testEachSourceHasItsWords(): void
    {
        $report = self::report(self::finding('a/gone', Verdict::ABANDONED));

        self::assertSame('gate: fails · --fail-on=high (LOCKROT_FAIL_ON) · 1 finding fails it · target php 8.4 (LOCKROT_TARGET_PHP)', self::text($report, 'high', RunSettings::SOURCE_ENV, Gate::MODE_CHECK, RunSettings::SOURCE_ENV));
        self::assertSame('gate: fails · --fail-on=high (extra.lockrot.fail-on) · 1 finding fails it · target php 8.4 (config.platform.php)', self::text($report, 'high', RunSettings::SOURCE_CONFIG, Gate::MODE_CHECK, RunSettings::SOURCE_PLATFORM));
        self::assertSame('gate: fails · --fail-on=high · 1 finding fails it · target php 8.4 (extra.lockrot.target-php)', self::text($report, 'high', RunSettings::SOURCE_OPTION, Gate::MODE_CHECK, RunSettings::SOURCE_CONFIG));
    }

    public function testAFailedLookupUnderStrictNetworkIsNamed(): void
    {
        $report = new Report([self::finding('a/fine', Verdict::OK)], [Notes::text('a repository failed', true)], new \DateTimeImmutable(self::AT), 1, 0);
        $run = new RunSettings(null, null, '8.4', RunSettings::SOURCE_OPTION, null, FailOn::parse('high'), RunSettings::SOURCE_OPTION, null, null, true);

        self::assertSame('gate: fails · --fail-on=high · no finding fails it · --strict-network: a lookup failed · target php 8.4 (--target-php)', RunGateText::render(Gate::decide($report, FailOn::parse('high'), true, Gate::MODE_CHECK), $run));
    }

    public function testEachGapIsOneClauseWithItsWordsAndAnUnknownReasonPrintsItsId(): void
    {
        $report = self::report(self::unchecked('a/one', 'offline'), self::unchecked('a/two', 'offline'), self::unchecked('a/three', 'later_reason'));

        self::assertSame('gate: passes · --fail-on=high · no finding fails it · advisories not applied to 2 packages: not checked (offline) · advisories not applied to 1 package: later_reason · target php 8.4 (--target-php)', self::text($report, 'high'));
    }

    private static function text(Report $report, ?string $failOn, string $source = RunSettings::SOURCE_OPTION, string $mode = Gate::MODE_CHECK, string $targetSource = RunSettings::SOURCE_OPTION): string
    {
        $run = new RunSettings(null, null, '8.4', $targetSource, null, $failOn === null ? null : FailOn::parse($failOn), $source, null, null, false, $mode);

        return RunGateText::render(Gate::decide($report, $run->failOn() ?? FailOn::none(), false, $mode), $run);
    }

    private static function report(Finding ...$findings): Report
    {
        return new Report($findings, [], new \DateTimeImmutable(self::AT), \count($findings), 0);
    }

    private static function finding(string $package, string $verdict): Finding
    {
        return (new FindingBuilder())->withPackage($package)->withVerdict($verdict)->withChain([$package])->build();
    }

    private static function unchecked(string $package, string $reason): Finding
    {
        $finding = (new FindingBuilder())->withPackage($package)->withSignals([new Signal(Signal::LEVEL_INFO, 'not checked', new NotCheckedData([new Check('advisories', $reason, ['S9'])]))])->withChain([$package])->build();
        $activity = FindingBuilder::activityOf($finding);

        return $finding->withDetails(new FindingDetails('read', null, null, [], ['requires' => null, 'target_runs' => null, 'project_allows' => null], null, 'not_run', $reason, [], null, $activity, FindingBuilder::ageOf($finding, 'read', $activity)));
    }
}
