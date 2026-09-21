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

    private function metadata(?string $lastStableReleaseAt): PackageMetadata
    {
        return new PackageMetadata('scheb/2fa-bundle', false, null, true, $lastStableReleaseAt === null ? null : new \DateTimeImmutable($lastStableReleaseAt), 'v8.6.1', 12, null, 'library', new \DateTimeImmutable('2026-09-14T00:00:00+00:00'));
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

    public function testAnUndatedLastStableReleaseIsNotMeasured(): void
    {
        self::assertNull(Libyears::behind($this->package(), $this->metadata(null)));
    }

    public function testALockEntryWithoutATimeIsNotMeasured(): void
    {
        self::assertNull(Libyears::behind($this->package('v5.13.2', null), $this->metadata('2026-01-24T13:26:10+00:00')));
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
        self::assertSame([2.01, 2.01], [$block->toArray()['total'], $block->toArray()['direct']]);
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
            Libyears::BRANCH_SNAPSHOTS => 2,
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

    public function testAnEmptyRunMeasuresNothing(): void
    {
        $block = Libyears::fromFindings([]);

        self::assertSame(0.0, $block->total());
        self::assertSame(0, $block->measured());
        self::assertNull($block->worst());
        self::assertSame('libyears: nothing measured', $block->line());
        self::assertNull($block->toArray()['worst']);
    }

    public function testTheLineNamesTheTotalTheDirectShareTheWorstAndWhatWasNotMeasured(): void
    {
        $block = Libyears::fromFindings([
            self::finding('smalot/pdfparser', 4.7123, true, 'v1.1.0'),
            self::finding('psr/log', 3.36, false, '1.1.4'),
            self::finding('wallabag/rulerz', null, true, 'dev-master'),
        ]);

        self::assertSame('libyears: 8.1 across 2 measured packages · direct 4.7 · worst smalot/pdfparser v1.1.0 (4.7) · 1 not measured', $block->line());
    }

    public function testTheLineDropsTheUnmeasuredItemWhenEverythingWasMeasured(): void
    {
        $block = Libyears::fromFindings([self::finding('a/a', 1.0)]);

        self::assertSame('libyears: 1.0 across 1 measured package · direct 1.0 · worst a/a 1.0.0 (1.0)', $block->line());
    }

    public function testTheLineSaysSoWhenNothingCouldBeMeasured(): void
    {
        $block = Libyears::fromFindings([self::finding('a/a', null, true, 'dev-main'), self::finding('b/b', null, true, 'dev-main')]);

        self::assertSame('libyears: nothing measured (2 not measured)', $block->line());
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
            'direct' => 4.71,
            'measured' => 2,
            'unmeasured' => [
                Libyears::BRANCH_SNAPSHOTS => 1,
                Libyears::NO_STABLE_RELEASE_DATE => 0,
                Libyears::NOT_FROM_COMPOSER_REPOSITORY => 0,
                Libyears::METADATA_UNAVAILABLE => 0,
            ],
            'worst' => ['package' => 'smalot/pdfparser', 'version' => 'v1.1.0', 'libyears' => 4.71],
        ], $block->toArray());
    }
}
