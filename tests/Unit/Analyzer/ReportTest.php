<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Analyzer;

use Composer\Downloader\TransportException;
use Lockrot\Analyzer\Libyears;
use Lockrot\Analyzer\LibyearsMeasurement;
use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunNote;
use Lockrot\Analyzer\RunSettings;
use Lockrot\Analyzer\TransitiveExposure;
use Lockrot\Baseline\Baseline;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Baseline\BaselineEntry;
use Lockrot\Config\Gate;
use Lockrot\Legacy\Priority013;
use Lockrot\Signal\Signal;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\TestCase;

final class ReportTest extends TestCase
{
    private function finding(string $package, string $verdict): Finding
    {
        return (new FindingBuilder())->withPackage($package)->withVerdict($verdict)->withChain([$package])->build();
    }

    /** A transitive finding: the chain is a root plus the package itself, so isDirect() is false. */
    private function transitive(string $package, string $verdict, bool $dev = false): Finding
    {
        return (new FindingBuilder())->withPackage($package)->withVerdict($verdict)->withChain(['vendor/root', $package])->withDev($dev)->build();
    }

    private function report(Finding ...$findings): Report
    {
        return new Report(array_values($findings), [], new \DateTimeImmutable('2026-09-14T00:00:00+00:00'), \count($findings), 0);
    }

    /** @return list<string> */
    private function names(Report $report): array
    {
        return array_map(static fn (Finding $f): string => $f->package(), $report->findings());
    }

    public function testFindingsAreSortedByPriorityThenSeverityThenDirectThenName(): void
    {
        // abandoned transitive dev -> medium, pinned direct prod -> high, so the pinned row sorts first
        // even though abandoned outranks pinned on verdict severity.
        $report = $this->report(
            $this->transitive('vendor/abandoned-transitive-dev', Verdict::ABANDONED, true),
            $this->finding('vendor/pinned-direct', Verdict::PINNED),
            $this->finding('vendor/abandoned-direct', Verdict::ABANDONED),
            $this->finding('vendor/ok', Verdict::OK)
        );

        self::assertSame(
            ['vendor/abandoned-direct', 'vendor/pinned-direct', 'vendor/abandoned-transitive-dev', 'vendor/ok'],
            $this->names($report)
        );
    }

    public function testWithinOnePriorityTheHigherVerdictSeverityComesFirst(): void
    {
        // Both are high: silent transitive prod and pinned direct prod. Silent outranks pinned.
        $report = $this->report(
            $this->finding('vendor/pinned-direct', Verdict::PINNED),
            $this->transitive('vendor/silent-transitive', Verdict::SILENT)
        );

        self::assertSame(['vendor/silent-transitive', 'vendor/pinned-direct'], $this->names($report));
    }

    public function testWithinOnePriorityAndVerdictDirectComesBeforeTransitive(): void
    {
        // Both dev and stale, so both land on the `low` floor: the only thing left to order them by
        // is direct-before-transitive, which beats the alphabetical package name.
        $report = $this->report(
            $this->transitive('vendor/aaa-transitive', Verdict::STALE, true),
            (new FindingBuilder())->withPackage('vendor/zzz-direct')->withVerdict(Verdict::STALE)->withChain(['vendor/zzz-direct'])->withDev(true)->build()
        );

        self::assertSame(['vendor/zzz-direct', 'vendor/aaa-transitive'], $this->names($report));
    }

    public function testBySeverityStillBreaksTiesByPackageNameAscending(): void
    {
        $report = new Report(
            [
                $this->finding('vendor/ok-b', Verdict::OK),
                $this->finding('vendor/silent-b', Verdict::SILENT),
                $this->finding('vendor/abandoned-b', Verdict::ABANDONED),
                $this->finding('vendor/silent-a', Verdict::SILENT),
                $this->finding('vendor/abandoned-a', Verdict::ABANDONED),
                $this->finding('vendor/ok-a', Verdict::OK),
            ],
            [],
            new \DateTimeImmutable('2026-09-14T00:00:00+00:00'),
            6,
            0
        );

        self::assertSame(
            ['vendor/abandoned-a', 'vendor/abandoned-b', 'vendor/silent-a', 'vendor/silent-b', 'vendor/ok-a', 'vendor/ok-b'],
            array_map(static fn (Finding $f): string => $f->package(), $report->findings())
        );
    }

    public function testFlaggedExcludesOkFinishedAndUnknown(): void
    {
        $report = new Report(
            [
                $this->finding('vendor/a', Verdict::ABANDONED),
                $this->finding('vendor/b', Verdict::SILENT),
                $this->finding('vendor/c', Verdict::PINNED),
                $this->finding('vendor/d', Verdict::OLD_PROMISE),
                $this->finding('vendor/e', Verdict::STALE),
                $this->finding('vendor/f', Verdict::UNKNOWN),
                $this->finding('vendor/g', Verdict::FINISHED),
                $this->finding('vendor/h', Verdict::OK),
            ],
            [],
            new \DateTimeImmutable('2026-09-14T00:00:00+00:00'),
            8,
            0
        );

        self::assertSame(
            ['vendor/a', 'vendor/b', 'vendor/c', 'vendor/d', 'vendor/e'],
            array_map(static fn (Finding $f): string => $f->package(), $report->flagged())
        );
    }

