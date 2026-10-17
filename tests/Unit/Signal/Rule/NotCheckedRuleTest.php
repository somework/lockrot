<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal\Rule;

use Lockrot\Clock;
use Lockrot\Data\Advisory\Advisory;
use Lockrot\Data\Advisory\AdvisoryNameCoverage;
use Lockrot\Data\Forge\ActivityLookup;
use Lockrot\Data\Forge\LocatedRepository;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Lock\ConfiguredRepositories;
use Lockrot\Lock\LockedPackage;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Signal\Data\Check;
use Lockrot\Signal\Data\NotCheckedData;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\Rule\LeftBehindRule;
use Lockrot\Signal\Rule\NotCheckedRule;
use Lockrot\Signal\Signal;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\SignalDataStub;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NotCheckedRuleTest extends TestCase
{
    private const NOW = '2026-09-14T00:00:00+00:00';

    private function rule(): NotCheckedRule
    {
        return new NotCheckedRule(new LeftBehindRule(Clock::fixed(self::NOW), new Thresholds()));
    }

    /** F::metadata()'s branches, with the newest tag undated, as PackageMetadata::fromPackages() leaves a tag that a shared commit dates. */
    private static function newestUndated(PackageMetadata $metadata): PackageMetadata
    {
        return new PackageMetadata('vendor/pkg', false, null, true, null, null, 12, null, 'library', new \DateTimeImmutable(self::NOW), $metadata->latestStableByBranch());
    }

    /** Metadata that dates its newest release, so only the activity round can be missing. */
    private static function dated(): PackageMetadata
    {
        return new PackageMetadata('vendor/pkg', false, null, true, new \DateTimeImmutable('2026-01-01T00:00:00+00:00'), '1.2.0', 12, null, 'library', new \DateTimeImmutable(self::NOW));
    }

    /** Metadata whose newest tag carries no date lockrot trusts: S2 has nothing to measure. */
    private static function undated(): PackageMetadata
    {
        return new PackageMetadata('vendor/pkg', false, null, true, null, null, 12, null, 'library', new \DateTimeImmutable(self::NOW));
    }

    public function testTheHostSummaryNamesTheHostLockrotCannotAsk(): void
    {
        $facts = (new PackageFacts(F::package(), self::dated(), null, []))
            ->withActivityLookup(ActivityLookup::notRead(ActivityLookup::HOST_NOT_SUPPORTED, LocatedRepository::unsupported('codeberg.org', null)));

        $signal = $this->rule()->evaluate($facts);

        self::assertNotNull($signal);
        self::assertSame('repository activity not checked (lockrot cannot ask codeberg.org), so S3 and S4 could not be read', $signal->summary());
        self::assertSame([['check' => 'repository_activity', 'reason' => 'host_not_supported', 'blocks' => [Signal::S3, Signal::S4]]], JsonPath::decoded($signal->data())['unchecked']);
    }

    /**
     * @dataProvider originWords
     */
    #[DataProvider('originWords')]
    public function testAVcsOrUnknownOriginRaisesBothOriginEntriesWordedByItsKind(LockedPackage $package, string $cause): void
    {
        $facts = (new PackageFacts($package, null, null, []))
            ->withActivityLookup(ActivityLookup::notRead(ActivityLookup::NOT_FROM_COMPOSER_REPOSITORY, LocatedRepository::none(null)));

        $signal = $this->rule()->evaluate($facts);

        self::assertNotNull($signal);
        self::assertSame(
            'release metadata not checked ('.$cause.'), so S2 and S8 could not be read; repository activity not checked ('.$cause.'), so S3 and S4 could not be read',
            $signal->summary()
        );
        self::assertSame([
            ['check' => 'release_metadata', 'reason' => 'not_from_composer_repository', 'blocks' => [Signal::S2, Signal::S8]],
            ['check' => 'repository_activity', 'reason' => 'not_from_composer_repository', 'blocks' => [Signal::S3, Signal::S4]],
        ], JsonPath::decoded($signal->data())['unchecked']);
    }

    /** @return iterable<string, array{LockedPackage, string}> */
    public static function originWords(): iterable
    {
        $vcs = LockFile::fromArray(['packages' => [['name' => 'acme/vcs', 'version' => '1.0.0', 'source' => ['type' => 'git', 'url' => 'https://github.com/acme/vcs.git', 'reference' => 'a']]]])->packages(false)[0]
            ->withRepositories(ProjectConfig::fromArray(['repositories' => [['type' => 'vcs', 'url' => 'https://github.com/acme/vcs.git']]])->repositories());

        yield 'vcs' => [$vcs, 'not installed from a Composer repository'];
        yield 'unknown' => [F::package(['fromComposerRepository' => false]), 'lockrot cannot tell which repository serves it'];
    }

    public function testAPathOriginRaisesNoOriginEntry(): void
    {
        $path = LockFile::fromArray(['packages' => [['name' => 'acme/local', 'version' => 'dev-main', 'dist' => ['type' => 'path', 'url' => 'packages/local', 'reference' => 'a']]]])->packages(false)[0]
            ->withRepositories(ConfiguredRepositories::none());
        $facts = (new PackageFacts($path, null, null, []))
            ->withActivityLookup(ActivityLookup::notRead(ActivityLookup::NOT_FROM_COMPOSER_REPOSITORY, LocatedRepository::none(null)));

        self::assertNull($this->rule()->evaluate($facts));
    }

    public function testARaisedS1DropsTheOriginEntriesWithTheActivityOne(): void
    {
        $facts = (new PackageFacts(F::package(['fromComposerRepository' => false]), null, null, []))
            ->withActivityLookup(ActivityLookup::notRead(ActivityLookup::NOT_FROM_COMPOSER_REPOSITORY, LocatedRepository::none(null)));
        $s10 = $this->rule()->evaluate($facts);
        self::assertNotNull($s10);

        self::assertSame([], NotCheckedRule::withoutMaintenanceGaps([$s10]));
    }

    public function testACheckedRunRaisesNothing(): void
    {
        $facts = new PackageFacts(F::package(), self::dated(), F::activity(false, '2026-09-01T00:00:00+00:00'), []);

        self::assertNull($this->rule()->evaluate($facts));
    }

    public function testASkippedActivityRoundNamesTheSignalsItBlocked(): void
    {
        $facts = new PackageFacts(F::package(), self::dated(), null, [], NotCheckedRule::NO_TOKEN);

        $signal = $this->rule()->evaluate($facts);

        self::assertNotNull($signal);
        self::assertSame(Signal::S10, $signal->id());
        self::assertSame(Signal::LEVEL_INFO, $signal->level(), 'it observes nothing, so it warns about nothing');
        self::assertSame('repository activity not checked (no token for the repository host), so S3 and S4 could not be read', $signal->summary());
        self::assertSame([Signal::S3, Signal::S4], JsonPath::decoded($signal->data())['blocks']);
        self::assertSame([['check' => 'repository_activity', 'reason' => NotCheckedRule::NO_TOKEN, 'blocks' => [Signal::S3, Signal::S4]]], JsonPath::decoded($signal->data())['unchecked']);
    }

    /**
     * @dataProvider reasons
     */
    #[DataProvider('reasons')]
    public function testEveryReasonTheRunCanGiveIsWorded(string $reason, string $words): void
    {
        $signal = $this->rule()->evaluate(new PackageFacts(F::package(), self::dated(), null, [], $reason));

        self::assertNotNull($signal);
        self::assertStringContainsString($words, $signal->summary());
        $unchecked = JsonPath::decoded($signal->data())['unchecked'];
        self::assertIsArray($unchecked);
        $first = $unchecked[0];
        self::assertIsArray($first);
        self::assertSame($reason, $first['reason']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function reasons(): iterable
    {
        yield 'no token' => [NotCheckedRule::NO_TOKEN, 'no token for the repository host'];
        yield 'the anonymous budget' => [NotCheckedRule::RATE_BUDGET, 'the anonymous request budget was spent'];
        yield 'the install-time budget' => [NotCheckedRule::BUDGET, 'the install-time budget ran out'];
        yield 'a rate limit' => [NotCheckedRule::RATE_LIMIT, 'too many requests'];
        yield 'a failed request' => [NotCheckedRule::FETCH_FAILED, 'the request failed'];
        yield 'offline' => [NotCheckedRule::OFFLINE, 'the run is offline'];
    }

    public function testAReasonTheRuleDoesNotKnowRaisesNothing(): void
    {
        self::assertNull($this->rule()->evaluate(new PackageFacts(F::package(), self::dated(), null, [], 'made up')));
    }

    public function testAnUndatedNewestReleaseIsTheOtherMissingCheck(): void
    {
        $signal = $this->rule()->evaluate(new PackageFacts(F::package(), self::undated(), F::activity(false, '2026-09-01T00:00:00+00:00'), []));

        self::assertNotNull($signal);
        self::assertStringContainsString('the age of the package was not read', $signal->summary());
        $unchecked = JsonPath::decoded($signal->data())['unchecked'];
        self::assertIsArray($unchecked);
        self::assertSame([['check' => 'release_dates', 'reason' => 'undated_releases', 'blocks' => [Signal::S2]]], $unchecked, 'no branch row is undated, so S8 has its input');
    }

    public function testBothChecksCanBeMissingAtOnce(): void
    {
        $signal = $this->rule()->evaluate(new PackageFacts(F::package(), self::undated(), null, [], NotCheckedRule::OFFLINE));

        self::assertNotNull($signal);
        self::assertSame([Signal::S2, Signal::S3, Signal::S4], JsonPath::decoded($signal->data())['blocks'], 'in signal order');
        $unchecked = JsonPath::decoded($signal->data())['unchecked'];
        self::assertIsArray($unchecked);
        self::assertCount(2, $unchecked);
        self::assertStringContainsString('; ', $signal->summary(), 'one sentence per missing check');
    }

    /**
     * S8 reads the installed version's release branch, and a branch snapshot is on none
     * ({@see \Lockrot\Data\Repository\ReleaseBranch::of()}), so the missing dates block S2 alone.
     * S2 is still worth naming: with S4 it is what turns `pinned` into `silent`.
     */
    public function testASnapshotsMissingDatesBlockTheAgeSignalOnly(): void
    {
        $signal = $this->rule()->evaluate(new PackageFacts(F::package(['version' => 'dev-main']), self::undated(), F::activity(false, '2026-09-01T00:00:00+00:00'), []));

        self::assertNotNull($signal);
        self::assertSame([Signal::S2], JsonPath::decoded($signal->data())['blocks']);
        self::assertSame([['check' => 'release_dates', 'reason' => 'undated_releases', 'blocks' => [Signal::S2]]], JsonPath::decoded($signal->data())['unchecked']);
        self::assertStringContainsString('so S2 could not measure it', $signal->summary());
        self::assertStringNotContainsString('S8', $signal->summary());
    }

    /**
     * scheb/2fa-google-authenticator v5.13.2 on wallabag: the newest tag is undated, and S8 fires
     * from the dated release of its own branch and of a higher one. Only S2 lost its input.
     */
    public function testAnUndatedNewestReleaseDoesNotBlockAnS8ThatFired(): void
    {
        $metadata = self::newestUndated(F::metadata([['v7.0.0', null], ['v6.1.0', '2026-03-01T00:00:00+00:00'], ['v5.13.2', '2021-12-01T00:00:00+00:00']]));
        $facts = new PackageFacts(F::package(['version' => 'v5.13.2']), $metadata, F::activity(false, '2026-09-01T00:00:00+00:00'), []);

        $signal = $this->rule()->evaluate($facts);

        self::assertNotNull((new LeftBehindRule(Clock::fixed(self::NOW), new Thresholds()))->evaluate($facts), 'S8 fires on these facts, or this test proves nothing');
        self::assertNotNull($signal);
        self::assertSame([['check' => 'release_dates', 'reason' => 'undated_releases', 'blocks' => [Signal::S2]]], JsonPath::decoded($signal->data())['unchecked']);
    }

    /** The installed branch's release is undated, the newest release is dated: S8 alone lost its input. */
    public function testAnUndatedInstalledBranchBlocksS8Alone(): void
    {
        $metadata = F::metadata([['v2.0.0', '2026-01-01T00:00:00+00:00'], ['v1.2.0', null]]);

        $signal = $this->rule()->evaluate(new PackageFacts(F::package(['version' => 'v1.2.0']), $metadata, F::activity(false, '2026-09-01T00:00:00+00:00'), []));

        self::assertNotNull($signal);
        self::assertSame([['check' => 'release_dates', 'reason' => 'undated_releases', 'blocks' => [Signal::S8]]], JsonPath::decoded($signal->data())['unchecked']);
        self::assertStringContainsString('so S8 could not measure it', $signal->summary());
    }

    /** The installed branch is quiet past `release-warn-years`, and the only higher branch is undated: that branch could have raised S8. */
    public function testAnUndatedHigherBranchBlocksS8OnAQuietBranch(): void
    {
        $metadata = self::newestUndated(F::metadata([['v2.0.0', null], ['v1.2.0', '2019-01-01T00:00:00+00:00']]));

        $signal = $this->rule()->evaluate(new PackageFacts(F::package(['version' => 'v1.2.0']), $metadata, F::activity(false, '2026-09-01T00:00:00+00:00'), []));

        self::assertNotNull($signal);
        self::assertSame([Signal::S2, Signal::S8], JsonPath::decoded($signal->data())['blocks']);
        self::assertStringContainsString('so S2 and S8 could not measure it', $signal->summary());
    }

    /** An installed branch within `release-warn-years` raises no S8 whatever a higher branch's date: S8 lost nothing. */
    public function testAnUndatedHigherBranchDoesNotBlockS8OnAReleasingBranch(): void
    {
        $metadata = self::newestUndated(F::metadata([['v2.0.0', null], ['v1.2.0', '2026-01-01T00:00:00+00:00']]));

        $signal = $this->rule()->evaluate(new PackageFacts(F::package(['version' => 'v1.2.0']), $metadata, F::activity(false, '2026-09-01T00:00:00+00:00'), []));

        self::assertNotNull($signal);
        self::assertSame([Signal::S2], JsonPath::decoded($signal->data())['blocks']);
    }

    public function testAPackageWithoutMetadataIsNotToldItsAgeWasNotRead(): void
    {
        // Its activity is dated, but without its metadata the verdict is `unknown`, and that verdict says it. The run's notes carry why.
        $signal = $this->rule()->evaluate(new PackageFacts(F::package(), null, F::activity(false, '2026-09-01T00:00:00+00:00'), []));

        self::assertNull($signal);
    }

    public function testAPackageWithNoStableReleaseIsNotToldItsAgeWasNotRead(): void
    {
        // S6 already says the package has no stable release. There is no age to read, not a missing check.
        $metadata = new PackageMetadata('vendor/pkg', false, null, false, null, null, 3, null, 'library', new \DateTimeImmutable(self::NOW));

        self::assertNull($this->rule()->evaluate(new PackageFacts(F::package(), $metadata, F::activity(false, '2026-09-01T00:00:00+00:00'), [])));
    }

    /** @return iterable<string, array{string, string}> each advisories reason that raises S10, with its words */
    public static function advisoryGaps(): iterable
    {
        yield 'offline' => ['offline', 'the run is offline'];
        yield 'Composer below 2.4' => ['composer_too_old', 'Composer is older than 2.4'];
        yield 'the install-time budget' => ['install_time_budget', 'the install-time budget ran out'];
        yield 'a repository that threw' => ['lookup_failed', 'an advisory repository failed'];
        yield 'no feed' => ['no_feed', 'no configured repository publishes advisories'];
    }

    /** @dataProvider advisoryGaps */
    #[DataProvider('advisoryGaps')]
    public function testAnAdvisoryLookupThatDidNotCompleteIsAnS10Entry(string $reason, string $cause): void
    {
        $facts = new PackageFacts(F::package(), self::dated(), F::activity(false, '2026-09-01T00:00:00+00:00'), [], null, null, new AdvisoryNameCoverage([], null, $reason));

        $s10 = $this->rule()->evaluate($facts);

        self::assertNotNull($s10);
        self::assertEquals(new NotCheckedData([new Check('advisories', $reason, [Signal::S9])]), $s10->data());
        self::assertSame('security advisories not checked ('.$cause.'), so S9 could not be read', $s10->summary());
    }

    /** @return iterable<string, array{?string}> */
    public static function advisorySkips(): iterable
    {
        yield 'outside the composer-repositories scope' => ['not_from_composer_repository'];
        yield 'a version Composer cannot parse' => ['unparseable_version'];
        yield 'a complete lookup' => [null];
    }

    /** @dataProvider advisorySkips */
    #[DataProvider('advisorySkips')]
    public function testASkippedOrCompleteAdvisoryLookupRaisesNoS10(?string $reason): void
    {
        $facts = new PackageFacts(F::package(), self::dated(), F::activity(false, '2026-09-01T00:00:00+00:00'), [], null, null, new AdvisoryNameCoverage([], null, $reason));

        self::assertNull($this->rule()->evaluate($facts));
    }

    /** A raised S1 makes the upkeep gaps moot, never another check: that entry keeps its own words. */
    public function testDroppingTheMaintenanceGapsKeepsAnyOtherEntryWithItsSummary(): void
    {
        $advisories = new Check('advisories', 'offline', [Signal::S9]);
        $s10 = new Signal(Signal::LEVEL_INFO, 'repository activity not checked (the run is offline), so S3 and S4 could not be read; advisories not checked (offline), so S9 could not be read', new NotCheckedData([
            new Check('repository_activity', 'offline', [Signal::S3, Signal::S4]),
            $advisories,
        ]));

        $kept = NotCheckedRule::withoutMaintenanceGaps([$s10]);

        self::assertCount(1, $kept);
        self::assertSame('security advisories not checked (the run is offline), so S9 could not be read', $kept[0]->summary(), 'the entry is worded again, never split out of the joined summary');
        $data = $kept[0]->data();
        self::assertInstanceOf(NotCheckedData::class, $data);
        self::assertSame([$advisories], $data->checks());
    }

    public function testAnS10OfMaintenanceChecksAloneGoesWhileTheOtherSignalsStay(): void
    {
        $s2 = new Signal(Signal::LEVEL_WARN, 'last release 2021-05-05 (5.4 years ago)', SignalDataStub::of(Signal::S2, []));
        $s10 = new Signal(Signal::LEVEL_INFO, 'not checked', new NotCheckedData([
            new Check('repository_activity', 'no_token', [Signal::S3, Signal::S4]),
            new Check('release_dates', 'undated_releases', [Signal::S2, Signal::S8]),
        ]));

        self::assertSame([$s2], NotCheckedRule::withoutMaintenanceGaps([$s2, $s10]));
    }

    /** `accept-unchecked`: S10 keeps the entries left, worded again, and every other signal stays in its place. */
    public function testKeepingNarrowsS10AndKeepsTheSignalsAroundIt(): void
    {
        $s2 = new Signal(Signal::LEVEL_WARN, 'last release 2021-05-05 (5.4 years ago)', SignalDataStub::of(Signal::S2, []));
        $s8 = new Signal(Signal::LEVEL_WARN, 'left behind', SignalDataStub::of(Signal::S8, []));
        $activity = new Check('repository_activity', 'host_not_supported', [Signal::S3, Signal::S4]);
        $s10 = new Signal(Signal::LEVEL_INFO, 'not checked', new NotCheckedData([$activity, new Check('advisories', 'no_feed', [Signal::S9])]));

        $kept = NotCheckedRule::keeping([$s2, $s10, $s8], [$activity], 'codeberg.org', null);

        self::assertSame([Signal::S2, Signal::S10, Signal::S8], array_map(static fn (Signal $signal): string => $signal->id(), $kept));
        self::assertSame('repository activity not checked (lockrot cannot ask codeberg.org), so S3 and S4 could not be read', $kept[1]->summary());
        self::assertSame([$s2, $s8], NotCheckedRule::keeping([$s2, $s10, $s8], [], null, null), 'S10 goes with no entry left');
    }

    /**
     * Release data that a Composer repository listing the package did not serve
     * leaves every fix unknown, so S9 is incomplete. Computed here, written by report-2 only:
     * report-1's S10 `check` list has no `releases`.
     */
    public function testUnservedReleaseDataBesideACountedAdvisoryIsAReleasesGap(): void
    {
        $advisory = new Advisory('PKSA-1', null, null, null, 'high', null, null);
        $unavailable = new PackageFacts(F::package(), null, null, [$advisory], null, null, null, true);

        self::assertEquals(new Check('releases', 'releases_unknown', [Signal::S9]), NotCheckedRule::releasesUnchecked($unavailable));
        self::assertSame(PackageFacts::METADATA_UNAVAILABLE, $unavailable->metadataStatus());
        self::assertSame(PackageFacts::METADATA_NOT_FOUND, (new PackageFacts(F::package(), null, null, [$advisory]))->metadataStatus(), 'facts built without the flag: no repository failed');
        self::assertNull($this->rule()->evaluate($unavailable), 'the signal itself does not carry it');
    }

    /** @dataProvider noReleasesGap */
    #[DataProvider('noReleasesGap')]
    public function testNoOtherMetadataStatusIsAReleasesGap(PackageFacts $facts, string $status): void
    {
        self::assertSame($status, $facts->metadataStatus());
        self::assertNull(NotCheckedRule::releasesUnchecked($facts));
    }

    /** @return iterable<string, array{PackageFacts, string}> */
    public static function noReleasesGap(): iterable
    {
        $advisory = new Advisory('PKSA-1', null, null, null, 'high', null, null);
        yield 'read' => [new PackageFacts(F::package(), self::dated(), null, [$advisory]), PackageFacts::METADATA_READ];
        yield 'not found' => [new PackageFacts(F::package(), null, null, [$advisory]), PackageFacts::METADATA_NOT_FOUND];
        yield 'not from a Composer repository' => [new PackageFacts(F::package(['fromComposerRepository' => false]), null, null, [$advisory], null, null, null, true), PackageFacts::METADATA_NOT_FROM_COMPOSER_REPOSITORY];
        yield 'unavailable with no counted advisory' => [new PackageFacts(F::package(), null, null, [], null, null, null, true), PackageFacts::METADATA_UNAVAILABLE];
    }
}
