<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Lockrot\Clock;
use Lockrot\Data\Forge\ActivityLookup;
use Lockrot\Data\Forge\LocatedRepository;
use Lockrot\Data\Forge\RepoRef;
use Lockrot\Signal\AgeMeasure;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use Lockrot\Verdict\Age;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FindingAgeTest extends TestCase
{
    private const LONG_AGO = '2020-09-14T00:00:00+00:00';

    private static function age(PackageFacts $facts): array
    {
        return JsonPath::decoded(Age::of(new AgeMeasure(Clock::fixed(F::NOW), new Thresholds()), $facts));
    }

    /** @return list<array{0: string, 1: ?string}> a living 2.x and the installed 1.x, last released long ago */
    private static function releases(): array
    {
        return [['2.0.0', '2026-08-01T00:00:00+00:00'], ['1.2.0', self::LONG_AGO]];
    }

    public function testEveryReadingIsWrittenWithItsOwnKeys(): void
    {
        $facts = F::facts(F::package(['version' => '1.2.0', 'time' => self::LONG_AGO]), F::metadata(self::releases()), F::activity(false, self::LONG_AGO));

        self::assertSame([
            'installed' => ['at' => self::LONG_AGO, 'years' => 6, 'dated_by' => null],
            'installed_unmeasured' => null,
            'release' => ['version' => '2.0.0', 'at' => '2026-08-01T00:00:00+00:00', 'years' => 0.1, 'level' => null, 'dated_by' => null],
            'release_unmeasured' => null,
            'branch_release' => ['version' => '1.2.0', 'at' => self::LONG_AGO, 'years' => 6, 'level' => 'high', 'dated_by' => null],
            'branch_release_unmeasured' => null,
            'push' => ['at' => self::LONG_AGO, 'years' => 6, 'level' => 'high'],
            'push_unmeasured' => null,
        ], self::age($facts));
    }

    /** @return iterable<string, array{string}> */
    public static function metadataStatuses(): iterable
    {
        yield 'unavailable' => [PackageFacts::METADATA_UNAVAILABLE];
        yield 'not found' => [PackageFacts::METADATA_NOT_FOUND];
    }

    /**
     * The metadata was not read: both release readings take its status. The installed reading
     * reads the lock, so it stays measured.
     *
     * @dataProvider metadataStatuses
     */
    #[DataProvider('metadataStatuses')]
    public function testMetadataThatWasNotReadGivesItsStatusToBothReleaseReadings(string $status): void
    {
        $facts = new PackageFacts(F::package(['version' => '1.2.0', 'time' => self::LONG_AGO]), null, null, [], null, null, null, $status === PackageFacts::METADATA_UNAVAILABLE);
        $age = self::age($facts->withActivityLookup(ActivityLookup::notRead(ActivityLookup::NO_REPOSITORY, LocatedRepository::none(null))));

        self::assertSame([null, $status, null, $status], [$age['release'], $age['release_unmeasured'], $age['branch_release'], $age['branch_release_unmeasured']]);
        self::assertSame(self::LONG_AGO, JsonPath::stringAt($age, ['installed', 'at']));
    }

    public function testAPackageFromNoComposerRepositoryHasNoReleaseReadings(): void
    {
        $facts = F::looked(F::facts(F::package(['version' => '1.2.0', 'fromComposerRepository' => false])), ActivityLookup::NOT_FROM_COMPOSER_REPOSITORY);
        $age = self::age($facts);

        self::assertSame(['not_from_composer_repository', 'not_from_composer_repository', 'not_from_composer_repository'], [$age['release_unmeasured'], $age['branch_release_unmeasured'], $age['push_unmeasured']]);
        self::assertSame('undated', $age['installed_unmeasured'], 'the lock entry carries no time');
    }

    /** @return iterable<string, array{string}> */
    public static function activityReasons(): iterable
    {
        foreach (['allowlisted', 'no_repository', 'host_not_supported', 'offline', 'install_time_budget', 'no_token', 'anonymous_budget', 'rate_limit', 'fetch_failed'] as $reason) {
            yield $reason => [$reason];
        }
    }

    /**
     * An activity entry that blocks S4 leaves no push reading, with the entry's reason.
     *
     * @dataProvider activityReasons
     */
    #[DataProvider('activityReasons')]
    public function testAnActivityThatWasNotReadGivesItsReasonToThePush(string $reason): void
    {
        $age = self::age(F::looked(F::facts(F::package(), F::metadata(self::releases())), $reason));

        self::assertNull($age['push']);
        self::assertSame($reason, $age['push_unmeasured']);
    }

    public function testARepositoryThatTheHostDoesNotKnowGivesThePushItsReason(): void
    {
        $facts = F::facts(F::package(), F::metadata(self::releases()))->withActivityLookup(ActivityLookup::notFound(new RepoRef(RepoRef::GITHUB, 'github.com', 'vendor/pkg')));

        self::assertSame([null, 'repository_not_found'], [self::age($facts)['push'], self::age($facts)['push_unmeasured']]);
    }

    public function testAReadWithoutADateIsUndated(): void
    {
        $age = self::age(F::facts(F::package(), F::metadata(self::releases()), F::activity(false, null)));

        self::assertSame([null, 'undated'], [$age['push'], $age['push_unmeasured']]);
    }

    /** The coverage variant `metadata-unavailable`: the release metadata did not come, and the host answered. */
    public function testAReleaseMetadataGapBesideAReadActivityKeepsAMeasuredPush(): void
    {
        $facts = (new PackageFacts(F::package(['version' => '1.2.0']), null, null, [], null, null, null, true))->withActivityLookup(ActivityLookup::read(F::activity(false, self::LONG_AGO)));
        $age = self::age($facts);

        self::assertSame(['at' => self::LONG_AGO, 'years' => 6, 'level' => 'high'], $age['push']);
        self::assertSame('unavailable', $age['release_unmeasured']);
    }

    /** No signal decides a reading's level: an allowlisted package keeps the level its reading passes. */
    public function testTheLevelIsTheReadingsWhateverAcceptsThePackage(): void
    {
        $facts = F::looked(F::facts(F::package(['version' => '1.2.0']), F::metadata([['1.2.0', self::LONG_AGO]])), ActivityLookup::ALLOWLISTED);

        self::assertSame('high', JsonPath::stringAt(self::age($facts), ['release', 'level']));
    }

    public function testASnapshotThatNamesNoLineHasNoInstalledOrBranchReading(): void
    {
        $age = self::age(F::looked(F::facts(F::package(['version' => 'dev-main', 'time' => self::LONG_AGO]), F::metadata(self::releases()))));

        self::assertSame(['branch_snapshot', 'branch_snapshot'], [$age['installed_unmeasured'], $age['branch_release_unmeasured']]);
    }

    public function testABranchAliasSnapshotReadsItsOwnLine(): void
    {
        $age = self::age(F::looked(F::facts(F::package(['version' => '1.x-dev', 'time' => self::LONG_AGO]), F::metadata(self::releases()))));

        self::assertSame('branch_snapshot', $age['installed_unmeasured'], 'a snapshot is no release');
        self::assertSame(['1.2.0', self::LONG_AGO], [JsonPath::stringAt($age, ['branch_release', 'version']), JsonPath::stringAt($age, ['branch_release', 'at'])]);
    }

    public function testEachReadingIsNullExactlyWhenItsReasonIsSet(): void
    {
        foreach ([self::age(F::looked(F::facts(F::package()))), self::age(F::facts(F::package(['time' => self::LONG_AGO]), F::metadata(self::releases()), F::activity(true, self::LONG_AGO)))] as $age) {
            foreach (['installed', 'release', 'branch_release', 'push'] as $reading) {
                self::assertNotSame($age[$reading] === null, $age[$reading.'_unmeasured'] === null, $reading);
            }
        }
    }
}
