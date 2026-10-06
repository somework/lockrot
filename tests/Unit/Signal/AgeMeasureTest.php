<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal;

use Lockrot\Clock;
use Lockrot\Data\Forge\RepoRef;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Signal\AgeMeasure;
use Lockrot\Signal\AgeReading;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\Rule\NotCheckedRule;
use Lockrot\Signal\Signal;
use Lockrot\Signal\Thresholds;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AgeMeasureTest extends TestCase
{
    private const NOW = '2026-10-01T00:00:00+00:00';

    private function measure(?Thresholds $thresholds = null): AgeMeasure
    {
        return new AgeMeasure(Clock::fixed(self::NOW), $thresholds ?? new Thresholds());
    }

    /** An instant the given number of seconds before the run clock, as an ISO 8601 string. */
    private static function ago(int $seconds): string
    {
        return (new \DateTimeImmutable(self::NOW))->modify(\sprintf('%+d seconds', -$seconds))->format(\DATE_ATOM);
    }

    private static function assertUnmeasured(string $reason, AgeReading $reading): void
    {
        self::assertFalse($reading->isMeasured());
        self::assertSame($reason, $reading->unmeasured());
        self::assertNull($reading->at());
        self::assertNull($reading->years());
        self::assertNull($reading->ratio());
        self::assertNull($reading->version());
        self::assertNull($reading->datedBy());
        self::assertNull($reading->level());
    }

    public function testSwiftmailerAtFourPointNineFiveYearsPrintsFiveAndStaysWarn(): void
    {
        $facts = FactsBuilder::facts(FactsBuilder::package(['version' => 'v6.3.0']), FactsBuilder::metadata([['v6.3.0', '2021-10-18T12:06:47+00:00']]));

        $reading = $this->measure()->release($facts);

        self::assertTrue($reading->isMeasured());
        self::assertNull($reading->unmeasured());
        self::assertSame(5.0, $reading->years());
        self::assertSame(50, $reading->tenths());
        self::assertSame(Signal::LEVEL_WARN, $reading->level());
        self::assertLessThan(5.0, $reading->ratio());
        self::assertGreaterThan(4.95, $reading->ratio());
        self::assertSame('v6.3.0', $reading->version());
        self::assertSame('2021-10-18T12:06:47+00:00', $reading->at() !== null ? $reading->at()->format(\DATE_ATOM) : null);
        self::assertNull($reading->datedBy());
        self::assertSame('5', json_encode($reading->years()));
    }

    /** @return iterable<string, array{int, float, ?string}> */
    public static function releaseRows(): iterable
    {
        $year = Clock::SECONDS_PER_YEAR;
        yield 'fresh' => [86400, 0.0, null];
        yield 'one second below warn' => [3 * $year - 1, 3.0, null];
        yield 'warn exactly' => [3 * $year, 3.0, Signal::LEVEL_WARN];
        yield 'one second below high' => [5 * $year - 1, 5.0, Signal::LEVEL_WARN];
        yield 'high exactly' => [5 * $year, 5.0, Signal::LEVEL_HIGH];
        yield 'seven years' => [7 * $year, 7.0, Signal::LEVEL_HIGH];
        yield 'a future tag reads 0' => [-86400, 0.0, null];
    }

    /** @dataProvider releaseRows */
    #[DataProvider('releaseRows')]
    public function testTheReleaseReadingTakesItsLevelFromTheExactRatio(int $secondsAgo, float $years, ?string $level): void
    {
        $facts = FactsBuilder::facts(FactsBuilder::package(), FactsBuilder::metadata([['1.0.0', self::ago($secondsAgo)]]));

        $reading = $this->measure()->release($facts);

        self::assertSame($years, $reading->years());
        self::assertSame($level, $reading->level());
        self::assertSame((float) $secondsAgo / Clock::SECONDS_PER_YEAR, $reading->ratio());
    }

    public function testTheReleaseReadingUsesTheConfiguredReleaseThresholds(): void
    {
        $facts = FactsBuilder::facts(FactsBuilder::package(), FactsBuilder::metadata([['1.0.0', self::ago(2 * Clock::SECONDS_PER_YEAR)]]));

        self::assertSame(Signal::LEVEL_WARN, $this->measure(new Thresholds(2, 4, 9, 10))->release($facts)->level());
        self::assertSame(Signal::LEVEL_HIGH, $this->measure(new Thresholds(1, 2, 9, 10))->release($facts)->level());
        self::assertNull($this->measure(new Thresholds(3, 4, 1, 2))->release($facts)->level());
    }

    public function testTheReleaseReadingNamesTheMonorepoParentThatDatesIt(): void
    {
        $metadata = $this->splitMetadata();

        $reading = $this->measure()->release(FactsBuilder::facts(FactsBuilder::package(['version' => 'v1.0.0']), $metadata));

        self::assertSame('vendor/parent', $reading->datedBy());
        self::assertSame('v1.1.0', $reading->version());
    }

    public function testTheReleaseReasons(): void
    {
        $measure = $this->measure();

        self::assertUnmeasured(AgeMeasure::NOT_FROM_COMPOSER_REPOSITORY, $measure->release(FactsBuilder::facts(FactsBuilder::package(['fromComposerRepository' => false]))));
        self::assertUnmeasured(AgeMeasure::UNAVAILABLE, $measure->release(FactsBuilder::facts(FactsBuilder::package())));
        self::assertUnmeasured(AgeMeasure::NOT_FOUND, $measure->release(FactsBuilder::facts(FactsBuilder::package()), AgeMeasure::NOT_FOUND));
        self::assertUnmeasured(AgeMeasure::NO_STABLE_RELEASE, $measure->release(FactsBuilder::facts(FactsBuilder::package(), FactsBuilder::metadata([['dev-main', '2026-09-01T00:00:00+00:00']]))));
        self::assertUnmeasured(AgeMeasure::UNDATED_RELEASES, $measure->release(FactsBuilder::facts(FactsBuilder::package(), FactsBuilder::metadata([['1.0.0', null]]))));
    }

    public function testTheMetadataReasonComesBeforeTheReadingsOwn(): void
    {
        // A vcs snapshot: no metadata, and a branch. The metadata's reason wins (§8.4.3 rule 5).
        $facts = FactsBuilder::facts(FactsBuilder::package(['version' => 'dev-main', 'fromComposerRepository' => false]));

        self::assertUnmeasured(AgeMeasure::NOT_FROM_COMPOSER_REPOSITORY, $this->measure()->branchRelease($facts));
        self::assertUnmeasured(AgeMeasure::NOT_FOUND, $this->measure()->branchRelease(FactsBuilder::facts(FactsBuilder::package(['version' => 'dev-main'])), AgeMeasure::NOT_FOUND));
        self::assertUnmeasured(AgeMeasure::UNAVAILABLE, $this->measure()->branchRelease(FactsBuilder::facts(FactsBuilder::package(['version' => 'dev-main']))));
    }

    public function testTheBranchReleaseReadingIsTheNewestDatedReleaseOnTheInstalledBranch(): void
    {
        $facts = FactsBuilder::facts(
            FactsBuilder::package(['version' => '1.2.0']),
            FactsBuilder::metadata([
                ['2.0.0', self::ago(86400)],
                ['1.3.0', self::ago(4 * Clock::SECONDS_PER_YEAR)],
                ['1.2.0', self::ago(5 * Clock::SECONDS_PER_YEAR)],
            ])
        );

        $reading = $this->measure()->branchRelease($facts);

        self::assertSame('1.3.0', $reading->version());
        self::assertSame(4.0, $reading->years());
        self::assertSame(4.0, $reading->ratio());
        self::assertSame(Signal::LEVEL_WARN, $reading->level());
        self::assertNull($reading->datedBy());
        self::assertSame(self::ago(4 * Clock::SECONDS_PER_YEAR), $reading->at() !== null ? $reading->at()->format(\DATE_ATOM) : null);
    }

    public function testTheBranchReleaseReadingUsesTheReleaseThresholds(): void
    {
        $facts = FactsBuilder::facts(FactsBuilder::package(['version' => '1.2.0']), FactsBuilder::metadata([['1.2.0', self::ago(2 * Clock::SECONDS_PER_YEAR)]]));

        self::assertSame(Signal::LEVEL_HIGH, $this->measure(new Thresholds(1, 2, 9, 10))->branchRelease($facts)->level());
        self::assertNull($this->measure(new Thresholds(3, 4, 1, 2))->branchRelease($facts)->level());
    }

    public function testTheBranchReleaseReadingNamesTheMonorepoParentThatDatesIt(): void
    {
        $reading = $this->measure()->branchRelease(FactsBuilder::facts(FactsBuilder::package(['version' => 'v1.0.0']), $this->splitMetadata()));

        self::assertSame('vendor/parent', $reading->datedBy());
        self::assertSame('v1.1.0', $reading->version());
        self::assertSame(1.0, $reading->years());
    }

    public function testTheBranchReleaseReasons(): void
    {
        $measure = $this->measure();
        $releases = [['2.0.0', self::ago(86400)], ['1.2.0', self::ago(4 * Clock::SECONDS_PER_YEAR)]];

        self::assertUnmeasured(AgeMeasure::BRANCH_SNAPSHOT, $measure->branchRelease(FactsBuilder::facts(FactsBuilder::package(['version' => 'dev-main']), FactsBuilder::metadata($releases))));
        self::assertUnmeasured(AgeMeasure::BRANCH_SNAPSHOT, $measure->branchRelease(FactsBuilder::facts(FactsBuilder::package(['version' => '1.x-dev']), FactsBuilder::metadata($releases))));
        self::assertUnmeasured(AgeMeasure::NO_BRANCH_ROW, $measure->branchRelease(FactsBuilder::facts(FactsBuilder::package(['version' => '3.0.0']), FactsBuilder::metadata($releases))));
        self::assertUnmeasured(AgeMeasure::NO_BRANCH_ROW, $measure->branchRelease(FactsBuilder::facts(FactsBuilder::package(['version' => 'not a version']), FactsBuilder::metadata($releases))));
    }

    public function testSEightsTwoRefusalsAreReasons(): void
    {
        $measure = $this->measure();
        // The branch's highest tag is undated: how much younger than the newest dated release it is cannot be known.
        $undatedHighest = FactsBuilder::metadata([['2.0.0', self::ago(86400)], ['1.3.0', null], ['1.2.0', self::ago(4 * Clock::SECONDS_PER_YEAR)]]);
        // No tag on the branch is dated at all.
        $undatedBranch = FactsBuilder::metadata([['2.0.0', self::ago(86400)], ['1.2.0', null]]);
        // The installed version is above every tag the repository lists on its branch.
        $listed = FactsBuilder::metadata([['2.0.0', self::ago(86400)], ['1.2.0', self::ago(4 * Clock::SECONDS_PER_YEAR)]]);

        self::assertUnmeasured(AgeMeasure::UNDATED_RELEASES, $measure->branchRelease(FactsBuilder::facts(FactsBuilder::package(['version' => '1.2.0']), $undatedHighest)));
        self::assertUnmeasured(AgeMeasure::UNDATED_RELEASES, $measure->branchRelease(FactsBuilder::facts(FactsBuilder::package(['version' => '1.2.0']), $undatedBranch)));
        self::assertUnmeasured(AgeMeasure::ABOVE_LISTED_RELEASES, $measure->branchRelease(FactsBuilder::facts(FactsBuilder::package(['version' => '1.2.1']), $listed)));
        // The highest listed tag itself is not above anything.
        self::assertTrue($measure->branchRelease(FactsBuilder::facts(FactsBuilder::package(['version' => '1.2.0']), $listed))->isMeasured());
        // Both refusals at once: the undated reason is the one given.
        self::assertUnmeasured(AgeMeasure::UNDATED_RELEASES, $measure->branchRelease(FactsBuilder::facts(FactsBuilder::package(['version' => '1.3.1']), $undatedHighest)));
    }

    public function testThePushReading(): void
    {
        $facts = FactsBuilder::facts(FactsBuilder::package(), null, FactsBuilder::activity(false, self::ago(5 * Clock::SECONDS_PER_YEAR - 1)));

        $reading = $this->measure()->push($facts);

        self::assertSame(5.0, $reading->years());
        self::assertSame(Signal::LEVEL_WARN, $reading->level());
        self::assertSame(self::ago(5 * Clock::SECONDS_PER_YEAR - 1), $reading->at() !== null ? $reading->at()->format(\DATE_ATOM) : null);
        self::assertNull($reading->version());
        self::assertNull($reading->datedBy());
        self::assertNull($reading->unmeasured());
    }

    public function testThePushReadingUsesThePushThresholds(): void
    {
        $facts = FactsBuilder::facts(FactsBuilder::package(), null, FactsBuilder::activity(false, self::ago(2 * Clock::SECONDS_PER_YEAR), RepoRef::GITLAB));

        self::assertSame(Signal::LEVEL_WARN, $this->measure(new Thresholds(9, 10, 2, 4))->push($facts)->level());
        self::assertSame(Signal::LEVEL_HIGH, $this->measure(new Thresholds(9, 10, 1, 2))->push($facts)->level());
        self::assertNull($this->measure(new Thresholds(1, 2, 3, 4))->push($facts)->level());
    }

    public function testThePushReasons(): void
    {
        $measure = $this->measure();
        $package = FactsBuilder::package();

        self::assertUnmeasured(AgeMeasure::UNDATED, $measure->push(FactsBuilder::facts($package, null, FactsBuilder::activity(true, null))));
        self::assertUnmeasured(NotCheckedRule::RATE_LIMIT, $measure->push(new PackageFacts($package, null, null, [], NotCheckedRule::RATE_LIMIT)));
        self::assertUnmeasured(AgeMeasure::NOT_FROM_COMPOSER_REPOSITORY, $measure->push(FactsBuilder::facts(FactsBuilder::package(['fromComposerRepository' => false]))));
        self::assertUnmeasured(AgeMeasure::NO_REPOSITORY, $measure->push(FactsBuilder::facts($package)));
        // The caller's own reason (§5.8: allowlisted, a 404, a host lockrot cannot ask) comes first.
        self::assertUnmeasured(AgeMeasure::ALLOWLISTED, $measure->push(new PackageFacts($package, null, null, [], NotCheckedRule::OFFLINE), AgeMeasure::ALLOWLISTED));
        self::assertUnmeasured(AgeMeasure::REPOSITORY_NOT_FOUND, $measure->push(FactsBuilder::facts($package), AgeMeasure::REPOSITORY_NOT_FOUND));
        // A dated answer is a reading whatever reason the caller holds.
        self::assertTrue($measure->push(FactsBuilder::facts($package, null, FactsBuilder::activity(false, self::ago(10))), AgeMeasure::ALLOWLISTED)->isMeasured());
    }

    public function testTheInstalledReading(): void
    {
        $measure = $this->measure();
        $at = self::ago(2 * Clock::SECONDS_PER_YEAR);

        $lockDated = $measure->installed(FactsBuilder::facts(FactsBuilder::package(['time' => $at])));
        self::assertSame(2.0, $lockDated->years());
        self::assertSame($at, $lockDated->at() !== null ? $lockDated->at()->format(\DATE_ATOM) : null);
        self::assertNull($lockDated->level(), 'no threshold applies to the installed release');
        self::assertNull($lockDated->datedBy());
        self::assertNull($lockDated->version(), 'the installed reading carries no version: the finding has it');

        $old = $measure->installed(FactsBuilder::facts(FactsBuilder::package(['time' => self::ago(9 * Clock::SECONDS_PER_YEAR)])));
        self::assertSame(9.0, $old->years());
        self::assertNull($old->level());

        $parent = $measure->installed(FactsBuilder::facts(FactsBuilder::package(['version' => 'v1.0.0', 'time' => self::ago(Clock::SECONDS_PER_YEAR)]), $this->splitMetadata()));
        self::assertSame('vendor/parent', $parent->datedBy());
        self::assertSame(2.0, $parent->years());
    }

    public function testTheInstalledReasons(): void
    {
        $measure = $this->measure();

        self::assertUnmeasured(AgeMeasure::BRANCH_SNAPSHOT, $measure->installed(FactsBuilder::facts(FactsBuilder::package(['version' => 'dev-main', 'time' => self::ago(10)]))));
        self::assertUnmeasured(AgeMeasure::BRANCH_SNAPSHOT, $measure->installed(FactsBuilder::facts(FactsBuilder::package(['version' => 'dev-main']))));
        self::assertUnmeasured(AgeMeasure::UNDATED, $measure->installed(FactsBuilder::facts(FactsBuilder::package())));
        // A split whose newest release a parent dated, while nothing dates the installed tag: the lock's date is a shared commit's.
        $split = $this->splitMetadata();
        self::assertUnmeasured(AgeMeasure::SHARED_COMMIT, $measure->installed(FactsBuilder::facts(FactsBuilder::package(['version' => 'v0.9.0', 'time' => self::ago(10)]), $split)));
    }

    public function testAMeasuredReadingNeedsADateAndAnUnmeasuredOneAReason(): void
    {
        $reading = AgeReading::measured(new \DateTimeImmutable(self::NOW), 69, 6.93, 'v1', 'vendor/parent', Signal::LEVEL_HIGH);

        self::assertSame(6.9, $reading->years());
        self::assertSame(69, $reading->tenths());
        self::assertSame(6.93, $reading->ratio());
        self::assertSame('v1', $reading->version());
        self::assertSame('vendor/parent', $reading->datedBy());
        self::assertSame(Signal::LEVEL_HIGH, $reading->level());
        self::assertTrue($reading->isMeasured());
        self::assertNull(AgeReading::unmeasuredBecause('undated')->tenths());
        self::assertSame(self::NOW, $reading->measuredAt()->format(\DATE_ATOM));
    }

    public function testAnUnmeasuredReadingHasNoDateToGive(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('An unmeasured reading has no date');

        AgeReading::unmeasuredBecause('undated')->measuredAt();
    }

    public function testPushOfReadsTheAnswerItIsGiven(): void
    {
        self::assertUnmeasured(AgeMeasure::UNDATED, $this->measure()->pushOf(FactsBuilder::activity(false, null)));
        self::assertSame(1.0, $this->measure()->pushOf(FactsBuilder::activity(false, self::ago(Clock::SECONDS_PER_YEAR)))->years());
    }

    /**
     * A subtree split whose tags the repository leaves undated, dated by its monorepo parent: the
     * parent's v1.1.0 released a year ago and its v1.0.0 two; the parent has no v0.9.0.
     */
    private function splitMetadata(): PackageMetadata
    {
        $year = new \DateTimeImmutable(self::ago(Clock::SECONDS_PER_YEAR));
        $child = FactsBuilder::metadata([['v1.1.0', null], ['v1.0.0', null], ['v0.9.0', null]]);
        $parent = new PackageMetadata(
            'vendor/parent',
            false,
            null,
            true,
            $year,
            'v1.1.0',
            2,
            'https://github.com/vendor/parent.git',
            'library',
            new \DateTimeImmutable(self::NOW),
            [1 => ['version' => 'v1.1.0', 'at' => $year, 'highest' => ['normalized' => '1.1.0.0', 'pretty' => 'v1.1.0', 'at' => $year], 'php' => null]],
            ['vendor/pkg'],
            null,
            ['1.1.0.0' => $year, '1.0.0.0' => new \DateTimeImmutable(self::ago(2 * Clock::SECONDS_PER_YEAR))]
        );

        return $child->datedBy($parent);
    }
}
