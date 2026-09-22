<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Repository;

use Lockrot\Data\Repository\InstalledRelease;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use PHPUnit\Framework\TestCase;

/**
 * One reading of the installed version's date, for every surface that prints or measures it. The
 * lock's `time` is a release date only sometimes, and the three answers it can hide — a branch's
 * commit, a commit a split package's tags share, a release the monorepo parent dates — used to be
 * re-derived by each caller, which is how one explanation came to call a date a release four lines
 * above saying it was not one.
 */
final class InstalledReleaseTest extends TestCase
{
    private const NOW = '2026-09-14T00:00:00+00:00';
    private const LOCKED_AT = '2023-06-05T12:46:42+00:00';

    /** @param array<string, string> $parentDates by normalized version */
    private static function metadata(?string $lastStableDatedBy, array $parentDates = []): PackageMetadata
    {
        $dates = [];
        foreach ($parentDates as $version => $at) {
            $dates[$version] = new \DateTimeImmutable($at);
        }

        return new PackageMetadata(
            'illuminate/macroable',
            false,
            null,
            true,
            new \DateTimeImmutable('2026-01-24T13:26:10+00:00'),
            'v13.31.0',
            120,
            null,
            'library',
            new \DateTimeImmutable(self::NOW),
            [],
            [],
            $lastStableDatedBy,
            $dates,
            $dates === [] ? null : 'laravel/framework'
        );
    }

    public function testTheLocksOwnDateIsTheReleasesWhereNothingSaysOtherwise(): void
    {
        $installed = InstalledRelease::of(F::package(['version' => 'v5.13.2', 'time' => self::LOCKED_AT]), self::metadata(null));

        self::assertSame(InstalledRelease::RELEASE, $installed->kind());
        self::assertEquals(new \DateTimeImmutable(self::LOCKED_AT), $installed->at());
        self::assertEquals(new \DateTimeImmutable(self::LOCKED_AT), $installed->lockTime());
        self::assertNull($installed->datedBy());
        self::assertTrue($installed->isDated());
    }

    public function testWithoutMetadataTheLockIsReadAsItReadsItself(): void
    {
        $installed = InstalledRelease::of(F::package(['version' => 'v5.13.2', 'time' => self::LOCKED_AT]), null);

        self::assertSame(InstalledRelease::RELEASE, $installed->kind());
        self::assertEquals(new \DateTimeImmutable(self::LOCKED_AT), $installed->at());
    }

    public function testAMonorepoParentsTagDatesTheInstalledVersion(): void
    {
        $installed = InstalledRelease::of(
            F::package(['version' => 'v5.13.2', 'time' => self::LOCKED_AT]),
            self::metadata('laravel/framework', ['5.13.2.0' => '2024-11-21T00:00:00+00:00'])
        );

        self::assertSame(InstalledRelease::DATED_BY_PARENT, $installed->kind());
        self::assertEquals(new \DateTimeImmutable('2024-11-21T00:00:00+00:00'), $installed->at(), 'the parent\'s date, not the lock\'s');
        self::assertEquals(new \DateTimeImmutable(self::LOCKED_AT), $installed->lockTime());
        self::assertSame('laravel/framework', $installed->datedBy());
        self::assertTrue($installed->isDated());
    }

    public function testASharedCommitLeavesTheInstalledVersionUndated(): void
    {
        // the package's own newest release had to be dated by the parent, and the parent does not
        // list this version: the lock's date is the commit its tags share, and measuring from it
        // would add that artefact to every number read off it
        $installed = InstalledRelease::of(
            F::package(['version' => 'v5.13.2', 'time' => self::LOCKED_AT]),
            self::metadata('laravel/framework', ['5.13.3.0' => '2024-11-21T00:00:00+00:00'])
        );

        self::assertSame(InstalledRelease::SHARED_COMMIT, $installed->kind());
        self::assertNull($installed->at());
        self::assertEquals(new \DateTimeImmutable(self::LOCKED_AT), $installed->lockTime(), 'the lock still says what it says');
        self::assertNull($installed->datedBy());
        self::assertFalse($installed->isDated());
    }

    public function testABranchSnapshotIsDatedByItsCommit(): void
    {
        $installed = InstalledRelease::of(F::package(['version' => 'dev-main', 'time' => self::LOCKED_AT]), self::metadata(null));

        self::assertSame(InstalledRelease::BRANCH_SNAPSHOT, $installed->kind());
        self::assertNull($installed->at(), 'a commit is not a release');
        self::assertEquals(new \DateTimeImmutable(self::LOCKED_AT), $installed->lockTime());
        self::assertFalse($installed->isDated());
    }

    public function testALockEntryWithoutATimeCarriesNoDateAtAll(): void
    {
        foreach (['v5.13.2', 'dev-main'] as $version) {
            $installed = InstalledRelease::of(F::package(['version' => $version, 'time' => null]), self::metadata(null));

            self::assertSame(InstalledRelease::UNDATED, $installed->kind(), $version);
            self::assertNull($installed->at());
            self::assertNull($installed->lockTime());
            self::assertFalse($installed->isDated());
        }
    }

    public function testTheParentDatesTheVersionEvenWhereTheLockCarriesNoDate(): void
    {
        $installed = InstalledRelease::of(
            F::package(['version' => 'v5.13.2', 'time' => null]),
            self::metadata('laravel/framework', ['5.13.2.0' => '2024-11-21T00:00:00+00:00'])
        );

        self::assertSame(InstalledRelease::DATED_BY_PARENT, $installed->kind(), 'the lock having no date says nothing about the parent\'s tag');
        self::assertEquals(new \DateTimeImmutable('2024-11-21T00:00:00+00:00'), $installed->at());
        self::assertNull($installed->lockTime());
        self::assertSame('laravel/framework', $installed->datedBy());
    }

    public function testAVersionComposerCannotParseFallsBackToTheLocksDate(): void
    {
        $installed = InstalledRelease::of(
            F::package(['version' => 'not a version', 'time' => self::LOCKED_AT]),
            self::metadata(null, ['5.13.2.0' => '2024-11-21T00:00:00+00:00'])
        );

        self::assertSame(InstalledRelease::RELEASE, $installed->kind());
        self::assertEquals(new \DateTimeImmutable(self::LOCKED_AT), $installed->at());
    }
}
