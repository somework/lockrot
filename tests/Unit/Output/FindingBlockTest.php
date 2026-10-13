<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Allowlist\AcceptedCheck;
use Lockrot\Allowlist\UncheckedAcceptanceEntry;
use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunSettings;
use Lockrot\Baseline\Baseline;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Data\Abandoned\AbandonedIgnoreRule;
use Lockrot\Lock\ConfiguredRepositories;
use Lockrot\Lock\OriginFacts;
use Lockrot\Lock\PackageOrigin;
use Lockrot\Output\FindingBlock;
use Lockrot\Score\CountedAdvisory;
use Lockrot\Signal\AbandonedIgnored;
use Lockrot\Signal\Data\Check;
use Lockrot\Signal\PhpFloor;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\BaselineEntries;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\SignalDataStub;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\FindingDetails;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\TestCase;

/** The parts of a finding block that the cases do not reach. */
final class FindingBlockTest extends TestCase
{
    /** @param list<array{check: string, reason: string, blocks: list<string>}> $unchecked */
    private static function unchecked(array $unchecked, ?PackageOrigin $origin = null): Finding
    {
        return (new FindingBuilder())->withOrigin($origin)->withSignals([new Signal(Signal::LEVEL_INFO, 'not checked', SignalDataStub::of(Signal::S10, ['unchecked' => $unchecked]))])->build();
    }

    /**
     * One package with the counted advisory `PKSA-x` at $severity with the fix $fixKind. The entry
     * records it at medium with $recordedFix, for the run's target PHP or another one.
     */
    private static function suffixOf(string $severity, string $fixKind, string $baselineTarget, string $recordedFix = 'update'): ?string
    {
        $s9 = new Signal('high', '1 advisory', SignalDataStub::of('S9', []));
        $finding = (new FindingBuilder())->withPackage('a/b')->withVersion('1.0.0')->withSignals([$s9])
            ->withFlags(FlagSet::fromSignals([$s9], null, [new CountedAdvisory('PKSA-x', $severity, $fixKind)]))
            ->build();
        $report = (new Report([$finding], [], new \DateTimeImmutable('2026-10-01T00:00:00+00:00'), 1, 0))
            ->withRun(new RunSettings(null, null, '8.4', RunSettings::SOURCE_OPTION, null, null, RunSettings::SOURCE_OPTION, null));
        $entry = BaselineEntries::of('a/b', '1.0.0', Verdict::STALE, '2026-06-01', [['PKSA-x', 'medium', $recordedFix]]);
        $comparison = BaselineComparison::compare(Baseline::of([$entry], '2026-09-14T00:00:00+00:00', $baselineTarget, RunSettings::SOURCE_OPTION), $report, 'lockrot-baseline.json', ['a/b']);

        return FindingBlock::baselineSuffix($finding, $comparison->standingOf('a/b'));
    }

    public function testALostFixReadsTheRecordedAndTheCurrentFixKind(): void
    {
        self::assertSame('(baseline: PKSA-x fix lost: update → none)', self::suffixOf('medium', 'none', '8.4'));
        self::assertSame('(baseline: PKSA-x fix lost: update → blocked)', self::suffixOf('medium', 'blocked', '8.4'));
    }

    public function testAReRatedAdvisoryAddsTheLostFixOnlyWhenTheFixesAreCompared(): void
    {
        self::assertSame('(baseline: PKSA-x re-rated medium → high, fix lost: update → none)', self::suffixOf('high', 'none', '8.4'));
        self::assertSame('(baseline: PKSA-x re-rated medium → high)', self::suffixOf('high', 'none', '8.3'));
        self::assertSame('(baseline: PKSA-x re-rated medium → high)', self::suffixOf('high', 'update', '8.4'));
        self::assertSame('(baseline: PKSA-x re-rated medium → high)', self::suffixOf('high', 'update', '8.3'));
        self::assertSame('(baseline: PKSA-x re-rated medium → high)', self::suffixOf('high', 'update', '8.3', 'none'), 'a fix that was lost already is not lost again');
        self::assertSame('(baseline: PKSA-x re-rated medium → high)', self::suffixOf('high', 'none', '8.4', 'none'));
    }

