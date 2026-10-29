<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunSettings;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Config\Gate;
use Lockrot\Config\GateNotApplied;
use Lockrot\Config\GateStanding;
use Lockrot\Output\FailedValue;
use Lockrot\Output\RunGateText;
use Lockrot\Signal\Data\Check;
use Lockrot\Signal\Data\NotCheckedData;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\BaselineEntries;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\Notes;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\FindingDetails;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testTwoKnownFindingsAndTwoValuesThatNothingFailsAreCountedWithoutValues(): void
    {
        $report = self::report(self::finding('a/stale', Verdict::STALE), self::finding('b/stale', Verdict::STALE));
        $entries = [BaselineEntries::of('a/stale', '1.0.0', Verdict::STALE, '2026-01-01'), BaselineEntries::of('b/stale', '1.0.0', Verdict::STALE, '2026-01-01')];
        $known = $report->withBaseline(BaselineComparison::compare(BaselineEntries::baseline($entries), $report, 'lockrot-baseline.json', ['a/stale', 'b/stale']));

        self::assertSame('gate: passes · --fail-on=stale,vulnerable · no finding fails it; 2 reach it and are known to the baseline · target php 8.4 (--target-php)', self::text($known, 'stale,vulnerable'));
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

    /**
     * @param RunSettings::SOURCE_* $source
     * @param RunSettings::SOURCE_* $targetSource
     */
    private static function text(Report $report, ?string $failOn, string $source = RunSettings::SOURCE_OPTION, string $mode = Gate::MODE_CHECK, string $targetSource = RunSettings::SOURCE_OPTION): string
    {
        $run = new RunSettings(null, null, '8.4', $targetSource, null, $failOn === null ? null : FailOn::parse($failOn), $source, null, null, false, $mode);

        return RunGateText::render(Gate::decide($report, $run->failOn() ?? FailOn::none(), false, $mode), $run);
    }

    private static function report(Finding ...$findings): Report
    {
        return new Report(array_values($findings), [], new \DateTimeImmutable(self::AT), \count($findings), 0);
    }

    private static function finding(string $package, string $verdict): Finding
    {
        return (new FindingBuilder())->withPackage($package)->withVerdict($verdict)->withChain([$package])->build();
    }

    private static function unchecked(string $package, string $reason): Finding
    {
        $finding = (new FindingBuilder())->withPackage($package)->withSignals([new Signal(Signal::LEVEL_INFO, 'not checked', new NotCheckedData([new Check('advisories', $reason, ['S9'])]))])->withChain([$package])->build();
        $activity = FindingBuilder::activityOf($finding);

        return $finding->withDetails(new FindingDetails('read', null, null, [], ['requires' => null, 'target_runs' => null, 'project_allows' => null], null, 'not_run', $reason, [], null, $activity, FindingBuilder::ageOf($finding, 'read', $activity), null, null));
    }

    /** @return iterable<string, array{string}> every committed report-2 case and variant document */
    public static function documents(): iterable
    {
        foreach (array_merge(glob(__DIR__.'/../../fixtures/schema/documents/cases/*.json') ?: [], glob(__DIR__.'/../../fixtures/schema/documents/variants/*.json') ?: []) as $path) {
            yield basename(\dirname($path)).'/'.basename($path) => [$path];
        }
    }

    /**
     * items/C67.md §3.2: the line rendered from a document's root `gate` and `run` is its `gate.text`.
     *
     * @dataProvider documents
     */
    #[DataProvider('documents')]
    public function testTheLineOfEachModelDocumentIsItsGateText(string $path): void
    {
        $document = JsonPath::decodeFile($path);
        $run = JsonPath::arrayAt($document, ['run']);
        $failOn = FailOn::parse(JsonPath::stringAt($run, ['fail_on']));
        $settings = new RunSettings(null, null, JsonPath::stringAt($run, ['target_php']), self::source($run, 'target_php_source'), null, $failOn, self::source($run, 'fail_on_source'), null, null, JsonPath::boolAt($run, ['strict_network']), JsonPath::stringAt($run, ['mode']));

        self::assertSame(JsonPath::stringAt($document, ['gate', 'text']), RunGateText::render(self::gateOf(JsonPath::arrayAt($document, ['gate']), $failOn), $settings));
    }

    /** @return iterable<string, array{string, string}> */
    public static function reasons(): iterable
    {
        yield 'offline' => ['offline', 'not checked (offline)'];
        yield 'install_time_budget' => ['install_time_budget', 'not checked (install-time budget spent)'];
        yield 'lookup_failed' => ['lookup_failed', 'not checked (a repository failed)'];
        yield 'no_feed' => ['no_feed', 'not checked (no repository publishes advisories)'];
        yield 'not_from_composer_repository' => ['not_from_composer_repository', 'not asked (advisory-lookup: composer-repositories)'];
        yield 'unparseable_version' => ['unparseable_version', 'version not readable'];
        yield 'an unknown reason' => ['future_reason', 'future_reason'];
    }

    /** @dataProvider reasons */
    #[DataProvider('reasons')]
    public function testEachReasonHasItsWords(string $reason, string $words): void
    {
        $gate = self::gateOf(['tripped_by' => [], 'fail_on_applied' => true, 'reaching' => 0, 'failing' => 0, 'exempt' => ['baseline' => 0], 'failing_by' => [['value' => 'high', 'failing' => 0]], 'not_applied' => [['check' => 'advisories', 'packages' => 2, 'reason' => $reason]]], FailOn::parse('high'));
        $settings = new RunSettings(null, null, '8.4', RunSettings::SOURCE_OPTION, null, FailOn::parse('high'), RunSettings::SOURCE_OPTION, null);

        self::assertSame('gate: passes · --fail-on=high · no finding fails it · advisories not applied to 2 packages: '.$words.' · target php 8.4 (--target-php)', RunGateText::render($gate, $settings));
    }

    /**
     * @param array<mixed, mixed> $run
     *
     * @return RunSettings::SOURCE_*
     */
    private static function source(array $run, string $key): string
    {
        $source = JsonPath::stringAt($run, [$key]);
        foreach ([RunSettings::SOURCE_CONFIG, RunSettings::SOURCE_DEFAULT, RunSettings::SOURCE_ENV, RunSettings::SOURCE_OPTION, RunSettings::SOURCE_PLATFORM, RunSettings::SOURCE_RUNTIME] as $known) {
            if ($source === $known) {
                return $known;
            }
        }
        self::fail('an unknown source: '.$source);
    }

    /**
     * A gate with the counts of a document's root `gate`: the line reads counts only, so each failing
     * standing fails the values that `failing_by` counts for it.
     *
     * @param array<mixed, mixed> $root
     */
    private static function gateOf(array $root, FailOn $failOn): Gate
    {
        $values = [];
        foreach ($failOn->values() as $value) {
            $values[$value->value()] = $value;
        }
        $failingBy = [];
        foreach (JsonPath::arrayAt($root, ['failing_by']) as $row) {
            self::assertIsArray($row);
            $failingBy[JsonPath::stringAt($row, ['value'])] = JsonPath::intAt($row, ['failing']);
        }
        $standings = [];
        for ($i = 0; $i < JsonPath::intAt($root, ['failing']); ++$i) {
            $by = [];
            foreach ($failingBy as $value => $count) {
                if ($i < $count) {
                    $by[] = new FailedValue($values[$value], []);
                }
            }
            $standings[] = new GateStanding(true, true, null, $by);
        }
        for ($i = 0; $i < JsonPath::intAt($root, ['exempt', 'baseline']); ++$i) {
            $standings[] = new GateStanding(true, false, Gate::EXEMPT_BASELINE);
        }
        while (\count($standings) < JsonPath::intAt($root, ['reaching'])) {
            $standings[] = new GateStanding(true, false, null);
        }
        $notApplied = [];
        foreach (JsonPath::arrayAt($root, ['not_applied']) as $row) {
            self::assertIsArray($row);
            $notApplied[] = new GateNotApplied(JsonPath::stringAt($row, ['check']), JsonPath::intAt($row, ['packages']), JsonPath::stringAt($row, ['reason']));
        }
        $trippedBy = array_values(array_map(static fn ($trip): string => \is_string($trip) ? $trip : '', JsonPath::arrayAt($root, ['tripped_by'])));
        $applied = JsonPath::boolAt($root, ['fail_on_applied']);
        // Gate's constructor is private so that a gate is always decided. Only this test bypasses that.
        $build = \Closure::bind(static fn (): Gate => new Gate($trippedBy, $applied, $standings, $failOn, $notApplied), null, Gate::class);

        return $build();
    }
}