    public function testByVerdictHasAllNineKeysWithZeros(): void
    {
        $report = new Report([], [], new \DateTimeImmutable('2026-09-14T00:00:00+00:00'), 0, 0);
        self::assertSame(
            [
                Verdict::ABANDONED => 0,
                Verdict::SILENT => 0,
                Verdict::PINNED => 0,
                Verdict::LEFT_BEHIND => 0,
                Verdict::OLD_PROMISE => 0,
                Verdict::STALE => 0,
                Verdict::UNKNOWN => 0,
                Verdict::FINISHED => 0,
                Verdict::OK => 0,
            ],
            $report->byVerdict()
        );
    }

    public function testByVerdictCountsFindings(): void
    {
        $report = new Report(
            [$this->finding('vendor/a', Verdict::SILENT), $this->finding('vendor/b', Verdict::SILENT), $this->finding('vendor/c', Verdict::OK)],
            [],
            new \DateTimeImmutable('2026-09-14T00:00:00+00:00'),
            3,
            0
        );
        $counts = $report->byVerdict();
        self::assertSame(2, $counts[Verdict::SILENT]);
        self::assertSame(1, $counts[Verdict::OK]);
        self::assertSame(0, $counts[Verdict::ABANDONED]);
    }

    /** `abandoned` alone does not say whether a package died or moved. */
    public function testTheAbandonedCountIsSplitByWhetherAReplacementIsNamed(): void
    {
        $s1 = static fn (?string $replacement): Signal => new Signal('S1', 'high', 'marked abandoned by its repository', ['replacement' => $replacement]);
        $report = $this->report(
            (new FindingBuilder())->withPackage('vendor/moved')->withVerdict(Verdict::ABANDONED)->withSignals([$s1('vendor/successor')])->withChain(['vendor/moved'])->build(),
            (new FindingBuilder())->withPackage('vendor/dead')->withVerdict(Verdict::ABANDONED)->withSignals([$s1(null)])->withChain(['vendor/dead'])->build(),
            (new FindingBuilder())->withPackage('vendor/text')->withVerdict(Verdict::ABANDONED)->withSignals([$s1('Symfony')])->withChain(['vendor/text'])->build(),
            $this->finding('vendor/quiet', Verdict::SILENT)
        );

        self::assertSame(1, $report->abandonedWithReplacement());
        self::assertSame(['total' => 3, 'with_replacement' => 1, 'with_suggestion' => 1], $report->toArray()['abandoned'], 'Symfony names no package: a suggestion');
        self::assertSame(['counts', 'abandoned', 'priorities'], \array_slice(array_keys($report->toArray()), 10, 3), 'the split sits right after the counts');
        self::assertStringStartsWith('4 packages checked · abandoned 3 (1 with a replacement) · silent 1 · ', $report->summaryLine());

        $none = $this->report($this->finding('vendor/dead', Verdict::ABANDONED));
        self::assertSame(['total' => 1, 'with_replacement' => 0], $none->toArray()['abandoned']);
        self::assertStringStartsWith('1 packages checked · abandoned 1 · silent 0', $none->summaryLine(), 'a zero is not said');
    }

    public function testByPriorityHasAllFiveKeysWithZeros(): void
    {
        $report = new Report([], [], new \DateTimeImmutable('2026-09-14T00:00:00+00:00'), 0, 0);
        self::assertSame(
            [
                Priority013::CRITICAL => 0,
                Priority013::HIGH => 0,
                Priority013::MEDIUM => 0,
                Priority013::LOW => 0,
                Priority013::NONE => 0,
            ],
            $report->byPriority()
        );
    }

    public function testByPriorityCountsFindings(): void
    {
        $report = $this->report(
            $this->finding('vendor/a', Verdict::ABANDONED),
            $this->transitive('vendor/b', Verdict::ABANDONED),
            $this->transitive('vendor/c', Verdict::STALE),
            $this->finding('vendor/d', Verdict::OK)
        );
        self::assertSame(
            [Priority013::CRITICAL => 1, Priority013::HIGH => 1, Priority013::MEDIUM => 0, Priority013::LOW => 1, Priority013::NONE => 1],
            $report->byPriority()
        );
    }

    public function testPrioritySummaryLineNamesTheFourFlaggedLevelsOnly(): void
    {
        $report = $this->report(
            $this->finding('vendor/a', Verdict::ABANDONED),
            $this->transitive('vendor/b', Verdict::ABANDONED),
            $this->finding('vendor/c', Verdict::STALE),
            $this->transitive('vendor/d', Verdict::STALE),
            $this->finding('vendor/e', Verdict::OK)
        );
        self::assertSame('priority: critical 1 · high 1 · medium 1 · low 1', $report->prioritySummaryLine());
    }

    public function testPrioritySummaryLineOnAnEmptyReport(): void
    {
        $report = new Report([], [], new \DateTimeImmutable('2026-09-14T00:00:00+00:00'), 0, 0);
        self::assertSame('priority: critical 0 · high 0 · medium 0 · low 0', $report->prioritySummaryLine());
    }

