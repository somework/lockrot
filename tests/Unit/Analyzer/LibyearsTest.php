<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Analyzer;

use Lockrot\Analyzer\Analyzer;
use Lockrot\Analyzer\Libyears;
use Lockrot\Clock;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Lock\LockedPackage;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\TestCase;

final class LibyearsTest extends TestCase
{
    private const LOCKED_AT = '2022-01-03T10:21:24+00:00';

    private function package(string $version = 'v5.13.2', ?string $time = self::LOCKED_AT, bool $fromComposerRepository = true, bool $dev = false): LockedPackage
    {
        return new LockedPackage('scheb/2fa-bundle', $version, $time === null ? null : new \DateTimeImmutable($time), null, [], null, 'library', $fromComposerRepository, $dev, false);
    }

    /** @param array<array-key, array{string, ?string}> $branches branch key (an int where PHP makes one) => [newest dated version, its date] */
    private function metadata(?string $lastStableReleaseAt, array $branches = []): PackageMetadata
    {
        $byBranch = [];
        foreach ($branches as $key => [$version, $at]) {
            $date = $at === null ? null : new \DateTimeImmutable($at);
            $byBranch[$key] = ['version' => $version, 'at' => $date, 'highest' => ['normalized' => ltrim($version, 'v').'.0', 'pretty' => $version, 'at' => $date]];
        }

        return new PackageMetadata('scheb/2fa-bundle', false, null, true, $lastStableReleaseAt === null ? null : new \DateTimeImmutable($lastStableReleaseAt), 'v8.6.1', 12, null, 'library', new \DateTimeImmutable('2026-09-14T00:00:00+00:00'), $byBranch);
    }

    private static function finding(string $package, ?float $libyears, bool $direct = true, string $version = '1.0.0', ?string $note = null): Finding
    {
        return new Finding($package, $version, Verdict::OK, [], $direct ? [$package] : ['vendor/root', $package], null, null, $note, false, [], $libyears);
    }

    // ---- the per-package rule --------------------------------------------------------------

    public function testAYearOfSecondsIsOneLibyear(): void
    {
        $lockedAt = new \DateTimeImmutable(self::LOCKED_AT);
        $latest = $lockedAt->modify('+'.Clock::SECONDS_PER_YEAR.' seconds');

        self::assertSame(1.0, Libyears::behind($this->package(), $this->metadata($latest->format(\DATE_ATOM))));
    }

    public function testFourYearsBehindOnTheDatesTheRepositoryReports(): void
    {
        $behind = Libyears::behind($this->package(), $this->metadata('2026-01-24T13:26:10+00:00'));

        self::assertNotNull($behind);
        self::assertEqualsWithDelta(4.06, $behind, 0.005);
    }

    public function testALockAheadOfTheLastStableReleaseIsMeasuredAsZeroNotDropped(): void
    {
        // A lock on a pre-release above the last stable, or on a tag the repository no longer
        // lists: measured, and not behind.
        self::assertSame(0.0, Libyears::behind($this->package(), $this->metadata('2019-01-23T15:23:04+00:00')));
    }

    public function testABranchSnapshotIsNotMeasuredEvenWithBothDates(): void
    {
        self::assertNull(Libyears::behind($this->package('dev-master'), $this->metadata('2026-01-24T13:26:10+00:00')));
        self::assertNull(Libyears::behind($this->package('1.x-dev'), $this->metadata('2026-01-24T13:26:10+00:00')));
    }

    public function testAPackageOutsideEveryComposerRepositoryIsNotMeasuredEvenWithMetadata(): void
    {
        self::assertNull(Libyears::behind($this->package('v5.13.2', self::LOCKED_AT, false), $this->metadata('2026-01-24T13:26:10+00:00')));
    }

    public function testNoMetadataNoMeasure(): void
    {
        self::assertNull(Libyears::behind($this->package(), null));
    }

    public function testAnUndatedLastStableReleaseIsNotMeasuredWhenNothingAboveIsDatedEither(): void
    {
        self::assertNull(Libyears::behind($this->package(), $this->metadata(null)));
        self::assertNull(Libyears::behind($this->package(), $this->metadata(null, ['8' => ['v8.6.1', null], '5' => ['v5.13.2', self::LOCKED_AT]])), 'the installed branch alone, and its newest is the installed version');
    }

