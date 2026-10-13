<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Config;

use Composer\Semver\VersionParser;
use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunSettings;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Config\Gate;
use Lockrot\Data\Advisory\Advisory;
use Lockrot\Score\CountedAdvisory;
use Lockrot\Signal\Data\AdvisoryData;
use Lockrot\Signal\Data\Check;
use Lockrot\Signal\Data\NotCheckedData;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\PhpFloor;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\BaselineEntries;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\Notes;
use Lockrot\Tests\Support\ScoreSweep;
use Lockrot\Tests\Support\SignalDataStub;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\FindingDetails;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\TestCase;

/** SPEC §6.1 and §6.2: what each kind of value reaches and fails, with and without a baseline entry. */
final class GateTest extends TestCase
{
    private const AT = '2026-09-14T00:00:00+00:00';

    public function testTheVocabulariesAreTheOnesTheSchemaLists(): void
    {
        self::assertSame(['check', 'generate_baseline'], Gate::MODES);
        self::assertSame(['strict_network', 'fail_on'], Gate::TRIPS);
        self::assertSame(['baseline'], Gate::EXEMPTIONS);
    }

    public function testAGradeValueFailsOnTheScoreAndNamesTheLeadThatReachesHalfItsFloor(): void
    {
        $gate = self::gate(self::report(self::finding('a/gone', Verdict::ABANDONED)), 'high');
        $standing = JsonPath::decoded($gate->standings()[0]);

        self::assertTrue($gate->fails());
        self::assertSame(['reaches_fail_on' => true, 'fails' => true, 'exempt_by' => null, 'by' => [['value' => 'high', 'kind' => 'grade', 'facts' => [['kind' => 'flag', 'id' => 'abandoned']]]], 'basis' => null], $standing);
    }

    public function testAFlagValueReachesTheFlagsAtOrAboveIt(): void
    {
        $report = self::report(self::finding('a/stale', Verdict::STALE), self::finding('a/pinned', Verdict::PINNED));

        self::assertSame([['value' => 'left-behind', 'failing' => 1]], JsonPath::decoded(self::gate($report, 'left-behind')->failingBy()));
        self::assertSame([['value' => 'stale', 'failing' => 2]], JsonPath::decoded(self::gate($report, 'stale')->failingBy()));
    }

    public function testAKnownFindingReachesAndIsExempt(): void
    {
        $report = self::report(self::finding('a/stale', Verdict::STALE));
        $known = $report->withBaseline(BaselineComparison::compare(BaselineEntries::baseline([BaselineEntries::of('a/stale', '1.0.0', Verdict::STALE, '2026-01-01')]), $report, 'lockrot-baseline.json', ['a/stale']));
        $gate = self::gate($known, 'low,stale');
        $standing = $gate->standings()[0];

        self::assertSame([true, false, 'baseline', []], [$standing->reachesFailOn(), $standing->fails(), $standing->exemptBy(), $standing->by()]);
        self::assertSame(['score' => 0, 'verdict' => null, 'cap' => 0, 'limited_by' => 'cap'], \array_slice(JsonPath::decoded($standing->basis()), 0, 4, true));
        self::assertSame(1, $gate->exemptByBaseline());
    }

    public function testAFlagAboveTheRecordedLeadFailsAFlagValue(): void
    {
        $report = self::report(self::finding('a/quiet', Verdict::SILENT));
        $worse = $report->withBaseline(BaselineComparison::compare(BaselineEntries::baseline([BaselineEntries::of('a/quiet', '1.0.0', Verdict::STALE, '2026-01-01')]), $report, 'lockrot-baseline.json', ['a/quiet']));

        self::assertSame([[['kind' => 'flag', 'id' => 'silent']]], array_map(static fn ($by): array => JsonPath::arrayAt(JsonPath::decoded($by), ['facts']), self::gate($worse, 'left-behind')->standings()[0]->by()));
    }

    public function testEachValueGetsItsCountInItsOrderWithZerosWritten(): void
    {
        $gate = self::gate(self::report(self::finding('a/gone', Verdict::ABANDONED)), 'high,unchecked');

        self::assertSame([['value' => 'high', 'failing' => 1], ['value' => 'unchecked', 'failing' => 0]], JsonPath::decoded($gate->failingBy()));
        self::assertSame([['value' => 'high', 'failing' => 1], ['value' => 'unchecked', 'failing' => 0]], JsonPath::arrayAt(JsonPath::decoded($gate->toArray(self::runSettings('high,unchecked'))), ['failing_by']));
        self::assertSame([], JsonPath::arrayAt(self::gate(self::report(), 'none')->toArray(self::runSettings('none')), ['failing_by']));
    }

