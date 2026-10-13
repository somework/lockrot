<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Config;

use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunSettings;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Config\Gate;
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

    public function testTheReleasesCheckThatAWrittenFindingAddsFailsUnchecked(): void
    {
        $finding = (new FindingBuilder())->withPackage('a/fixless')->withSignals([self::s10('releases', 'releases_unknown', ['S9'])])->withChain(['a/fixless'])->build();

        self::assertTrue(self::gate(self::report($finding), 'unchecked')->fails());
    }

    public function testAdvisoriesThatNothingCheckedAreNotAppliedForAGradeValue(): void
    {
        $offline = self::unchecked('a/offline', 'offline', true);
        $skipped = self::unchecked('a/skipped', 'not_from_composer_repository', false);
        $report = self::report($offline, $skipped);

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

    /** A finding whose advisory lookup did not run, with S10 for it unless the reason is a skip. */
    private static function unchecked(string $package, string $reason, bool $inS10): Finding
    {
        $finding = (new FindingBuilder())->withPackage($package)->withSignals($inS10 ? [self::s10('advisories', $reason, ['S9'])] : [])->withChain([$package])->build();
        $activity = FindingBuilder::activityOf($finding);

        return $finding->withDetails(new FindingDetails('read', null, null, [], ['requires' => null, 'target_runs' => null, 'project_allows' => null], null, 'not_run', $reason, [], null, $activity, FindingBuilder::ageOf($finding, 'read', $activity), null, null));
    }
}