    /**
     * scheb/2fa-backup-code: v8.3.0 to v8.6.1 sit on one commit, all "2026-01-24", so the newest
     * release is undated — but a tag's commit is never younger than the release it names, so the
     * newest trusted date above the installed version bounds the answer from below.
     */
    public function testWhenTheNewestReleaseIsUndatedTheNewestTrustedDateAboveTheInstalledVersionCounts(): void
    {
        $metadata = $this->metadata(null, [
            '8' => ['v8.6.1', '2026-01-24T13:26:10+00:00'],
            '7' => ['v7.14.0', '2026-01-24T12:00:00+00:00'],
            '6' => ['v6.13.1', '2024-11-29T00:00:00+00:00'],
            '5' => ['v5.13.2', self::LOCKED_AT],
        ]);
        $behind = Libyears::behind($this->package(), $metadata);

        self::assertNotNull($behind);
        self::assertEqualsWithDelta(4.06, $behind, 0.005);
    }

    /**
     * The branch view is a map in whatever order the repository listed the branches; an undated
     * branch, a backport below, or a lower version on the installed branch listed *first* must be
     * stepped over, not stop the scan, and the newest date wins whatever position it sits in.
     */
    public function testTheScanStepsOverWhatDoesNotCountAndKeepsTheNewestDateWhereverItIsListed(): void
    {
        $listedOldestFirst = $this->metadata(null, [
            '8' => ['v8.6.1', null],
            '4' => ['v4.9.9', '2026-03-01T00:00:00+00:00'],
            '5' => ['v5.13.1', '2026-02-01T00:00:00+00:00'],
            '6' => ['v6.13.1', '2024-11-29T00:00:00+00:00'],
            '7' => ['v7.14.0', '2026-01-24T13:26:10+00:00'],
        ]);
        $behind = Libyears::behind($this->package(), $listedOldestFirst);

        self::assertNotNull($behind);
        self::assertEqualsWithDelta(4.06, $behind, 0.005, 'the 7.x date, not the 6.x one listed before it, and none of the three that do not count');
    }

    public function testABackportOnALowerBranchReleasedLaterIsNotAheadOfTheInstalledVersion(): void
    {
        $metadata = $this->metadata(null, [
            '5' => ['v5.13.2', self::LOCKED_AT],
            '4' => ['v4.9.9', '2026-03-01T00:00:00+00:00'],
        ]);

        self::assertNull(Libyears::behind($this->package(), $metadata));
    }

    public function testOnTheInstalledBranchOnlyAHigherVersionCounts(): void
    {
        $newerPatch = $this->metadata(null, ['5' => ['v5.14.0', '2023-06-01T00:00:00+00:00']]);
        $behind = Libyears::behind($this->package(), $newerPatch);
        self::assertNotNull($behind);
        self::assertEqualsWithDelta(1.41, $behind, 0.005);

        // the branch's newest dated release below the installed version — a re-tag dated later — is not movement forward
        $lowerDatedLater = $this->metadata(null, ['5' => ['v5.13.1', '2023-06-01T00:00:00+00:00']]);
        self::assertNull(Libyears::behind($this->package(), $lowerDatedLater));
    }

    public function testATrustedNewestReleaseDateOutranksTheBranchView(): void
    {
        // when the repository dates the newest release, that date is the one used, whatever the branches say
        $behind = Libyears::behind($this->package(), $this->metadata('2026-01-24T13:26:10+00:00', ['8' => ['v8.6.1', '2030-01-01T00:00:00+00:00']]));
        self::assertNotNull($behind);
        self::assertEqualsWithDelta(4.06, $behind, 0.005);
    }

    public function testALockEntryWithoutATimeIsNotMeasured(): void
    {
        self::assertNull(Libyears::behind($this->package('v5.13.2', null), $this->metadata('2026-01-24T13:26:10+00:00')));
    }

    // ---- a split package: the installed version is dated by its monorepo parent -------------

    private const LATEST = '2026-01-24T13:26:10+00:00';

    /**
     * A split package dated by laravel/framework: the newest release's date came from the parent,
     * and the parent's tag for the installed v5.13.2 is dated too — two years before the newest.
     *
     * @param array<string, string> $parentDates by normalized version
     */
    private function splitPackage(array $parentDates, ?string $lastStableDatedBy = 'laravel/framework'): PackageMetadata
    {
        $dates = [];
        foreach ($parentDates as $version => $at) {
            $dates[$version] = new \DateTimeImmutable($at);
        }

        return new PackageMetadata('illuminate/macroable', false, null, true, new \DateTimeImmutable(self::LATEST), 'v13.31.0', 120, null, 'library', new \DateTimeImmutable('2026-09-14T00:00:00+00:00'), [], [], $lastStableDatedBy, $dates, $dates === [] ? null : 'laravel/framework');
    }

    private static function twoYearsBefore(string $date): string
    {
        return (new \DateTimeImmutable($date))->modify('-'.(2 * Clock::SECONDS_PER_YEAR).' seconds')->format(\DATE_ATOM);
    }