    /** The `run` block records the settings, so two reports show whether the locks or the settings differ. */
    public function testTheReportRecordsWhatTheRunWasToldToDo(): void
    {
        $report = $this->report($this->finding('vendor/a', Verdict::SILENT))
            ->withRun(new RunSettings('Acme shop', 'acme/shop', '8.4', '/home/someone/clients/acme/composer.lock', FailOn::fromString('silent'), new Thresholds(2, 4, 6, 8), '>=8.2'));

        $run = JsonPath::arrayAt($report->toArray(), ['run']);

        self::assertSame(
            ['project' => 'Acme shop', 'root_package' => 'acme/shop', 'target_php' => '8.4', 'project_php' => '>=8.2', 'project_php_lowest' => '8.2.0', 'lock_file' => 'composer.lock', 'fail_on' => 'silent'],
            array_intersect_key($run, array_flip(['project', 'root_package', 'target_php', 'project_php', 'project_php_lowest', 'lock_file', 'fail_on']))
        );
        self::assertSame([['value' => 'silent', 'kind' => 'flag', 'threshold' => 'silent']], $run['gates'], "report-1's verdict word is a flag gate");
        self::assertSame(['release-warn-years' => 2, 'release-high-years' => 4, 'push-warn-years' => 6, 'push-high-years' => 8], $run['thresholds']);
        self::assertSame(['critical', 'high', 'medium', 'low'], $run['graded_verdicts']);
    }

    /**
     * `root_package` is always written where `run` is, null included: null means the manifest names
     * no package, not that the field is missing.
     */
    public function testARunWithoutAManifestNameWritesARootPackageOfNull(): void
    {
        $run = JsonPath::arrayAt($this->report()->withRun(new RunSettings('Acme shop', null, null, null, null, null))->toArray(), ['run']);

        self::assertArrayHasKey('root_package', $run);
        self::assertNull($run['root_package']);
        self::assertSame('Acme shop', $run['project']);
        self::assertArrayHasKey('project_php', $run, 'and a manifest without require.php writes null, not nothing');
        self::assertNull($run['project_php']);
    }

    /**
     * A report is something people publish, and an absolute path carries the account it ran under
     * and often the client's directory name. The same rule as the repository URLs.
     */
    public function testTheRunNamesTheLockAndNeverLocatesIt(): void
    {
        $report = $this->report()->withRun(new RunSettings(null, null, null, '/srv/deploy/acme-bank/composer.lock', FailOn::none(), null));

        $json = json_encode($report->toArray());

        self::assertIsString($json);
        self::assertStringNotContainsString('acme-bank', $json);
        self::assertStringNotContainsString('/srv', $json);
        self::assertSame('composer.lock', JsonPath::stringAt($report->toArray(), ['run', 'lock_file']));
    }

    /**
     * `flagged_verdicts` is the vocabulary, not a setting: a consumer that decides what counts as a
     * finding does not need the severity order. `unknown` is the case it
     * settles — a package lockrot could not check is a note, not a finding.
     */
    public function testTheRunNamesWhichVerdictsAreFindings(): void
    {
        $flagged = JsonPath::arrayAt($this->report()->withRun(new RunSettings(null, null, null, null, null, null))->toArray(), ['run', 'flagged_verdicts']);

        self::assertNotContains(Verdict::UNKNOWN, $flagged);
        self::assertNotContains(Verdict::FINISHED, $flagged);
        self::assertNotContains(Verdict::OK, $flagged);
        foreach (array_keys($flagged) as $at) {
            $verdict = JsonPath::stringAt($flagged, [$at]);
            self::assertTrue(Verdict::flagged($verdict), $verdict.' is not a flagged verdict');
        }
    }

    /**
     * The `baseline` block gives the totals. A reader filtering for what is new needs the same
     * judgement per finding, which the totals cannot give.
     */
    public function testEachFindingCarriesItsStandingAgainstTheBaseline(): void
    {
        $report = $this->report(
            $this->finding('vendor/known', Verdict::STALE),
            $this->finding('vendor/worse', Verdict::ABANDONED),
            $this->finding('vendor/fresh', Verdict::LEFT_BEHIND)
        );
        // An earlier baseline, when vendor/worse was only stale.
        $before = $this->report($this->finding('vendor/known', Verdict::STALE), $this->finding('vendor/worse', Verdict::STALE));
        $compared = $report->withBaseline(BaselineComparison::compare(
            Baseline::fromReport($before),
            $report,
            'lockrot-baseline.json',
            ['vendor/known', 'vendor/worse', 'vendor/fresh']
        ));

        $standings = [];
        foreach (array_keys(JsonPath::arrayAt($compared->toArray(), ['findings'])) as $at) {
            $standings[JsonPath::stringAt($compared->toArray(), ['findings', $at, 'package'])]
                = JsonPath::arrayAt($compared->toArray(), ['findings', $at, 'baseline']);
        }

        self::assertSame(['status' => 'known', 'previous_verdict' => Verdict::STALE], $standings['vendor/known']);
        self::assertSame(['status' => 'worsened', 'previous_verdict' => Verdict::STALE], $standings['vendor/worse']);
        self::assertSame(['status' => 'new', 'previous_verdict' => null], $standings['vendor/fresh']);
    }

    public function testAFindingWithoutABaselineStandsNowhere(): void
    {
        $findings = JsonPath::arrayAt($this->report($this->finding('vendor/a', Verdict::SILENT))->toArray(), ['findings']);

        self::assertArrayHasKey(0, $findings);
        self::assertIsArray($findings[0]);
        self::assertArrayHasKey('baseline', $findings[0]);
        self::assertNull($findings[0]['baseline']);
    }

