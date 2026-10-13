<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Data\Forge\ActivityLookup;
use Lockrot\Data\Forge\RepoRef;
use Lockrot\Data\Forge\RepositoryActivity;
use Lockrot\Output\AgeText;
use Lockrot\Signal\AgeMeasure;
use Lockrot\Signal\AgeReading;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Verdict\Age;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\FindingDetails;
use PHPUnit\Framework\TestCase;

/** The `age:` line, AgeText: measured readings only, each release named once. */
final class AgeTextTest extends TestCase
{
    public function testTheYearsPhraseSaysLessThanThreeWeeksForAPublishedZero(): void
    {
        self::assertSame('less than three weeks ago', AgeText::ago(0.0));
        self::assertSame('0.1 years ago', AgeText::ago(0.1));
        self::assertSame('10.0 years ago', AgeText::ago(10.0));
    }

    public function testTheBranchIsTheReleaseBranchOrTheAliasLineOrItsBranch(): void
    {
        self::assertSame('2.x', AgeText::branchOf('v2.16.1'));
        self::assertSame('0.3.x', AgeText::branchOf('0.3.1'));
        self::assertSame('5.x', AgeText::branchOf('5.4.x-dev'));
        self::assertSame('its branch', AgeText::branchOf('dev-main'));
    }

    public function testTheInstalledReleaseThatIsTheNewestOnItsBranchSaysSo(): void
    {
        $finding = self::finding('v2.16.1', self::installed('2024-09-09', 2.1), self::release('v3.30.0', '2026-09-25', 0.0), self::release('v2.16.1', '2024-09-09', 2.1), self::push('2026-09-30', 0.0), RepoRef::GITHUB);

        self::assertSame('age: installed v2.16.1 released 2024-09-09 (2.1 years ago), the newest on 2.x; newest release v3.30.0 2026-09-25 (less than three weeks ago); last push 2026-09-30 (less than three weeks ago)', AgeText::line($finding));
    }

    public function testTheBranchReleaseAndTheNewestReleaseEachGetTheirClause(): void
    {
        $finding = self::finding('v5.4.23', self::installed('2023-04-23', 3.4), self::release('v8.1.8', '2026-09-26', 0.0), self::release('v5.4.53', '2026-05-20', 0.4), self::push('2026-10-01', 0.0), RepoRef::GITLAB);

        self::assertSame('age: installed v5.4.23 released 2023-04-23 (3.4 years ago); 5.x last released v5.4.53 2026-05-20 (0.4 years ago); newest release v8.1.8 2026-09-26 (less than three weeks ago); last commit 2026-10-01 (less than three weeks ago)', AgeText::line($finding));
    }

    public function testABranchWhoseNewestReleaseIsThePackagesNewestIsNamedOnce(): void
    {
        $finding = self::finding('v1.37.0', AgeReading::unmeasuredBecause(AgeMeasure::SHARED_COMMIT), self::release('v1.43.0', '2026-09-23', 0.0), self::release('v1.43.0', '2026-09-23', 0.0), AgeReading::unmeasuredBecause('allowlisted'), RepoRef::GITHUB);

        self::assertSame('age: installed v1.37.0, release date not known (dated only by a commit its tags share); 1.x last released v1.43.0 2026-09-23 (less than three weeks ago), its newest release', AgeText::line($finding));
    }

    public function testTheInstalledReleaseThatIsTheNewestReleaseSaysSoAndIsNamedOnce(): void
    {
        $finding = self::finding('v4.1.1', self::installed('2018-01-15', 8.7, 'tubalmartin/all'), self::release('v4.1.1', '2018-01-15', 8.7), self::release('v4.1.1', '2018-01-15', 8.7), AgeReading::unmeasuredBecause('no_token'), RepoRef::GITHUB);

        self::assertSame('age: installed v4.1.1 released 2018-01-15 (8.7 years ago, dated by tubalmartin/all), its newest release', AgeText::line($finding));
    }

    public function testASnapshotLeavesOutItsInstalledReadingAndNothingMeasuredIsNoLine(): void
    {
        $nothing = AgeReading::unmeasuredBecause(AgeMeasure::UNDATED_RELEASES);
        $finding = self::finding('dev-main', AgeReading::unmeasuredBecause(AgeMeasure::BRANCH_SNAPSHOT), $nothing, $nothing, AgeReading::unmeasuredBecause('no_token'), RepoRef::GITHUB);

        self::assertNull(AgeText::line($finding));
        self::assertNull(AgeText::line(self::finding('dev-main', AgeReading::unmeasuredBecause('details_missing'), $nothing, $nothing, $nothing, RepoRef::GITHUB)));
        self::assertSame('age: installed 1.0.0, release date not known (no date)', AgeText::line(self::finding('1.0.0', AgeReading::unmeasuredBecause(AgeMeasure::UNDATED), $nothing, $nothing, $nothing, RepoRef::GITHUB)));
    }

    public function testASnapshotOnAnAliasLineNamesTheLine(): void
    {
        $finding = self::finding('5.4.x-dev', AgeReading::unmeasuredBecause(AgeMeasure::BRANCH_SNAPSHOT), self::release('v8.1.5', '2026-08-21', 0.1), self::release('v5.4.45', '2024-09-25', 2.0), self::push('2026-09-25', 0.0), RepoRef::GITHUB);

        self::assertSame('age: 5.x last released v5.4.45 2024-09-25 (2.0 years ago); newest release v8.1.5 2026-08-21 (0.1 years ago); last push 2026-09-25 (less than three weeks ago)', AgeText::line($finding));
    }

    private static function installed(string $date, float $years, ?string $datedBy = null): AgeReading
    {
        return AgeReading::measured(new \DateTimeImmutable($date.'T10:00:00+00:00'), (int) round($years * 10), $years, null, $datedBy, null, AgeReading::INSTALLED);
    }

    private static function release(string $version, string $date, float $years): AgeReading
    {
        return AgeReading::measured(new \DateTimeImmutable($date.'T10:00:00+00:00'), (int) round($years * 10), $years, $version, null, null, AgeReading::RELEASE);
    }

    private static function push(string $date, float $years): AgeReading
    {
        return AgeReading::measured(new \DateTimeImmutable($date.'T10:00:00+00:00'), (int) round($years * 10), $years, null, null, null, AgeReading::PUSH);
    }

    private static function finding(string $version, AgeReading $installed, AgeReading $release, AgeReading $branchRelease, AgeReading $push, string $forge): Finding
    {
        $finding = (new FindingBuilder())->withPackage('a/pkg')->withVersion($version)->build();
        $activity = ActivityLookup::read(new RepositoryActivity(new RepoRef($forge, 'example.org', 'a/pkg'), false, null, new \DateTimeImmutable('2026-10-01T00:00:00+00:00')));
        $details = $finding->details();

        return $finding->withDetails(new FindingDetails($details->metadataStatus(), null, null, $details->skipped(), $details->installedPhp(), null, 'complete', null, [], null, $activity, new Age($installed, $release, $branchRelease, $push), null, null));
    }
}