    public function testASplitPackageIsMeasuredFromItsParentsDateForTheInstalledVersion(): void
    {
        // The lock says 2022-01-03 for v5.13.2 — the commit its tags share, not the release; the
        // parent's v5.13.2 says two years before the newest, and that is the installed end.
        $metadata = $this->splitPackage(['5.13.2.0' => self::twoYearsBefore(self::LATEST)]);

        self::assertSame(2.0, Libyears::behind($this->package(), $metadata));
        self::assertEquals(new \DateTimeImmutable(self::twoYearsBefore(self::LATEST)), Libyears::installedReleaseAt($this->package(), $metadata));
        self::assertSame('laravel/framework', Libyears::installedReleaseDatedBy($this->package(), $metadata));
    }

    public function testASplitPackageWhoseParentDoesNotDateTheInstalledVersionIsNotMeasured(): void
    {
        // The newest release's date came from the parent, so the lock's date for the installed
        // version is the shared commit's, a year and a half early, and would inflate the sum.
        $neighbour = $this->splitPackage(['5.13.3.0' => self::twoYearsBefore(self::LATEST)]);

        self::assertNull(Libyears::behind($this->package(), $this->splitPackage([])));
        self::assertNull(Libyears::behind($this->package(), $neighbour), 'a neighbouring version is not this one');
        self::assertNull(Libyears::installedReleaseAt($this->package(), $this->splitPackage([])));
        self::assertSame('laravel/framework', $neighbour->releaseDatesBy());
        self::assertNull(Libyears::installedReleaseDatedBy($this->package(), $neighbour), 'the parent has dates, none of them for this version');
    }

    public function testTheParentsDateOutranksTheLocksEvenWhenThePackageDatedItsNewestReleaseItself(): void
    {
        // illuminate/contracts: the newest tag sits on a commit of its own and is dated by the
        // package itself, but the installed v8.83.27 sits on one 31 tags share — the lock's date
        // is eleven months early. The parent dates the installed version whenever it can.
        $metadata = $this->splitPackage(['5.13.2.0' => self::twoYearsBefore(self::LATEST)], null);

        self::assertNull($metadata->lastStableDatedBy());
        self::assertSame(2.0, Libyears::behind($this->package(), $metadata));
    }

    public function testWithoutAParentAPackageIsMeasuredFromTheLocksDate(): void
    {
        $metadata = $this->splitPackage([], null);

        self::assertEquals(new \DateTimeImmutable(self::LOCKED_AT), Libyears::installedReleaseAt($this->package(), $metadata));
        self::assertEqualsWithDelta(4.06, Libyears::behind($this->package(), $metadata) ?? 0.0, 0.005);
    }

    public function testAVersionComposerCannotNormalizeIsMeasuredFromTheLocksDate(): void
    {
        $metadata = $this->splitPackage(['5.13.2.0' => self::twoYearsBefore(self::LATEST)], null);

        self::assertEquals(new \DateTimeImmutable(self::LOCKED_AT), Libyears::installedReleaseAt($this->package('not a version'), $metadata));
    }

    public function testASnapshotHasNoReleaseDateToMeasureFrom(): void
    {
        $metadata = $this->splitPackage(['5.13.2.0' => self::twoYearsBefore(self::LATEST)], null);

        self::assertNull(Libyears::installedReleaseAt($this->package('dev-main'), $metadata));
        self::assertNull(Libyears::installedReleaseDatedBy($this->package('dev-main'), $metadata), 'the parent dates releases, and a branch is not one');
    }

    public function testADevelopmentPackageIsMeasuredLikeAnyOther(): void
    {
        $behind = Libyears::behind($this->package('v5.13.2', self::LOCKED_AT, true, true), $this->metadata('2026-01-24T13:26:10+00:00'));

        self::assertNotNull($behind);
        self::assertEqualsWithDelta(4.06, $behind, 0.005);
    }

    // ---- the block the report derives -------------------------------------------------------

    public function testTheTotalsSumTheUnroundedValuesAndRoundOnce(): void
    {
        $block = Libyears::fromFindings([self::finding('a/a', 1.005), self::finding('b/b', 1.005)]);

        self::assertSame(2.01, $block->total());
        self::assertSame([2.01, 2.01], [$block->toArray()['total'], $block->toArray()['direct_requirements']]);
    }

    public function testDirectCountsOnlyTheDirectRequirements(): void
    {
        $block = Libyears::fromFindings([self::finding('a/a', 4.0), self::finding('b/b', 2.5, false)]);

        self::assertSame(6.5, $block->total());
        self::assertSame(4.0, $block->direct());
        self::assertSame(2, $block->measured());
    }

