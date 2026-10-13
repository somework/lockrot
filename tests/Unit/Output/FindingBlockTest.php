<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Allowlist\AcceptedCheck;
use Lockrot\Allowlist\UncheckedAcceptanceEntry;
use Lockrot\Lock\ConfiguredRepositories;
use Lockrot\Lock\OriginFacts;
use Lockrot\Lock\PackageOrigin;
use Lockrot\Output\FindingBlock;
use Lockrot\Signal\Data\Check;
use Lockrot\Signal\PhpFloor;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\SignalDataStub;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\FindingDetails;
use PHPUnit\Framework\TestCase;

/** The parts of a finding block that the cases do not reach. */
final class FindingBlockTest extends TestCase
{
    /** @param list<array{check: string, reason: string, blocks: list<string>}> $unchecked */
    private static function unchecked(array $unchecked, ?PackageOrigin $origin = null): Finding
    {
        return (new FindingBuilder())->withOrigin($origin)->withSignals([new Signal(Signal::LEVEL_INFO, 'not checked', SignalDataStub::of(Signal::S10, ['unchecked' => $unchecked]))])->build();
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