    public function testBothCausesAreRecordedInTheirOrder(): void
    {
        $report = new Report([self::finding('a/gone', Verdict::ABANDONED)], [Notes::text('a repository failed', true)], new \DateTimeImmutable(self::AT), 1, 0);

        self::assertSame(['strict_network', 'fail_on'], Gate::decide($report, FailOn::parse('high'), true, Gate::MODE_CHECK)->trippedBy());
    }

    public function testAGenerateRunReachesButFailsNothing(): void
    {
        $gate = Gate::decide(self::report(self::finding('a/gone', Verdict::ABANDONED)), FailOn::parse('high'), false, Gate::MODE_GENERATE_BASELINE);
        $standing = $gate->standings()[0];

        self::assertSame([false, false, 1, 0, [], [['value' => 'high', 'failing' => 0]]], [$gate->fails(), $gate->failOnApplied(), $gate->reaching(), $gate->failing(), $standing->by(), JsonPath::decoded($gate->failingBy())]);
    }

    public function testAFindingThatCarriesS10FailsUnchecked(): void
    {
        $finding = (new FindingBuilder())->withPackage('a/unread')->withSignals([self::s10('repository_activity', 'host_not_supported', ['S3', 'S4'])])->withChain(['a/unread'])->build();
        $standing = JsonPath::decoded(self::gate(self::report($finding), 'unchecked')->standings()[0]);

        self::assertSame([['value' => 'unchecked', 'kind' => 'unchecked', 'facts' => [['kind' => 'signal', 'id' => 'S3'], ['kind' => 'signal', 'id' => 'S4']]]], $standing['by']);
    }

    /**
     * TYPES-2: the raw signals hold no S10, and the details add the `releases` check that a counted
     * advisory needed from metadata that was not read. `--fail-on=unchecked` reads the written S10.
     */
    public function testTheReleasesCheckThatAWrittenFindingAddsFailsUnchecked(): void
    {
        $package = F::package(['name' => 'a/fixless']);
        $advisory = new Advisory('PKSA-1', null, 'Title', null, 'high', null, (new VersionParser())->parseConstraints('<2.0.0'));
        $facts = F::looked(new PackageFacts($package, null, null, [$advisory], null, null, null, true));
        $signals = [new Signal(Signal::LEVEL_HIGH, 'advisory', new AdvisoryData([SignalDataStub::row(['id' => 'PKSA-1', 'severity' => 'high'])], false, true))];
        $built = (new FindingBuilder())->withPackage('a/fixless')->withSignals($signals)->withChain(['a/fixless'])
            ->withFlags(FlagSet::fromSignals($signals, null, [new CountedAdvisory('PKSA-1', 'high', 'unknown')]), false)->build();
        $finding = $built->withDetails(FindingDetails::of($facts, null, new PhpFloor('8.4', null), [], $signals, null, FindingBuilder::unmeasuredAge()));

        self::assertNotContains(Signal::S10, array_map(static fn (Signal $signal): string => $signal->id(), $finding->signals()), 'no S10 in the raw signals');
        self::assertSame(['releases'], array_map(static fn (Check $check): string => $check->check(), $finding->checksMissing()));
        self::assertTrue(self::gate(self::report($finding), 'unchecked')->fails());
    }

    public function testAdvisoriesThatNothingCheckedAreNotAppliedForAGradeValue(): void
    {
        $offline = FindingBuilder::advisoriesUnchecked('a/offline', 'offline', true);
        $skipped = FindingBuilder::advisoriesUnchecked('a/skipped', 'not_from_composer_repository', false);
        $report = self::report($offline, $skipped, self::vulnerable('a/checked', ['stale'], []));

        self::assertSame([['check' => 'advisories', 'packages' => 1, 'reason' => 'not_from_composer_repository'], ['check' => 'advisories', 'packages' => 1, 'reason' => 'offline']], JsonPath::decoded(self::gate($report, 'high')->notAppliedEntries()));
        self::assertSame([['check' => 'advisories', 'packages' => 1, 'reason' => 'not_from_composer_repository']], JsonPath::decoded(self::gate($report, 'high,unchecked')->notAppliedEntries()), 'an S10 reason fails closed on unchecked');
        self::assertSame([], self::gate($report, 'stale')->notAppliedEntries(), 'a flag value reads no advisory');
        self::assertSame([], Gate::decide($report, FailOn::parse('high'), false, Gate::MODE_GENERATE_BASELINE)->notAppliedEntries());
    }

    public function testAModeTheGateDoesNotKnowIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"pull_request"');