    /** The run survives being compared with a baseline, which rebuilds the report. */
    public function testTheRunOutlivesWithBaseline(): void
    {
        $report = $this->report($this->finding('vendor/a', Verdict::SILENT))
            ->withRun(new RunSettings(null, null, '8.3', null, FailOn::none(), null));

        $compared = $report->withBaseline(BaselineComparison::compare(
            Baseline::fromReport($report),
            $report,
            'lockrot-baseline.json',
            ['vendor/a']
        ));

        self::assertSame('8.3', JsonPath::stringAt($compared->toArray(), ['run', 'target_php']));
    }

    public function testTheRunSaysHowItWasToldToGate(): void
    {
        $run = JsonPath::arrayAt($this->report()->withRun(new RunSettings(null, null, null, null, FailOn::fromString('high'), null, null, true, Gate::MODE_GENERATE_BASELINE))->toArray(), ['run']);

        self::assertSame('high', $run['fail_on']);
        self::assertSame('priority', $run['fail_on_kind']);
        self::assertTrue($run['strict_network']);
        self::assertSame('generate_baseline', $run['mode']);
    }

    public function testARunInAModeTheGateDoesNotKnowIsRefusedWhereItIsGiven(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"pull_request"');

        new RunSettings(null, null, null, null, FailOn::none(), null, null, false, 'pull_request');
    }

    /** A run told no fail-on, which only a test builds, has no kind and no gate. */
    public function testARunWithoutAFailOnHasNoKindAndNoGate(): void
    {
        $report = $this->report($this->finding('vendor/a', Verdict::SILENT))->withRun(new RunSettings(null, null, null, null, null, null));
        $document = $report->toArray();

        self::assertNull(JsonPath::arrayAt($document, ['run'])['fail_on_kind']);
        self::assertNull($report->gate());
        self::assertArrayHasKey('gate', $document);
        self::assertNull($document['gate']);
        self::assertNull(JsonPath::arrayAt($document, ['findings', 0])['gate']);
    }

    /** Without `run` the gate has nothing to read: null at the root and on every finding, never absent. */
    public function testAReportWithoutARunHasNoGate(): void
    {
        $report = $this->report($this->finding('vendor/a', Verdict::SILENT));
        $document = $report->toArray();

        self::assertNull($report->gate());
        self::assertArrayHasKey('gate', $document);
        self::assertNull($document['gate']);
        $at = array_search('baseline', array_keys($document), true);
        self::assertIsInt($at);
        self::assertSame(['baseline', 'gate', 'notes'], \array_slice(array_keys($document), $at, 3), 'after the baseline block');
        $finding = JsonPath::arrayAt($document, ['findings', 0]);
        self::assertArrayHasKey('gate', $finding);
        self::assertNull($finding['gate']);
        self::assertSame(['baseline', 'gate'], \array_slice(array_keys($finding), -2), 'after the finding\'s baseline standing');
    }

    /**
     * The gate is derived, not stored: the report compared with a baseline afterwards decides it
     * again, so a finding accepted there is exempt and the run passes.
     */
    public function testTheGateIsDecidedOverTheReportAsItStands(): void
    {
        $report = $this->report($this->finding('vendor/known', Verdict::ABANDONED), $this->finding('vendor/fine', Verdict::OK))
            ->withRun(new RunSettings(null, null, null, null, FailOn::fromString(Verdict::SILENT), null));
        $before = $report->toArray();

        self::assertSame(['fails' => true, 'tripped_by' => ['fail_on'], 'fail_on_applied' => true], $before['gate']);
        self::assertSame(['reaches_fail_on' => true, 'fails' => true, 'exempt_by' => null], JsonPath::arrayAt($before, ['findings', 0, 'gate']));
        self::assertSame(['reaches_fail_on' => false, 'fails' => false, 'exempt_by' => null], JsonPath::arrayAt($before, ['findings', 1, 'gate']));

        $compared = $report->withBaseline(BaselineComparison::compare(Baseline::fromReport($report), $report, 'lockrot-baseline.json', ['vendor/known', 'vendor/fine']));
        $after = $compared->toArray();

        self::assertSame(['fails' => false, 'tripped_by' => [], 'fail_on_applied' => true], $after['gate']);
        self::assertSame(['reaches_fail_on' => true, 'fails' => false, 'exempt_by' => 'baseline'], JsonPath::arrayAt($after, ['findings', 0, 'gate']));
        $gate = $compared->gate();
        self::assertNotNull($gate);
        self::assertFalse($gate->fails());
    }

