<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Analyzer;

use Lockrot\Analyzer\Libyears;
use Lockrot\Analyzer\LibyearsMeasurement;
use Lockrot\Clock;
use Lockrot\Data\Repository\InstalledRelease;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Lock\LockedPackage;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\Origins;
use Lockrot\Verdict\Finding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LibyearsTest extends TestCase
{
    private const LOCKED_AT = '2022-01-03T10:21:24+00:00';

    private static function package(string $version = 'v5.13.2', ?string $time = self::LOCKED_AT, bool $fromComposerRepository = true, bool $dev = false): LockedPackage
    {
        return new LockedPackage('scheb/2fa-bundle', $version, $time === null ? null : new \DateTimeImmutable($time), null, [], null, 'library', Origins::facts($fromComposerRepository), $dev, false);
    }

    /** @param array<array-key, array{string, ?string}> $branches branch key (an int where PHP makes one) => [newest dated version, its date] */
    private static function metadata(?string $lastStableReleaseAt, array $branches = []): PackageMetadata
    {
        $byBranch = [];
        foreach ($branches as $key => [$version, $at]) {
            $date = $at === null ? null : new \DateTimeImmutable($at);
            $byBranch[$key] = ['version' => $version, 'at' => $date, 'highest' => ['normalized' => ltrim($version, 'v').'.0', 'pretty' => $version, 'at' => $date], 'php' => null];
        }

        return new PackageMetadata('scheb/2fa-bundle', false, null, true, $lastStableReleaseAt === null ? null : new \DateTimeImmutable($lastStableReleaseAt), 'v8.6.1', 12, null, 'library', new \DateTimeImmutable('2026-09-14T00:00:00+00:00'), $byBranch);
    }

    private static function finding(string $package, LibyearsMeasurement $libyears, bool $direct = true, string $version = '1.0.0', ?string $note = null): Finding
    {
        return (new FindingBuilder())->withPackage($package)->withVersion($version)->withChain($direct ? [$package] : ['vendor/root', $package])->withNote($note)->withLibyears($libyears)->withOrigin(Origins::of($note !== Finding::NOTE_NOT_IN_REPOSITORY, $package))->build();
    }

    /** @return iterable<string, array{LockedPackage, ?PackageMetadata, ?float, ?string}> the package, its metadata, the years and the reason */
    public static function measurements(): iterable
    {
        $latest = self::metadata('2026-01-24T13:26:10+00:00');
        yield 'a path entry' => [self::package('v5.13.2', self::LOCKED_AT, false), $latest, null, Libyears::NOT_FROM_COMPOSER_REPOSITORY];
        yield 'a path entry on a branch: not asked outranks a snapshot' => [self::package('dev-main', self::LOCKED_AT, false), $latest, null, Libyears::NOT_FROM_COMPOSER_REPOSITORY];
        yield 'a path entry without metadata: not asked outranks not answered' => [self::package('v5.13.2', self::LOCKED_AT, false), null, null, Libyears::NOT_FROM_COMPOSER_REPOSITORY];
        yield 'no metadata' => [self::package(), null, null, Libyears::METADATA_UNAVAILABLE];
        yield 'no metadata for a branch: not answered outranks a snapshot' => [self::package('dev-main'), null, null, Libyears::METADATA_UNAVAILABLE];
        yield 'a branch with metadata' => [self::package('dev-main'), $latest, null, Libyears::BRANCH_SNAPSHOT];
        yield 'a branch the lock does not date' => [self::package('2.x-dev', null), $latest, null, Libyears::BRANCH_SNAPSHOT];
        yield 'the installed tag on a shared commit' => [self::package(), self::splitPackage([], null), null, Libyears::NO_STABLE_RELEASE_DATE];
        yield 'a lock entry without a time' => [self::package('v5.13.2', null), $latest, null, Libyears::NO_STABLE_RELEASE_DATE];
        yield 'no newest date and nothing dated above' => [self::package(), self::metadata(null), null, Libyears::NO_STABLE_RELEASE_DATE];
        yield 'a lower bound, from the newest dated release above' => [self::package(), self::metadata(null, ['8' => ['v8.6.1', '2026-01-24T13:26:10+00:00'], '5' => ['v5.13.2', self::LOCKED_AT]]), 4.06, null];
        yield 'ahead of the last stable release, clamped to zero' => [self::package(), self::metadata('2019-01-23T15:23:04+00:00'), 0.0, null];
    }

    /**
     * The years and the reason: never both, never neither.
     *
     * @dataProvider measurements
     */
    #[DataProvider('measurements')]
    public function testEveryExitOfTheRuleSaysWhy(LockedPackage $package, ?PackageMetadata $metadata, ?float $years, ?string $reason): void
    {
        $measurement = Libyears::measure($package, $metadata);

        self::assertSame($reason, $measurement->unmeasuredReason());
        self::assertSame($years === null, $measurement->years() === null, 'measured exactly when no reason is given');
        self::assertNotSame($measurement->years() === null, $measurement->unmeasuredReason() === null, 'one of the two, never both');
        if ($years !== null) {
            self::assertEqualsWithDelta($years, $measurement->years(), 0.005);
        }
    }

    public function testAMeasurementHoldsItsYearsOrItsReason(): void
    {
        $measured = LibyearsMeasurement::of(1.5);
        $zero = LibyearsMeasurement::of(0.0);

        self::assertSame(1.5, $measured->years());
        self::assertNull($measured->unmeasuredReason());
        self::assertSame(0.0, $zero->years(), 'zero is a measurement');
        self::assertNull($zero->unmeasuredReason());
        foreach (Libyears::REASONS as $reason) {
            self::assertSame($reason, LibyearsMeasurement::unmeasured($reason)->unmeasuredReason());
            self::assertNull(LibyearsMeasurement::unmeasured($reason)->years());
        }
    }

    public function testAReasonOutsideTheListIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('bogus');

        LibyearsMeasurement::unmeasured('bogus');
    }

    public function testAMeasuredFindingHasNoReasonToPutIntoWords(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('a/a');

        Libyears::reasonWords(self::finding('a/a', LibyearsMeasurement::of(1.0)));
    }

    /** A finding with metadata, a note and a tag on a shared commit counts under the missing date, not under the note. */
    public function testTheReasonIsTheRulesNotTheNotes(): void
    {
        $measurement = Libyears::measure(self::package(), self::splitPackage([], null));
        $finding = self::finding('illuminate/macroable', $measurement, true, 'v5.13.2', 'a note that is no reason');

        self::assertSame(Libyears::NO_STABLE_RELEASE_DATE, $finding->libyearsUnmeasured());
        self::assertSame(1, Libyears::fromFindings([$finding])->unmeasured()[Libyears::NO_STABLE_RELEASE_DATE]);
        self::assertSame(0, Libyears::fromFindings([$finding])->unmeasured()[Libyears::METADATA_UNAVAILABLE]);
        self::assertSame('no release date lockrot trusts', Libyears::reasonWords($finding));
    }

    public function testTheBlockIsTheFindingsReasonsCounted(): void
    {
        $findings = [
            self::finding('measured/one', LibyearsMeasurement::of(1.0)),
            self::finding('measured/zero', LibyearsMeasurement::of(0.0)),
            self::finding('pinned/one', LibyearsMeasurement::unmeasured(Libyears::BRANCH_SNAPSHOT), true, 'dev-main'),
            self::finding('pinned/two', LibyearsMeasurement::unmeasured(Libyears::BRANCH_SNAPSHOT), false, '2.x-dev'),
            self::finding('gone/one', LibyearsMeasurement::unmeasured(Libyears::METADATA_UNAVAILABLE), true, '1.0.0', 'not found in the repository'),
        ];
        $reasons = [];
        foreach ($findings as $finding) {
            $reason = $finding->toArray()['libyears_unmeasured'];
            if ($reason !== null) {
                self::assertIsString($reason);
                $reasons[] = $reason;
            }
        }
        $block = Libyears::fromFindings($findings)->toArray();

        self::assertSame(array_merge(array_fill_keys(Libyears::REASONS, 0), array_count_values($reasons)), $block['unmeasured']);
        self::assertSame([Libyears::BRANCH_SNAPSHOT => 2, Libyears::NO_STABLE_RELEASE_DATE => 0, Libyears::NOT_FROM_COMPOSER_REPOSITORY => 0, Libyears::METADATA_UNAVAILABLE => 1], $block['unmeasured']);
        self::assertSame(2, $block['measured']);
    }

    public function testAYearOfSecondsIsOneLibyear(): void
    {
        $lockedAt = new \DateTimeImmutable(self::LOCKED_AT);
        $latest = $lockedAt->modify('+'.Clock::SECONDS_PER_YEAR.' seconds');

        self::assertSame(1.0, Libyears::measure(self::package(), self::metadata($latest->format(\DATE_ATOM)))->years());
    }

    public function testFourYearsBehindOnTheDatesTheRepositoryReports(): void
    {
        $behind = Libyears::measure(self::package(), self::metadata('2026-01-24T13:26:10+00:00'))->years();

        self::assertNotNull($behind);
        self::assertEqualsWithDelta(4.06, $behind, 0.005);
    }

    public function testALockAheadOfTheLastStableReleaseIsMeasuredAsZeroNotDropped(): void
    {
        // A lock on a pre-release above the last stable, or on a tag that the repository does not
        // list: measured, and not behind.
        self::assertSame(0.0, Libyears::measure(self::package(), self::metadata('2019-01-23T15:23:04+00:00'))->years());
    }

    public function testABranchSnapshotIsNotMeasuredEvenWithBothDates(): void
    {
        self::assertNull(Libyears::measure(self::package('dev-master'), self::metadata('2026-01-24T13:26:10+00:00'))->years());
        self::assertNull(Libyears::measure(self::package('1.x-dev'), self::metadata('2026-01-24T13:26:10+00:00'))->years());
    }

    public function testAPackageOutsideEveryComposerRepositoryIsNotMeasuredEvenWithMetadata(): void
    {
        self::assertNull(Libyears::measure(self::package('v5.13.2', self::LOCKED_AT, false), self::metadata('2026-01-24T13:26:10+00:00'))->years());
    }

    public function testNoMetadataNoMeasure(): void
    {
        self::assertNull(Libyears::measure(self::package(), null)->years());
    }

    public function testAnUndatedLastStableReleaseIsNotMeasuredWhenNothingAboveIsDatedEither(): void
    {
        self::assertNull(Libyears::measure(self::package(), self::metadata(null))->years());
        self::assertNull(Libyears::measure(self::package(), self::metadata(null, ['8' => ['v8.6.1', null], '5' => ['v5.13.2', self::LOCKED_AT]]))->years(), 'the installed branch alone, and its newest is the installed version');
    }

    /**
     * scheb/2fa-backup-code: v8.3.0 to v8.6.1 sit on one commit, all "2026-01-24", so the newest
     * release is undated — but a tag's commit is never younger than the release it names, so the
     * newest trusted date above the installed version bounds the answer from below.
     */
    public function testWhenTheNewestReleaseIsUndatedTheNewestTrustedDateAboveTheInstalledVersionCounts(): void
    {
        $metadata = self::metadata(null, [
            '8' => ['v8.6.1', '2026-01-24T13:26:10+00:00'],
            '7' => ['v7.14.0', '2026-01-24T12:00:00+00:00'],
            '6' => ['v6.13.1', '2024-11-29T00:00:00+00:00'],
            '5' => ['v5.13.2', self::LOCKED_AT],
        ]);
        $behind = Libyears::measure(self::package(), $metadata)->years();

        self::assertNotNull($behind);
        self::assertEqualsWithDelta(4.06, $behind, 0.005);
    }

    /**
     * The repository lists the branches in any order. An undated branch, a backport below, or a
     * lower version on the installed branch listed first must not stop the scan. The newest date wins.
     */
    public function testTheScanStepsOverWhatDoesNotCountAndKeepsTheNewestDateWhereverItIsListed(): void
    {
        $listedOldestFirst = self::metadata(null, [
            '8' => ['v8.6.1', null],
            '4' => ['v4.9.9', '2026-03-01T00:00:00+00:00'],
            '5' => ['v5.13.1', '2026-02-01T00:00:00+00:00'],
            '6' => ['v6.13.1', '2024-11-29T00:00:00+00:00'],
            '7' => ['v7.14.0', '2026-01-24T13:26:10+00:00'],
        ]);
        $behind = Libyears::measure(self::package(), $listedOldestFirst)->years();

        self::assertNotNull($behind);
        self::assertEqualsWithDelta(4.06, $behind, 0.005, 'the 7.x date, not the 6.x one listed before it, and none of the three that do not count');
    }

    public function testABackportOnALowerBranchReleasedLaterIsNotAheadOfTheInstalledVersion(): void
    {
        $metadata = self::metadata(null, [
            '5' => ['v5.13.2', self::LOCKED_AT],
            '4' => ['v4.9.9', '2026-03-01T00:00:00+00:00'],
        ]);

        self::assertNull(Libyears::measure(self::package(), $metadata)->years());
    }

    public function testOnTheInstalledBranchOnlyAHigherVersionCounts(): void
    {
        $newerPatch = self::metadata(null, ['5' => ['v5.14.0', '2023-06-01T00:00:00+00:00']]);
        $behind = Libyears::measure(self::package(), $newerPatch)->years();
        self::assertNotNull($behind);
        self::assertEqualsWithDelta(1.41, $behind, 0.005);

        // the branch's newest dated release below the installed version — a re-tag dated later — is not movement forward
        $lowerDatedLater = self::metadata(null, ['5' => ['v5.13.1', '2023-06-01T00:00:00+00:00']]);
        self::assertNull(Libyears::measure(self::package(), $lowerDatedLater)->years());
    }

    public function testATrustedNewestReleaseDateOutranksTheBranchView(): void
    {
        $behind = Libyears::measure(self::package(), self::metadata('2026-01-24T13:26:10+00:00', ['8' => ['v8.6.1', '2030-01-01T00:00:00+00:00']]))->years();
        self::assertNotNull($behind);
        self::assertEqualsWithDelta(4.06, $behind, 0.005);
    }

    public function testALockEntryWithoutATimeIsNotMeasured(): void
    {
        self::assertNull(Libyears::measure(self::package('v5.13.2', null), self::metadata('2026-01-24T13:26:10+00:00'))->years());
    }

    private const LATEST = '2026-01-24T13:26:10+00:00';

    /**
     * A split package dated by laravel/framework: the newest release's date came from the parent,
     * and the parent's tag for the installed v5.13.2 is dated too — two years before the newest.
     *
     * @param array<string, string> $parentDates by normalized version
     * @param array<string, true>   $shared      the normalized versions whose tags share a commit
     */
    private static function splitPackage(array $parentDates, ?string $lastStableDatedBy = 'laravel/framework', array $shared = ['5.13.2.0' => true]): PackageMetadata
    {
        $dates = [];
        foreach ($parentDates as $version => $at) {
            $dates[$version] = new \DateTimeImmutable($at);
        }

        return new PackageMetadata('illuminate/macroable', false, null, true, new \DateTimeImmutable(self::LATEST), 'v13.31.0', 120, null, 'library', new \DateTimeImmutable('2026-09-14T00:00:00+00:00'), [], [], $lastStableDatedBy, $dates, $dates === [] ? null : 'laravel/framework', $shared);
    }

    private static function twoYearsBefore(string $date): string
    {
        return (new \DateTimeImmutable($date))->modify('-'.(2 * Clock::SECONDS_PER_YEAR).' seconds')->format(\DATE_ATOM);
    }

    public function testASplitPackageIsMeasuredFromItsParentsDateForTheInstalledVersion(): void
    {
        // The lock says 2022-01-03 for v5.13.2, the commit its tags share, not the release. The
        // parent's v5.13.2 says two years before the newest, and that is the installed end.
        $metadata = self::splitPackage(['5.13.2.0' => self::twoYearsBefore(self::LATEST)]);

        self::assertSame(2.0, Libyears::measure(self::package(), $metadata)->years());
        self::assertEquals(new \DateTimeImmutable(self::twoYearsBefore(self::LATEST)), InstalledRelease::of(self::package(), $metadata)->at());
        self::assertSame('laravel/framework', InstalledRelease::of(self::package(), $metadata)->datedBy());
    }

    public function testASplitPackageWhoseParentDoesNotDateTheInstalledVersionIsNotMeasured(): void
    {
        // The newest release's date came from the parent, so the lock's date for the installed
        // version is the shared commit's, and a measurement from that date inflates the sum.
        $neighbour = self::splitPackage(['5.13.3.0' => self::twoYearsBefore(self::LATEST)]);

        self::assertNull(Libyears::measure(self::package(), self::splitPackage([]))->years());
        self::assertNull(Libyears::measure(self::package(), $neighbour)->years(), 'a neighbouring version is not this one');
        self::assertNull(InstalledRelease::of(self::package(), self::splitPackage([]))->at());
        self::assertSame('laravel/framework', $neighbour->releaseDatesBy());
        self::assertNull(InstalledRelease::of(self::package(), $neighbour)->datedBy(), 'the parent has dates, none of them for this version');
    }

    public function testTheParentsDateOutranksTheLocksEvenWhenThePackageDatedItsNewestReleaseItself(): void
    {
        // illuminate/contracts: the newest tag sits on a commit of its own and is dated by the
        // package itself, but the installed v8.83.27 sits on a commit that many tags share, so the
        // lock's date is early. The parent dates the installed version whenever it can.
        $metadata = self::splitPackage(['5.13.2.0' => self::twoYearsBefore(self::LATEST)], null);

        self::assertNull($metadata->lastStableDatedBy());
        self::assertSame(2.0, Libyears::measure(self::package(), $metadata)->years());
    }

    /** Where no parent dates the installed version, the lock's date is a commit's: nothing to measure from. */
    public function testATagOnASharedCommitIsNotMeasuredFromTheLocksDate(): void
    {
        self::assertNull(Libyears::measure(self::package(), self::splitPackage([], null))->years());
    }

    public function testWithoutAParentAPackageIsMeasuredFromTheLocksDate(): void
    {
        // a tag with a commit to itself: the lock's date is its release's, parent or no parent
        $metadata = self::splitPackage([], null, []);

        self::assertEquals(new \DateTimeImmutable(self::LOCKED_AT), InstalledRelease::of(self::package(), $metadata)->at());
        self::assertEqualsWithDelta(4.06, Libyears::measure(self::package(), $metadata)->years() ?? 0.0, 0.005);
    }

    public function testAVersionComposerCannotNormalizeIsMeasuredFromTheLocksDate(): void
    {
        $metadata = self::splitPackage(['5.13.2.0' => self::twoYearsBefore(self::LATEST)], null, []);

        self::assertEquals(new \DateTimeImmutable(self::LOCKED_AT), InstalledRelease::of(self::package('not a version'), $metadata)->at());
    }

    public function testASnapshotHasNoReleaseDateToMeasureFrom(): void
    {
        $metadata = self::splitPackage(['5.13.2.0' => self::twoYearsBefore(self::LATEST)], null, []);

        self::assertNull(InstalledRelease::of(self::package('dev-main'), $metadata)->at());
        self::assertNull(InstalledRelease::of(self::package('dev-main'), $metadata)->datedBy(), 'the parent dates releases, and a branch is not one');
    }

    public function testADevelopmentPackageIsMeasuredLikeAnyOther(): void
    {
        $behind = Libyears::measure(self::package('v5.13.2', self::LOCKED_AT, true, true), self::metadata('2026-01-24T13:26:10+00:00'))->years();

        self::assertNotNull($behind);
        self::assertEqualsWithDelta(4.06, $behind, 0.005);
    }

    public function testTheTotalsSumTheUnroundedValuesAndRoundOnce(): void
    {
        $block = Libyears::fromFindings([self::finding('a/a', LibyearsMeasurement::of(1.005)), self::finding('b/b', LibyearsMeasurement::of(1.005))]);

        self::assertSame(2.01, $block->total());
        self::assertSame([2.01, 2.01], [$block->toArray()['total'], $block->toArray()['direct_requirements']]);
    }

    public function testDirectCountsOnlyTheDirectRequirements(): void
    {
        $block = Libyears::fromFindings([self::finding('a/a', LibyearsMeasurement::of(4.0)), self::finding('b/b', LibyearsMeasurement::of(2.5), false)]);

        self::assertSame(6.5, $block->total());
        self::assertSame(4.0, $block->direct());
        self::assertSame(2, $block->measured());
    }

    public function testEveryReasonANullIsFiledUnder(): void
    {
        $block = Libyears::fromFindings([
            self::finding('measured/one', LibyearsMeasurement::of(1.0)),
            self::finding('path/local', LibyearsMeasurement::unmeasured(Libyears::NOT_FROM_COMPOSER_REPOSITORY), true, 'dev-main', Finding::NOTE_NOT_IN_REPOSITORY),
            self::finding('gone/missing', LibyearsMeasurement::unmeasured(Libyears::METADATA_UNAVAILABLE), true, '1.0.0', 'not found in the repository'),
            self::finding('gone/failed', LibyearsMeasurement::unmeasured(Libyears::METADATA_UNAVAILABLE), true, '1.0.0', 'Repository metadata unavailable: timeout'),
            self::finding('pinned/main', LibyearsMeasurement::unmeasured(Libyears::BRANCH_SNAPSHOT), true, 'dev-main'),
            self::finding('pinned/branch', LibyearsMeasurement::unmeasured(Libyears::BRANCH_SNAPSHOT), true, '2.x-dev'),
            self::finding('undated/split', LibyearsMeasurement::unmeasured(Libyears::NO_STABLE_RELEASE_DATE), true, 'v1.37.0'),
        ]);

        self::assertSame([
            Libyears::BRANCH_SNAPSHOT => 2,
            Libyears::NO_STABLE_RELEASE_DATE => 1,
            Libyears::NOT_FROM_COMPOSER_REPOSITORY => 1,
            Libyears::METADATA_UNAVAILABLE => 2,
        ], $block->unmeasured());
        self::assertSame(1, $block->measured());
    }

    public function testEveryReasonHasWordsOfItsOwn(): void
    {
        self::assertSame('branch snapshot', Libyears::reasonWords(self::finding('pinned/main', LibyearsMeasurement::unmeasured(Libyears::BRANCH_SNAPSHOT), true, 'dev-main')));
        self::assertSame('no release date lockrot trusts', Libyears::reasonWords(self::finding('undated/split', LibyearsMeasurement::unmeasured(Libyears::NO_STABLE_RELEASE_DATE), true, 'v1.37.0')));
        self::assertSame('not from a Composer repository', Libyears::reasonWords(self::finding('path/local', LibyearsMeasurement::unmeasured(Libyears::NOT_FROM_COMPOSER_REPOSITORY), true, 'dev-main', Finding::NOTE_NOT_IN_REPOSITORY)));
        self::assertSame('metadata unavailable', Libyears::reasonWords(self::finding('gone/failed', LibyearsMeasurement::unmeasured(Libyears::METADATA_UNAVAILABLE), true, '1.0.0', 'Repository metadata unavailable: timeout')));
    }

    public function testTheWorstIsTheMaximumWithTiesGoingToTheFirstName(): void
    {
        $block = Libyears::fromFindings([
            self::finding('zeta/pkg', LibyearsMeasurement::of(3.0)),
            self::finding('alpha/pkg', LibyearsMeasurement::of(3.0)),
            self::finding('mid/pkg', LibyearsMeasurement::of(2.0)),
        ]);
        $worst = $block->worst();

        self::assertNotNull($worst);
        self::assertSame('alpha/pkg', $worst->package());
        // and the same answer when the first-named one comes first: a tie never goes to whoever came later
        $reversed = Libyears::fromFindings([self::finding('alpha/pkg', LibyearsMeasurement::of(3.0)), self::finding('zeta/pkg', LibyearsMeasurement::of(3.0))])->worst();
        self::assertNotNull($reversed);
        self::assertSame('alpha/pkg', $reversed->package());
    }

    public function testALockWithNothingBehindNamesNoWorstPackage(): void
    {
        $block = Libyears::fromFindings([self::finding('a/a', LibyearsMeasurement::of(0.0)), self::finding('b/b', LibyearsMeasurement::of(0.0), false)]);

        self::assertNull($block->worst());
        self::assertSame(2, $block->measured());
        self::assertNull($block->toArray()['furthest_behind']);
        self::assertSame('libyears: 0.0 behind across all 2 packages', $block->line());
        // A package at zero never outranks one that is behind, whatever the order.
        $worst = Libyears::fromFindings([self::finding('a/a', LibyearsMeasurement::of(0.0)), self::finding('b/b', LibyearsMeasurement::of(0.4))])->worst();
        self::assertNotNull($worst);
        self::assertSame('b/b', $worst->package());
    }

    public function testAnEmptyRunMeasuresNothing(): void
    {
        $block = Libyears::fromFindings([]);

        self::assertNull($block->total(), 'no package was measured, so there is no sum — zero would read as "nothing is behind"');
        self::assertNull($block->direct());
        self::assertSame(0, $block->measured());
        self::assertNull($block->worst());
        self::assertSame('libyears: nothing to measure', $block->line());
        self::assertNull($block->toArray()['furthest_behind']);
        self::assertNull($block->toArray()['total'], 'and the document says so too');
        self::assertNull($block->toArray()['direct_requirements']);
    }

    /**
     * Zero and null are different answers: a run that measured packages and found none behind is
     * `0.0`, a run that could measure nothing at all has no number. A reader that sums the field over
     * several projects otherwise counts an unmeasurable lock as a lock with nothing to fix.
     */
    public function testNothingMeasuredHasNoTotalWhileNothingBehindIsZero(): void
    {
        $unmeasurable = Libyears::fromFindings([
            self::finding('pinned/main', LibyearsMeasurement::unmeasured(Libyears::BRANCH_SNAPSHOT), true, 'dev-main'),
            self::finding('gone/missing', LibyearsMeasurement::unmeasured(Libyears::METADATA_UNAVAILABLE), true, '1.0.0', 'not found in the repository'),
        ]);
        $measuredAndCurrent = Libyears::fromFindings([
            self::finding('a/a', LibyearsMeasurement::of(0.0)),
            self::finding('b/b', LibyearsMeasurement::of(0.0), false),
        ]);

        self::assertNull($unmeasurable->total());
        self::assertNull($unmeasurable->toArray()['total']);
        self::assertNull($unmeasurable->toArray()['direct_requirements']);
        self::assertSame(0, $unmeasurable->measured());
        self::assertSame(0.0, $measuredAndCurrent->total(), 'measured, and nothing is behind');
        self::assertSame(0.0, $measuredAndCurrent->toArray()['total']);
        self::assertSame(0.0, $measuredAndCurrent->toArray()['direct_requirements']);
        self::assertSame(2, $measuredAndCurrent->measured());
    }

    public function testTheLineNamesTheTotalItsScopeTheDirectShareAndThePackageFurthestBehind(): void
    {
        $block = Libyears::fromFindings([
            self::finding('smalot/pdfparser', LibyearsMeasurement::of(4.7123), true, 'v1.1.0'),
            self::finding('psr/log', LibyearsMeasurement::of(3.36), false, '1.1.4'),
            self::finding('wallabag/rulerz', LibyearsMeasurement::unmeasured(Libyears::BRANCH_SNAPSHOT), true, 'dev-master'),
        ]);

        self::assertSame('libyears: 8.1 behind across 2 of 3 packages · 4.7 from direct requirements · furthest behind smalot/pdfparser v1.1.0 at 4.7', $block->line());
    }

    public function testTheLineSaysAllWhenEveryPackageWasMeasured(): void
    {
        $block = Libyears::fromFindings([self::finding('a/a', LibyearsMeasurement::of(1.0))]);

        self::assertSame('libyears: 1.0 behind across the one package · 1.0 from direct requirements · furthest behind a/a 1.0.0 at 1.0', $block->line());
        self::assertSame('libyears: 2.0 behind across all 2 packages · 2.0 from direct requirements · furthest behind a/a 1.0.0 at 1.0', Libyears::fromFindings([self::finding('a/a', LibyearsMeasurement::of(1.0)), self::finding('b/b', LibyearsMeasurement::of(1.0))])->line());
    }

    public function testTheLineSaysSoWhenNothingCouldBeMeasured(): void
    {
        $block = Libyears::fromFindings([self::finding('a/a', LibyearsMeasurement::unmeasured(Libyears::BRANCH_SNAPSHOT), true, 'dev-main'), self::finding('b/b', LibyearsMeasurement::unmeasured(Libyears::BRANCH_SNAPSHOT), true, 'dev-main')]);

        self::assertSame('libyears: none of the 2 packages could be measured', $block->line());
        self::assertSame('libyears: the one package could not be measured', Libyears::fromFindings([self::finding('a/a', LibyearsMeasurement::unmeasured(Libyears::BRANCH_SNAPSHOT), true, 'dev-main')])->line());
    }

    public function testTheArrayIsTheBlockTheSchemaDescribes(): void
    {
        $block = Libyears::fromFindings([
            self::finding('smalot/pdfparser', LibyearsMeasurement::of(4.7123), true, 'v1.1.0'),
            self::finding('psr/log', LibyearsMeasurement::of(3.36), false, '1.1.4'),
            self::finding('wallabag/rulerz', LibyearsMeasurement::unmeasured(Libyears::BRANCH_SNAPSHOT), true, 'dev-master'),
        ]);

        self::assertSame([
            'total' => 8.07,
            'direct_requirements' => 4.71,
            'measured' => 2,
            'unmeasured' => [
                Libyears::BRANCH_SNAPSHOT => 1,
                Libyears::NO_STABLE_RELEASE_DATE => 0,
                Libyears::NOT_FROM_COMPOSER_REPOSITORY => 0,
                Libyears::METADATA_UNAVAILABLE => 0,
            ],
            'furthest_behind' => ['package' => 'smalot/pdfparser', 'version' => 'v1.1.0', 'libyears' => 4.71],
        ], $block->toArray());
    }
}