        Gate::decide(self::report(), FailOn::none(), false, 'pull_request');
    }

    /**
     * A finding whose flags are its maintenance flags and whose counted advisories have these severities.
     *
     * @param list<string> $flags
     * @param list<string> $severities
     */
    private static function vulnerable(string $package, array $flags, array $severities): Finding
    {
        $signals = [];
        foreach ($flags as $flag) {
            $signals = array_merge($signals, ScoreSweep::signalsOf($flag));
        }
        $counted = [];
        foreach ($severities as $i => $severity) {
            $counted[] = new CountedAdvisory('A'.$i, $severity, 'update');
        }

        return (new FindingBuilder())->withPackage($package)->withChain([$package])->withFlags(FlagSet::fromSignals($signals, null, $counted))->build();
    }

    /** @return array{bool, bool, ?string} the standing's reaches_fail_on, fails and exempt_by */
    private static function standing(Gate $gate, string $package): array
    {
        $standing = $gate->standingOf($package);
        self::assertNotNull($standing);

        return [$standing->reachesFailOn(), $standing->fails(), $standing->exemptBy()];
    }

    public function testAVulnerableValueReadsTheRawSeverityOfACountedAdvisory(): void
    {
        $report = self::report(self::vulnerable('a/high', [], ['high']), self::vulnerable('a/medium', [], ['medium']), self::vulnerable('a/none', ['stale'], []));
        $gate = self::gate($report, 'vulnerable-high');

        self::assertSame([true, true, null], self::standing($gate, 'a/high'));
        self::assertSame([false, false, null], self::standing($gate, 'a/medium'));
        self::assertSame([false, false, null], self::standing($gate, 'a/none'), 'no counted advisory reaches no vulnerable value');
        self::assertSame([false, false, null], self::standing(self::gate($report, 'vulnerable'), 'a/none'));
    }

    public function testAGradeValueIsReachedAtItsFloorExactly(): void
    {
        $report = self::report(self::vulnerable('a/sixteen', ['left-behind'], []), self::vulnerable('a/eight', ['stale'], []));

        self::assertSame(16, self::vulnerable('a/sixteen', ['left-behind'], [])->score()->total(), 'the floor of high');
        self::assertSame([true, true, null], self::standing(self::gate($report, 'high'), 'a/sixteen'));
        self::assertSame([false, false, null], self::standing(self::gate($report, 'high'), 'a/eight'));
    }

    public function testAFlagValueIsNotReachedByAFlagBelowIt(): void
    {
        $report = self::report(self::vulnerable('a/stale', ['stale'], []));

        self::assertSame([false, false, null], self::standing(self::gate($report, 'left-behind'), 'a/stale'));
        self::assertSame([true, true, null], self::standing(self::gate($report, 'stale'), 'a/stale'));
    }

    /** Only a reaching finding under an entry is exempt: one that does not reach, or has no entry, is not. */
    public function testOnlyAReachingFindingUnderAnEntryIsExempt(): void
    {
        $report = self::report(self::vulnerable('a/known', ['left-behind'], []), self::vulnerable('a/quiet', ['stale'], []), self::vulnerable('a/new', ['left-behind'], []));
        $baseline = BaselineEntries::baseline([BaselineEntries::of('a/known', '1.0.0', 'left-behind', '2026-01-01'), BaselineEntries::of('a/quiet', '1.0.0', 'stale', '2026-01-01')]);
        $compared = $report->withBaseline(BaselineComparison::compare($baseline, $report, 'b.json', ['a/known', 'a/quiet', 'a/new']));
        $gate = self::gate($compared, 'high');

        self::assertSame([true, false, 'baseline'], self::standing($gate, 'a/known'));
        self::assertSame([false, false, null], self::standing($gate, 'a/quiet'));
        self::assertSame([true, true, null], self::standing($gate, 'a/new'));
    }

    public function testAFindingWithoutS10DoesNotReachUnchecked(): void
    {
        $report = self::report(self::vulnerable('a/stale', ['stale'], []), FindingBuilder::advisoriesUnchecked('a/offline', 'offline', true));
        $gate = self::gate($report, 'unchecked');

        self::assertSame([false, false, null], self::standing($gate, 'a/stale'));
        self::assertSame([true, true, null], self::standing($gate, 'a/offline'));
    }

    private static function gate(Report $report, string $failOn): Gate
    {
        return Gate::decide($report, FailOn::parse($failOn), false, Gate::MODE_CHECK);
    }

    private static function runSettings(string $failOn): RunSettings
    {
        return new RunSettings(null, null, '8.4', RunSettings::SOURCE_OPTION, null, FailOn::parse($failOn), RunSettings::SOURCE_OPTION, null);
    }

    private static function report(Finding ...$findings): Report
    {
        return new Report(array_values($findings), [], new \DateTimeImmutable(self::AT), \count($findings), 0);
    }

    private static function finding(string $package, string $verdict): Finding
    {
        return (new FindingBuilder())->withPackage($package)->withVerdict($verdict)->withChain([$package])->build();
    }

    /** @param list<string> $blocks */
    private static function s10(string $check, string $reason, array $blocks): Signal
    {
        return new Signal(Signal::LEVEL_INFO, 'not checked', new NotCheckedData([new Check($check, $reason, $blocks)]));
    }

}