    public function testTheLibyearsBlockIsTheArithmeticOverTheFindings(): void
    {
        $report = $this->report(
            (new FindingBuilder())->withPackage('vendor/direct')->withVerdict(Verdict::LEFT_BEHIND)->withChain(['vendor/direct'])->withLibyears(LibyearsMeasurement::of(4.0))->build(),
            (new FindingBuilder())->withPackage('vendor/deep')->withChain(['vendor/direct', 'vendor/deep'])->withLibyears(LibyearsMeasurement::of(2.5))->build(),
            (new FindingBuilder())->withPackage('vendor/pinned')->withVersion('dev-main')->withVerdict(Verdict::PINNED)->withChain(['vendor/pinned'])->withLibyears(LibyearsMeasurement::unmeasured(Libyears::BRANCH_SNAPSHOT))->build()
        );
        $block = $report->libyears();

        self::assertSame(6.5, $block->total());
        self::assertSame(4.0, $block->direct());
        self::assertSame(2, $block->measured());
        self::assertSame(1, $block->unmeasured()[Libyears::BRANCH_SNAPSHOT]);
        self::assertSame($block->toArray(), $report->toArray()['libyears']);
        // and the block a consumer reads is recomputable from the findings it reads
        $sum = 0.0;
        foreach (JsonPath::arrayAt($report->toArray(), ['findings']) as $finding) {
            self::assertIsArray($finding);
            if ($finding['libyears'] !== null) {
                self::assertIsFloat($finding['libyears']);
                $sum += $finding['libyears'];
            }
        }
        self::assertEqualsWithDelta(JsonPath::arrayAt($report->toArray(), ['libyears'])['total'], $sum, 0.01);
    }

    public function testToArrayKeys(): void
    {
        $report = new Report(
            [$this->finding('vendor/a', Verdict::SILENT)],
            [RunNote::metadataUnavailable(['vendor/a' => 'HTTP 503'])],
            new \DateTimeImmutable('2026-09-14T00:00:00+00:00'),
            5,
            1
        );
        $array = $report->toArray();
        self::assertSame(
            ['generated_at', 'run', 'activity_cache_oldest_at', 'packages_checked', 'include_dev', 'not_from_composer_repository', 'network_failures', 'counts', 'abandoned', 'priorities', 'exposure', 'exposure_rule', 'unattributed', 'libyears', 'baseline', 'gate', 'notes', 'note_details', 'findings'],
            array_keys($array)
        );
        // The literal, not the constant: the document states the value it attributed by.
        self::assertSame(['max_fan_in' => 8], $array['exposure_rule']);
        self::assertSame([], $array['unattributed']);
        self::assertIsArray($array['priorities']);
        self::assertSame(
            [Priority013::CRITICAL, Priority013::HIGH, Priority013::MEDIUM, Priority013::LOW, Priority013::NONE],
            array_keys($array['priorities'])
        );
        self::assertSame(1, $array['priorities'][Priority013::CRITICAL]);
        self::assertSame('2026-09-14T00:00:00+00:00', $array['generated_at']);
        self::assertNull($array['activity_cache_oldest_at']);
        self::assertSame(5, $array['packages_checked']);
        self::assertSame(1, $array['not_from_composer_repository']);
        self::assertTrue($array['network_failures']);
        self::assertNull($array['baseline']);
        self::assertNull($array['run'], 'a report nothing told about the run says so rather than guessing');
        self::assertSame(['Repository metadata unavailable for 1 package: HTTP 503'], $array['notes']);
        self::assertIsArray($array['findings']);
        self::assertCount(1, $array['findings']);
    }

    /**
     * The notes are one list: `notes` is each note's text and `note_details` each note, in the same
     * order, and `network_failures` is true exactly when one of them sets it — never a second answer
     * a caller passes in beside the notes.
     */
    public function testTheNotesTheirDetailsAndTheNetworkFlagComeFromOneList(): void
    {
        $at = new \DateTimeImmutable('2026-09-14T00:00:00+00:00');
        $quiet = [RunNote::offline(), RunNote::notFromComposerRepository(2)];
        $failing = [RunNote::offline(), RunNote::advisoriesUnavailable('packagist.org', new TransportException('HTTP 503')), RunNote::notFromComposerRepository(2)];

        self::assertFalse((new Report([], [], $at, 0, 0))->hadNetworkFailures());
        self::assertFalse((new Report([], $quiet, $at, 0, 2))->hadNetworkFailures());
        $report = new Report([], $failing, $at, 0, 2);
        self::assertTrue($report->hadNetworkFailures());
        self::assertSame($failing, $report->runNotes());
        self::assertSame([
            "offline: repository metadata served from Composer's cache",
            'security advisories unavailable from packagist.org: HTTP 503',
            '2 packages are not from a Composer repository and were not checked',
        ], $report->notes());

        $array = $report->toArray();
        $details = JsonPath::arrayAt($array, ['note_details']);
        self::assertTrue($array['network_failures']);
        self::assertSame($array['notes'], array_column($details, 'text'));
        self::assertSame(['offline', 'advisories_unavailable', 'not_from_composer_repository'], array_column($details, 'code'));
        self::assertSame('{}', json_encode(JsonPath::arrayAt($details, [0])['data']), 'no parameters is an empty object, never a list');
        self::assertSame('{"package_count":2}', json_encode(JsonPath::arrayAt($details, [2])['data']));
        self::assertSame([], (new Report([], [], $at, 0, 0))->toArray()['note_details']);

        $withRun = $report->withRun(new RunSettings(null, null, '8.4', null, FailOn::none(), new Thresholds()));
        $withBaseline = $withRun->withBaseline(BaselineComparison::compare(Baseline::fromReport($report), $report, 'lockrot-baseline.json', []));
        foreach ([$withRun, $withBaseline] as $copy) {
            self::assertSame($failing, $copy->runNotes());
            self::assertTrue($copy->hadNetworkFailures());
        }
    }