    public function testEveryReasonANullIsFiledUnder(): void
    {
        $block = Libyears::fromFindings([
            self::finding('measured/one', 1.0),
            self::finding('path/local', null, true, 'dev-main', Analyzer::NOTE_NOT_IN_REPOSITORY),
            self::finding('gone/missing', null, true, '1.0.0', 'not found in the repository'),
            self::finding('gone/failed', null, true, '1.0.0', 'Repository metadata unavailable: timeout'),
            self::finding('pinned/main', null, true, 'dev-main'),
            self::finding('pinned/branch', null, true, '2.x-dev'),
            self::finding('undated/split', null, true, 'v1.37.0'),
        ]);

        self::assertSame([
            Libyears::BRANCH_SNAPSHOT => 2,
            Libyears::NO_STABLE_RELEASE_DATE => 1,
            Libyears::NOT_FROM_COMPOSER_REPOSITORY => 1,
            Libyears::METADATA_UNAVAILABLE => 2,
        ], $block->unmeasured());
        self::assertSame(1, $block->measured());
    }

    public function testTheWorstIsTheMaximumWithTiesGoingToTheFirstName(): void
    {
        $block = Libyears::fromFindings([
            self::finding('zeta/pkg', 3.0),
            self::finding('alpha/pkg', 3.0),
            self::finding('mid/pkg', 2.0),
        ]);
        $worst = $block->worst();

        self::assertNotNull($worst);
        self::assertSame('alpha/pkg', $worst->package());
        // and the same answer when the first-named one comes first: a tie never goes to whoever came later
        $reversed = Libyears::fromFindings([self::finding('alpha/pkg', 3.0), self::finding('zeta/pkg', 3.0)])->worst();
        self::assertNotNull($reversed);
        self::assertSame('alpha/pkg', $reversed->package());
    }

    public function testALockWithNothingBehindNamesNoWorstPackage(): void
    {
        $block = Libyears::fromFindings([self::finding('a/a', 0.0), self::finding('b/b', 0.0, false)]);

        self::assertNull($block->worst());
        self::assertSame(2, $block->measured());
        self::assertNull($block->toArray()['furthest_behind']);
        self::assertSame('libyears: 0.0 behind across all 2 packages', $block->line());
        // and a package at zero never outranks one that is behind, whatever the order
        $worst = Libyears::fromFindings([self::finding('a/a', 0.0), self::finding('b/b', 0.4)])->worst();
        self::assertNotNull($worst);
        self::assertSame('b/b', $worst->package());
    }

    public function testAnEmptyRunMeasuresNothing(): void
    {
        $block = Libyears::fromFindings([]);

        self::assertSame(0.0, $block->total());
        self::assertSame(0, $block->measured());
        self::assertNull($block->worst());
        self::assertSame('libyears: nothing to measure', $block->line());
        self::assertNull($block->toArray()['furthest_behind']);
    }

    public function testTheLineNamesTheTotalItsScopeTheDirectShareAndThePackageFurthestBehind(): void
    {
        $block = Libyears::fromFindings([
            self::finding('smalot/pdfparser', 4.7123, true, 'v1.1.0'),
            self::finding('psr/log', 3.36, false, '1.1.4'),
            self::finding('wallabag/rulerz', null, true, 'dev-master'),
        ]);

        self::assertSame('libyears: 8.1 behind across 2 of 3 packages · 4.7 from direct requirements · furthest behind smalot/pdfparser v1.1.0 at 4.7', $block->line());
    }

    public function testTheLineSaysAllWhenEveryPackageWasMeasured(): void
    {
        $block = Libyears::fromFindings([self::finding('a/a', 1.0)]);

        self::assertSame('libyears: 1.0 behind across the one package · 1.0 from direct requirements · furthest behind a/a 1.0.0 at 1.0', $block->line());
        self::assertSame('libyears: 2.0 behind across all 2 packages · 2.0 from direct requirements · furthest behind a/a 1.0.0 at 1.0', Libyears::fromFindings([self::finding('a/a', 1.0), self::finding('b/b', 1.0)])->line());
    }

    public function testTheLineSaysSoWhenNothingCouldBeMeasured(): void
    {
        $block = Libyears::fromFindings([self::finding('a/a', null, true, 'dev-main'), self::finding('b/b', null, true, 'dev-main')]);

        self::assertSame('libyears: none of the 2 packages could be measured', $block->line());
        self::assertSame('libyears: the one package could not be measured', Libyears::fromFindings([self::finding('a/a', null, true, 'dev-main')])->line());
    }

    public function testTheArrayIsTheBlockTheSchemaDescribes(): void
    {
        $block = Libyears::fromFindings([
            self::finding('smalot/pdfparser', 4.7123, true, 'v1.1.0'),
            self::finding('psr/log', 3.36, false, '1.1.4'),
            self::finding('wallabag/rulerz', null, true, 'dev-master'),
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