    public function testTheMarkingLineNamesEachPatternAndEachConstraintOnceInOrder(): void
    {
        $finding = (new FindingBuilder())->withPackage('doctrine/cache')->build();
        $rules = [new AbandonedIgnoreRule('doctrine/*', 'goes with the 9.0 upgrade', ['^3.0', '^1.0']), new AbandonedIgnoreRule('doctrine/cache', null, ['^1.0'])];
        $ignored = new AbandonedIgnored('audit.ignore-abandoned', $rules, AbandonedIgnored::MARKED_BY_LOCK, null, null);

        self::assertSame(
            'abandoned marking ignored (audit.ignore-abandoned: doctrine/*, doctrine/cache): goes with the 9.0 upgrade (constraint ^1.0, ^3.0 not applied, as composer audit does not apply it)',
            FindingBlock::markingLine($finding->withDetails(FindingBuilder::detailsOf($finding, true, null, null, $ignored)))
        );
        self::assertNull(FindingBlock::markingLine($finding));
    }

    public function testTheMissingChecksJoinByTheirWordsEachIdOnceInSignalOrder(): void
    {
        $finding = self::unchecked([
            ['check' => 'release_metadata', 'reason' => 'no_token', 'blocks' => ['S10', 'S9']],
            ['check' => 'advisories', 'reason' => 'offline', 'blocks' => ['S9']],
            ['check' => 'repository_activity', 'reason' => 'no_token', 'blocks' => ['S4', 'S9']],
        ]);

        self::assertSame('checks missing: S4, S9, S10 (no token) · S9 (offline)', FindingBlock::checksMissing($finding));
    }

    public function testAPackageOfUnknownOriginReadsOriginUnknownForAMissingRepository(): void
    {
        $unknown = PackageOrigin::of('a/b', '1.0.0', new OriginFacts('', null, null, null, null), ConfiguredRepositories::none());
        $check = [['check' => 'release_metadata', 'reason' => 'not_from_composer_repository', 'blocks' => ['S2']]];

        self::assertSame('checks missing: S2 (origin unknown)', FindingBlock::checksMissing(self::unchecked($check, $unknown)));
        self::assertSame('origin unknown', FindingBlock::checkWords(self::unchecked($check, $unknown), 'not_from_composer_repository'));
        self::assertNotSame('origin unknown', FindingBlock::checkWords(self::unchecked($check, $unknown), 'offline'));
        self::assertNotSame('origin unknown', FindingBlock::checkWords(self::unchecked($check), 'not_from_composer_repository'));
    }

    public function testThePullsInSentenceIsTheS7SummaryAndNoS7IsNoSentence(): void
    {
        $s10 = new Signal(Signal::LEVEL_INFO, 'not checked', SignalDataStub::of(Signal::S10, ['unchecked' => [['check' => 'advisories', 'reason' => 'offline', 'blocks' => ['S9']]]]));
        $s7 = new Signal(Signal::LEVEL_INFO, 'pulls in 1 flagged package: a/b (stale)', SignalDataStub::of(Signal::S7, ['flagged' => 1, 'packages' => []]));

        self::assertSame('pulls in 1 flagged package: a/b (stale)', FindingBlock::pullsIn((new FindingBuilder())->withSignals([$s10, $s7])->build()));
        self::assertNull(FindingBlock::pullsIn((new FindingBuilder())->withSignals([$s10])->build()));
    }

    public function testChecksAcceptedJoinsOneGroupPerEntryInEntryOrder(): void
    {
        $activity = new Check('repository_activity', 'host_not_supported', ['S3', 'S4']);
        $advisories = new Check('advisories', 'no_feed', ['S9']);
        $byHost = UncheckedAcceptanceEntry::fromConfig(0, ['check' => 'repository_activity', 'host' => 'codeberg.org', 'reason' => 'we watch it by hand', 'expires' => '2027-03-31']);
        $byFeed = UncheckedAcceptanceEntry::fromConfig(1, ['check' => 'advisories', 'package' => 'vendor/pkg', 'reason' => 'no feed covers it', 'expires' => '2027-03-31']);
        $finding = (new FindingBuilder())->build();
        $details = FindingDetails::of(F::looked(F::facts(F::package(), F::metadata([['1.0.0', '2020-01-01T00:00:00+00:00']]))), null, new PhpFloor('8.4'), [], [], null, $finding->details()->age(), [new AcceptedCheck($activity, $byHost), new AcceptedCheck($advisories, $byFeed)]);

        self::assertSame('checks accepted: S3, S4 (host not supported; accepted by your accept-unchecked list: we watch it by hand) · S9 (no repository publishes advisories; accepted by your accept-unchecked list: no feed covers it)', FindingBlock::checksAccepted($finding->withDetails($details)));
        self::assertNull(FindingBlock::checksAccepted($finding));
    }
}