    public function testWithBaselineLeavesTheOriginalReportUntouched(): void
    {
        $report = new Report(
            [$this->finding('vendor/a', Verdict::SILENT)],
            [],
            new \DateTimeImmutable('2026-09-14T00:00:00+00:00'),
            1,
            0
        );
        $comparison = BaselineComparison::compare(
            Baseline::of([new BaselineEntry('vendor/a', '1.0.0', Verdict::SILENT, '2026-01-15')], '2026-09-14T00:00:00+00:00'),
            $report,
            'lockrot-baseline.json',
            ['vendor/a']
        );

        $withBaseline = $report->withBaseline($comparison);

        self::assertNull($report->baseline());
        self::assertNotSame($report, $withBaseline);
        self::assertSame($comparison, $withBaseline->baseline());
        self::assertSame(
            ['path' => 'lockrot-baseline.json', 'known' => 1, 'new' => 0, 'worsened' => 0, 'stale' => []],
            $withBaseline->toArray()['baseline']
        );
        self::assertSame($report->summaryLine(), $withBaseline->summaryLine());
    }

    /** A finding reachable from the named roots. The chain starts at the first of them, or is empty without any. */
    private function reachedFrom(string $package, string $verdict, string ...$roots): Finding
    {
        $chain = $roots === [] ? [] : ($roots[0] === $package ? [$package] : [$roots[0], $package]);

        return (new FindingBuilder())->withPackage($package)->withVerdict($verdict)->withChain($chain)->withDirectDependents(array_values($roots))->build();
    }

    public function testExposureCountsFlaggedFindingsPerParentMostFirstThenByName(): void
    {
        $report = $this->report(
            $this->reachedFrom('vendor/a', Verdict::ABANDONED, 'root/one', 'root/two'),
            $this->reachedFrom('vendor/b', Verdict::STALE, 'root/two'),
            $this->reachedFrom('vendor/c', Verdict::SILENT, 'root/three'),
            // Direct, and also reached through another root: the project's own choice, counted nowhere.
            $this->reachedFrom('root/three', Verdict::PINNED, 'root/three', 'root/one'),
            // Unflagged rows never count, whoever pulls them in.
            $this->reachedFrom('vendor/ok', Verdict::OK, 'root/one'),
            $this->reachedFrom('vendor/unreached', Verdict::ABANDONED)
        );
        self::assertSame(['root/two' => 2, 'root/one' => 1, 'root/three' => 1], $report->exposure());
        self::assertSame('pulled in by: root/two 2 · root/one 1 · root/three 1', $report->exposureSummaryLine());
        self::assertSame(
            [['package' => 'root/two', 'flagged' => 2], ['package' => 'root/one', 'flagged' => 1], ['package' => 'root/three', 'flagged' => 1]],
            $report->toArray()['exposure']
        );
    }

    public function testUnflaggedAdvisoriesAreTotalledForTheFooterAndFlaggedOnesAreNot(): void
    {
        $at = new \DateTimeImmutable('2026-09-14T00:00:00+00:00');
        $s9 = static fn (int $n): Signal => new Signal('S9', 'warn', $n.' security advisories affect x', ['advisories' => array_fill(0, $n, ['id' => 'x'])]);
        $report = new Report([
            (new FindingBuilder())->withPackage('vendor/ok')->withSignals([$s9(12)])->withChain(['vendor/ok'])->withDataDate($at)->build(),
            (new FindingBuilder())->withPackage('vendor/done')->withVerdict(Verdict::FINISHED)->withSignals([$s9(4)])->withChain(['vendor/done'])->withAllowlistReason('interfaces')->withDataDate($at)->build(),
            (new FindingBuilder())->withPackage('vendor/gone')->withVerdict(Verdict::ABANDONED)->withSignals([new Signal('S1', 'high', 'abandoned'), $s9(2)])->withChain(['vendor/gone'])->withDataDate($at)->build(),
            (new FindingBuilder())->withPackage('vendor/clean')->withChain(['vendor/clean'])->withDataDate($at)->build(),
        ], [], $at, 4, 0, null, null, true);

        self::assertSame('16 security advisories on 2 packages the report does not flag; see composer audit', $report->unflaggedAdvisoriesLine());
    }

    /**
     * `composer audit` counts `packages-dev` by default and a run without `--dev` does not, so the
     * two totals differ on most projects. The line says why before a reader asks.
     */
    public function testWithoutDevTheFooterSaysWhyAuditCountsMore(): void
    {
        $at = new \DateTimeImmutable('2026-09-14T00:00:00+00:00');
        $finding = (new FindingBuilder())->withPackage('vendor/ok')->withSignals([new Signal('S9', 'warn', '2 security advisories affect x', ['advisories' => [['id' => 'x'], ['id' => 'y']]])])->withChain(['vendor/ok'])->withDataDate($at)->build();
        $withoutDev = new Report([$finding], [], $at, 1, 0);
        $withDev = new Report([$finding], [], $at, 1, 0, null, null, true);

        self::assertFalse($withoutDev->includesDev());
        self::assertSame(
            '2 security advisories on 1 package the report does not flag; see composer audit (it counts packages-dev too, which this run skipped; pass --dev to include them)',
            $withoutDev->unflaggedAdvisoriesLine()
        );
        self::assertFalse($withoutDev->toArray()['include_dev']);
        self::assertTrue($withDev->includesDev());
        self::assertTrue($withDev->toArray()['include_dev']);
        $comparison = BaselineComparison::compare(Baseline::of([], '2026-09-14T00:00:00+00:00'), $withDev, 'lockrot-baseline.json', ['vendor/ok']);
        self::assertTrue($withDev->withBaseline($comparison)->includesDev(), 'the baseline view keeps the scope');
    }

    public function testUnflaggedAdvisoriesLineIsSingularAndEmptyWhenNoneAreThere(): void
    {
        $at = new \DateTimeImmutable('2026-09-14T00:00:00+00:00');
        $one = new Report([(new FindingBuilder())->withPackage('vendor/ok')->withSignals([new Signal('S9', 'warn', '1 security advisory affects x', ['advisories' => [['id' => 'x']]])])->withChain(['vendor/ok'])->withDataDate($at)->build()], [], $at, 1, 0, null, null, true);
        $none = new Report([(new FindingBuilder())->withPackage('vendor/gone')->withVerdict(Verdict::ABANDONED)->withSignals([new Signal('S9', 'warn', 'x', ['advisories' => [['id' => 'x']]])])->withChain(['vendor/gone'])->withDataDate($at)->build()], [], $at, 1, 0);

        self::assertSame('1 security advisory on 1 package the report does not flag; see composer audit', $one->unflaggedAdvisoriesLine());
        self::assertSame('', $none->unflaggedAdvisoriesLine());
    }

    public function testExposureIsEmptyWhenNothingFlaggedIsReachedThroughAnotherPackage(): void
    {
        $report = $this->report($this->reachedFrom('vendor/a', Verdict::ABANDONED, 'vendor/a'), $this->finding('vendor/b', Verdict::OK));
        self::assertSame([], $report->exposure());
        self::assertSame('', $report->exposureSummaryLine());
        self::assertSame([], $report->toArray()['exposure']);
    }

    public function testExposureSummaryLineNamesFiveParentsThenCountsTheRest(): void
    {
        $findings = [];
        for ($i = 1; $i <= 7; ++$i) {
            $findings[] = $this->reachedFrom(\sprintf('vendor/p%02d', $i), Verdict::STALE, \sprintf('root/r%02d', $i));
        }
        $line = $this->report(...$findings)->exposureSummaryLine();
        self::assertStringStartsWith('pulled in by: root/r01 1 · root/r02 1 · ', $line);
        self::assertStringEndsWith(' · root/r05 1 · … and 2 more', $line);
        self::assertStringNotContainsString('root/r06', $line);
    }

    public function testExposureLeavesOutAPackageReachedFromMoreRootsThanTheCap(): void
    {
        $many = [];
        for ($i = 1; $i <= TransitiveExposure::MAX_FAN_IN + 1; ++$i) {
            $many[] = \sprintf('root/r%02d', $i);
        }
        $report = $this->report(
            $this->reachedFrom('vendor/shared', Verdict::ABANDONED, ...$many),
            $this->reachedFrom('vendor/leaf', Verdict::STALE, 'root/r01')
        );

        self::assertSame(['root/r01' => 1], $report->exposure());
        self::assertSame('pulled in by: root/r01 1', $report->exposureSummaryLine());
    }

    /** @return list<string> root/r01 … root/rNN */
    private static function roots(int $count): array
    {
        $roots = [];
        for ($i = 1; $i <= $count; ++$i) {
            $roots[] = \sprintf('root/r%02d', $i);
        }

        return $roots;
    }

    /**
     * What the cap gives to nobody is listed, with its verdict and how many direct requirements
     * reach it — and listing it moves nothing the cap already decided: `exposure` and the `pulled in
     * by:` line stay the same.
     */
    public function testAPackageReachedFromNineRootsIsUnattributedAndChangesNothingElse(): void
    {
        $report = $this->report(
            $this->reachedFrom('vendor/shared', Verdict::ABANDONED, ...self::roots(9)),
            $this->reachedFrom('vendor/leaf', Verdict::STALE, 'root/r01')
        );

        self::assertSame([['package' => 'vendor/shared', 'verdict' => 'abandoned', 'fan_in' => 9]], $report->toArray()['unattributed']);
        self::assertSame(['root/r01' => 1], $report->exposure());
        self::assertSame('pulled in by: root/r01 1', $report->exposureSummaryLine());
        self::assertSame([['package' => 'root/r01', 'flagged' => 1]], $report->toArray()['exposure']);
    }

    public function testAPackageReachedFromExactlyEightRootsIsNotUnattributed(): void
    {
        $report = $this->report($this->reachedFrom('vendor/shared', Verdict::ABANDONED, ...self::roots(8)));

        self::assertSame([], $report->toArray()['unattributed']);
        self::assertCount(8, $report->exposure());
    }

    /**
     * Only a flagged transitive package above the cap is unattributed: a direct requirement is its
     * own finding, an unflagged or unknown row is nobody's exposure, and a package no direct
     * requirement reaches (a lock-only run, a replace or provide) is in neither list.
     */
    public function testUnattributedLeavesOutDirectUnflaggedUnknownAndUnreachedPackages(): void
    {
        $nine = self::roots(9);
        $cases = [
            'direct, reached from eight more' => $this->reachedFrom('root/r01', Verdict::PINNED, ...$nine),
            'ok' => $this->reachedFrom('vendor/ok', Verdict::OK, ...$nine),
            'unknown' => $this->reachedFrom('vendor/unknown', Verdict::UNKNOWN, ...$nine),
            'finished' => $this->reachedFrom('vendor/finished', Verdict::FINISHED, ...$nine),
            'reached by nothing' => $this->reachedFrom('vendor/unreached', Verdict::ABANDONED),
        ];
        foreach ($cases as $case => $finding) {
            self::assertSame([], $this->report($finding)->toArray()['unattributed'], $case);
        }
        self::assertTrue($cases['direct, reached from eight more']->isDirect());
        self::assertCount(9, $cases['direct, reached from eight more']->directDependents());
    }

    public function testUnattributedFollowsReportOrderNotNameOrder(): void
    {
        $report = $this->report(
            $this->reachedFrom('vendor/a', Verdict::STALE, ...self::roots(9)),
            $this->reachedFrom('vendor/between', Verdict::SILENT, 'root/r01'),
            $this->reachedFrom('vendor/z', Verdict::ABANDONED, ...self::roots(10))
        );

        self::assertSame(
            [['package' => 'vendor/z', 'verdict' => 'abandoned', 'fan_in' => 10], ['package' => 'vendor/a', 'verdict' => 'stale', 'fan_in' => 9]],
            $report->toArray()['unattributed']
        );
    }

    public function testExposureSummaryLineWithExactlyFiveParentsNamesThemAllAndCountsNothing(): void
    {
        $findings = [];
        for ($i = 1; $i <= 5; ++$i) {
            $findings[] = $this->reachedFrom(\sprintf('vendor/p%02d', $i), Verdict::STALE, \sprintf('root/r%02d', $i));
        }

        self::assertSame('pulled in by: root/r01 1 · root/r02 1 · root/r03 1 · root/r04 1 · root/r05 1', $this->report(...$findings)->exposureSummaryLine());
    }

    public function testExposureKeepsAPackageReachedFromExactlyAsManyRootsAsTheCap(): void
    {
        $roots = [];
        for ($i = 1; $i <= TransitiveExposure::MAX_FAN_IN; ++$i) {
            $roots[] = \sprintf('root/r%02d', $i);
        }
        $report = $this->report($this->reachedFrom('vendor/shared', Verdict::ABANDONED, ...$roots));

        self::assertSame(array_fill_keys($roots, 1), $report->exposure());
    }

    /**
     * The footer's source clause: the plain pair when every activity answer was fetched in this
     * run, otherwise the age of the oldest cached one in whole hours rounded up, never below one.
     */
    public function testTheDataSourcesClauseStatesTheAgeOfCachedActivity(): void
    {
        $at = new \DateTimeImmutable('2026-09-14T12:00:00+00:00');
        $report = static fn (?string $oldest): Report => new Report([], [], $at, 0, 0, null, $oldest === null ? null : new \DateTimeImmutable($oldest));

        self::assertSame('package repositories, repository hosts', $report(null)->dataSourcesClause());
        self::assertSame("package repositories; repository activity from lockrot's cache, up to 1 h old", $report('2026-09-14T11:30:00+00:00')->dataSourcesClause(), '30 minutes rounds up to one hour');
        self::assertSame("package repositories; repository activity from lockrot's cache, up to 1 h old", $report('2026-09-14T12:00:00+00:00')->dataSourcesClause(), 'fetched this second, still at least one hour');
        self::assertSame("package repositories; repository activity from lockrot's cache, up to 23 h old", $report('2026-09-13T13:00:00+00:00')->dataSourcesClause(), 'exactly 23 hours');
        self::assertSame("package repositories; repository activity from lockrot's cache, up to 24 h old", $report('2026-09-13T12:59:00+00:00')->dataSourcesClause(), '23 hours and a minute rounds up to 24');
        self::assertSame("package repositories; repository activity from lockrot's cache, up to 1 h old", $report('2026-09-14T11:00:00+00:00')->dataSourcesClause(), 'exactly one hour is one hour');
        self::assertSame("package repositories; repository activity from lockrot's cache, up to 2 h old", $report('2026-09-14T10:59:59+00:00')->dataSourcesClause(), 'one second past the hour rounds up');
        self::assertSame("package repositories; repository activity from lockrot's cache, up to 1 h old", $report('2026-09-14T13:00:00+00:00')->dataSourcesClause(), 'a clock that ran backwards is not a negative age');
        self::assertSame("package repositories; repository activity from lockrot's cache, up to 3601 h old", $report('2026-04-17T11:00:00+00:00')->dataSourcesClause(), 'an --offline run serves the cache however old it is; 3601 hours here');
    }

    public function testTheCacheDateIsCarriedIntoTheArrayAndAcrossWithBaseline(): void
    {
        $at = new \DateTimeImmutable('2026-09-14T12:00:00+00:00');
        $oldest = new \DateTimeImmutable('2026-09-13T20:00:00+00:00');
        $report = new Report([], [], $at, 0, 0, null, $oldest);

        self::assertSame($oldest, $report->activityCacheOldestAt());
        self::assertSame('2026-09-13T20:00:00+00:00', $report->toArray()['activity_cache_oldest_at']);
        $withBaseline = $report->withBaseline(BaselineComparison::compare(Baseline::of([], '2026-09-14T00:00:00+00:00'), $report, 'lockrot-baseline.json', []));
        self::assertSame($oldest, $withBaseline->activityCacheOldestAt());
    }
}
