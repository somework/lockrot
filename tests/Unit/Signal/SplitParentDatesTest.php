<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal;

use Composer\Package\Loader\ArrayLoader;
use Lockrot\Clock;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\InstalledRelease;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Signal\AgeMeasure;
use Lockrot\Signal\BranchRow;
use Lockrot\Signal\Data\LeftBehindData;
use Lockrot\Signal\Data\NoReleaseData;
use Lockrot\Signal\Data\NotCheckedData;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\PhpFloor;
use Lockrot\Signal\Rule\NotCheckedRule;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The signals of a subtree split that a monorepo parent dates: S2, S5, S8 and the S10
 * `release_dates` entry read one date for each tag (docs/verdicts.md#dates-from-the-monorepo).
 */
final class SplitParentDatesTest extends TestCase
{
    /**
     * @param list<array{0: string, 1: string, 2: ?string}> $tags version, commit, time (null: no time)
     * @param array<string, string>                         $replace
     */
    private static function metadata(string $name, array $tags, string $now, ?string $installed = null, array $replace = []): PackageMetadata
    {
        $loader = new ArrayLoader();
        $packages = [];
        foreach ($tags as [$version, $commit, $time]) {
            $packages[] = $loader->load(['name' => $name, 'version' => $version, 'require' => ['php' => '>=7.1'], 'replace' => $replace, 'source' => ['type' => 'git', 'url' => 'https://github.com/'.$name.'.git', 'reference' => $commit]] + ($time === null ? [] : ['time' => $time]));
        }

        return PackageMetadata::fromPackages($name, $packages, new \DateTimeImmutable($now), $installed);
    }

    /** @return array{0: array<string, string>, 1: array<string, list<string>>, 2: array<string, array{string, ?string}>} the level by signal id, the causes of the S10 `release_dates` entry, and the release day and `dated_by` that S2 and S8 read */
    private static function signals(PackageFacts $facts, string $now): array
    {
        $levels = [];
        $causes = [];
        $dates = [];
        foreach (SignalSet::default(Clock::fixed($now), new Thresholds(), '8.4', PhpReleaseDates::load())->evaluate($facts) as $signal) {
            $levels[$signal->id()] = $signal->level();
            $data = $signal->data();
            if ($data instanceof NoReleaseData) {
                $dates[$signal->id()] = [substr($data->lastRelease(), 0, 10), $data->datedBy()];
            }
            if ($data instanceof LeftBehindData) {
                $dates[$signal->id()] = [substr($data->branchLastRelease(), 0, 10), $data->datedBy()];
            }
            if ($data instanceof NotCheckedData) {
                foreach ($data->checks() as $check) {
                    if ($check->check() === 'release_dates') {
                        $causes = $check->causes();
                    }
                }
            }
        }

        return [$levels, $causes, $dates];
    }

    /** Ruling R16-26: v2.2.0-beta1 shares the commit of v2.0.1 to v2.0.3, and the parent lists only v5.0.0. */
    public function testANewestPreReleaseOnASharedCommitLeavesS2UnknownAndS10NamesTheSharedCommit(): void
    {
        $now = '2026-09-14T00:00:00+00:00';
        $split = self::metadata('acme/split', [
            ['v2.1.0', 'c21', '2020-01-01T00:00:00+00:00'],
            ['v2.0.1', 'c-shared', '2021-01-01T00:00:00+00:00'],
            ['v2.0.2', 'c-shared', '2021-01-01T00:00:00+00:00'],
            ['v2.0.3', 'c-shared', '2021-01-01T00:00:00+00:00'],
            ['v2.2.0-beta1', 'c-shared', '2021-01-01T00:00:00+00:00'],
        ], $now, 'v2.1.0');
        $parent = self::metadata('acme/mono', [['v5.0.0', 'm50', '2026-01-01T00:00:00+00:00']], $now, null, ['acme/split' => 'self.version']);
        $package = FactsBuilder::package(['name' => 'acme/split', 'version' => 'v2.1.0', 'time' => '2020-01-01T00:00:00+00:00']);

        [$levels, $causes] = self::signals(new PackageFacts($package, $split->datedBy($parent), FactsBuilder::activity(false, '2026-09-01T00:00:00+00:00')), $now);

        self::assertArrayNotHasKey(Signal::S2, $levels);
        self::assertSame([NotCheckedRule::CAUSE_SHARED_COMMIT => [Signal::S2]], $causes);
    }

    /**
     * Ruling R17-D1: an undated pre-release above the highest stable tag is an unresolved date, also
     * when the parent dates the split's highest stable branch. Without the pre-release, S2 reads the
     * parent date.
     */
    public function testAnUndatedPreReleaseAboveAParentDatedHighestBranchLeavesS2Unknown(): void
    {
        $now = '2026-09-14T00:00:00+00:00';
        $stable = [['v13.30.0', 'c1330', null], ['v13.31.0', 'c1331', null]];
        $parent = self::metadata('acme/mono', [
            ['v13.30.0', 'm1330', '2021-01-01T00:00:00+00:00'],
            ['v13.31.0', 'm1331', '2021-02-01T00:00:00+00:00'],
        ], $now, null, ['acme/split' => 'self.version']);
        $package = FactsBuilder::package(['name' => 'acme/split', 'version' => 'v13.30.0', 'time' => '2021-01-01T00:00:00+00:00']);
        $activity = FactsBuilder::activity(false, '2026-09-01T00:00:00+00:00');
        $withPreRelease = self::metadata('acme/split', array_merge($stable, [['v14.0.0-beta1', 'c14b1', null]]), $now, 'v13.30.0');

        foreach ([$withPreRelease->datedBy($parent), $withPreRelease] as $metadata) {
            $facts = new PackageFacts($package, $metadata, $activity);
            [$levels, $causes] = self::signals($facts, $now);

            self::assertArrayNotHasKey(Signal::S2, $levels);
            self::assertSame([NotCheckedRule::CAUSE_TAG_UNDATED => [Signal::S2]], $causes);
            self::assertSame(AgeMeasure::UNDATED_RELEASES, (new AgeMeasure(Clock::fixed($now), new Thresholds()))->release($facts)->unmeasured());
        }
        [$levels, , $dates] = self::signals(new PackageFacts($package, self::metadata('acme/split', $stable, $now, 'v13.30.0')->datedBy($parent), $activity), $now);
        self::assertSame(Signal::LEVEL_HIGH, $levels[Signal::S2] ?? null);
        self::assertSame(['2021-02-01', 'acme/mono'], $dates[Signal::S2] ?? null);
    }

    /** Ruling R17-D1: the highest row's own date does not replace an undated pre-release that sits on a shared commit above it. */
    public function testAnUndatedPreReleaseOnASharedCommitAboveADatedHighestRowLeavesS2Unknown(): void
    {
        $now = '2026-09-14T00:00:00+00:00';
        $split = self::metadata('acme/split', [
            ['v13.29.0', 'c-shared', '2020-01-01T00:00:00+00:00'],
            ['v13.29.1', 'c-shared', '2020-01-01T00:00:00+00:00'],
            ['v13.29.2', 'c-shared', '2020-01-01T00:00:00+00:00'],
            ['v13.31.0', 'c1331', '2021-02-01T00:00:00+00:00'],
            ['v14.0.0-beta1', 'c-shared', null],
        ], $now, 'v13.31.0');
        $parent = self::metadata('acme/mono', [['v13.29.0', 'm1329', '2019-12-01T00:00:00+00:00']], $now, null, ['acme/split' => 'self.version']);
        $package = FactsBuilder::package(['name' => 'acme/split', 'version' => 'v13.31.0', 'time' => '2021-02-01T00:00:00+00:00']);

        foreach ([$split->datedBy($parent), $split] as $metadata) {
            [$levels, $causes] = self::signals(new PackageFacts($package, $metadata, FactsBuilder::activity(false, '2026-09-01T00:00:00+00:00')), $now);

            self::assertArrayNotHasKey(Signal::S2, $levels);
            self::assertSame([NotCheckedRule::CAUSE_SHARED_COMMIT => [Signal::S2]], $causes);
        }
    }

    /** @return iterable<string, array{bool, array<string, string>, string, array{string, string}}> */
    public static function provideSplitsWhoseInstalledSharedTagTheParentDates(): iterable
    {
        yield 'a releasing 2.x above the installed 1.x' => [true, [Signal::S5 => Signal::LEVEL_WARN, Signal::S8 => Signal::LEVEL_HIGH], Signal::S8, ['2019-05-30', 'acme/mono']];
        yield 'no line above the installed 1.x' => [false, [Signal::S2 => Signal::LEVEL_HIGH, Signal::S5 => Signal::LEVEL_WARN], Signal::S2, ['2019-05-30', 'acme/mono']];
    }

    /**
     * Ruling R16-16: v1.4.9 shares its commit with v1.4.7 and v1.4.8, and the parent dates it
     * 2019-05-30. S2, S5 and S8 all read that date, and S10 names no `release_dates` cause.
     *
     * @dataProvider provideSplitsWhoseInstalledSharedTagTheParentDates
     *
     * @param array<string, string> $expected the level by signal id of S2, S5, S8 and S10
     * @param array{string, string} $read     the release day and `dated_by` that $judge reads
     */
    #[DataProvider('provideSplitsWhoseInstalledSharedTagTheParentDates')]
    public function testAParentDateOfASharedCommitTagIsTrustedByEverySignal(bool $withTwoX, array $expected, string $judge, array $read): void
    {
        $now = '2026-11-09T00:00:00+00:00';
        $tags = [
            ['1.5.0', 'c150', '2018-01-01T00:00:00+00:00'],
            ['1.4.9', 'c-shared', '2019-06-01T00:00:00+00:00'],
            ['1.4.8', 'c-shared', '2019-06-01T00:00:00+00:00'],
            ['1.4.7', 'c-shared', '2019-06-01T00:00:00+00:00'],
        ];
        if ($withTwoX) {
            $tags[] = ['2.0.0', 'c200', '2025-06-01T00:00:00+00:00'];
        }
        $split = self::metadata('acme/split', $tags, $now, '1.4.9');
        $parent = self::metadata('acme/mono', [
            ['1.5.0', 'm150', '2018-01-01T00:00:00+00:00'],
            ['1.4.9', 'm149', '2019-05-30T00:00:00+00:00'],
            ['1.4.8', 'm148', '2019-04-01T00:00:00+00:00'],
            ['2.0.0', 'm200', '2025-06-01T00:00:00+00:00'],
        ], $now, null, ['acme/split' => 'self.version']);
        $package = FactsBuilder::package(['name' => 'acme/split', 'version' => '1.4.9', 'time' => '2019-06-01T00:00:00+00:00', 'php' => '>=7.1']);

        [$levels, $causes, $dates] = self::signals(new PackageFacts($package, $split->datedBy($parent), FactsBuilder::activity(false, '2026-10-01T00:00:00+00:00')), $now);

        self::assertSame($expected, array_intersect_key($levels, array_flip([Signal::S2, Signal::S5, Signal::S8, Signal::S10])));
        self::assertSame([], $causes);
        self::assertSame($read, $dates[$judge] ?? null);
    }

    /** @return iterable<string, array{list<array{0: string, 1: string, 2: string}>, list<array{0: string, 1: string, 2: string}>, string, string, array<string, string>}> */
    public static function provideOwnCommitTagsBesideAParentDatedSharedTag(): iterable
    {
        yield 'the installed highest tag, the parent dates a shared tag of its line' => [
            [['v2.1.0', 'c210', '2019-01-01T00:00:00+00:00'], ['v2.0.5', 'c-shared', '2021-01-01T00:00:00+00:00'], ['v2.0.6', 'c-shared', '2021-01-01T00:00:00+00:00'], ['v2.0.7', 'c-shared', '2021-01-01T00:00:00+00:00']],
            [['v2.0.7', 'm207', '2021-03-01T00:00:00+00:00']],
            'v2.1.0',
            '2019-01-01T00:00:00+00:00',
            [Signal::S2 => Signal::LEVEL_HIGH, Signal::S5 => Signal::LEVEL_WARN],
        ];
        yield 'an installed lower line, the parent dates a shared tag of the highest line' => [
            [['v1.5.0', 'c150', '2018-01-01T00:00:00+00:00'], ['v3.1.0', 'c310', '2024-01-01T00:00:00+00:00'], ['v3.0.5', 'c-shared', '2025-01-01T00:00:00+00:00'], ['v3.0.6', 'c-shared', '2025-01-01T00:00:00+00:00'], ['v3.0.7', 'c-shared', '2025-01-01T00:00:00+00:00']],
            [['v3.0.7', 'm307', '2025-03-01T00:00:00+00:00'], ['v3.1.0', 'm310', '2024-01-01T00:00:00+00:00']],
            'v1.5.0',
            '2018-01-01T00:00:00+00:00',
            [Signal::S5 => Signal::LEVEL_WARN, Signal::S8 => Signal::LEVEL_HIGH],
        ];
    }

    /**
     * Ruling R16-16b: only a parent row that replaces the highest branch row makes the lock time
     * of a tag on its own commit a commit date. A parent date of one shared tag does not.
     *
     * @dataProvider provideOwnCommitTagsBesideAParentDatedSharedTag
     *
     * @param list<array{0: string, 1: string, 2: string}> $tags
     * @param list<array{0: string, 1: string, 2: string}> $parentTags
     * @param array<string, string>                        $expected the level by signal id of S2, S5, S8 and S10
     */
    #[DataProvider('provideOwnCommitTagsBesideAParentDatedSharedTag')]
    public function testALockTimeOfATagOnItsOwnCommitStaysAReleaseDateBesideAParentDatedSharedTag(array $tags, array $parentTags, string $installed, string $time, array $expected): void
    {
        $now = '2026-09-14T00:00:00+00:00';
        $split = self::metadata('acme/split', $tags, $now, $installed)->datedBy(self::metadata('acme/mono', $parentTags, $now, null, ['acme/split' => 'self.version']));
        $facts = new PackageFacts(FactsBuilder::package(['name' => 'acme/split', 'version' => $installed, 'time' => $time, 'php' => '>=7.1']), $split, FactsBuilder::activity(false, '2026-09-01T00:00:00+00:00'));

        $release = InstalledRelease::of($facts->package(), $split);
        [$levels, $causes] = self::signals($facts, $now);

        self::assertSame([InstalledRelease::RELEASE, $time], [$release->kind(), $release->at() === null ? null : $release->at()->format(\DATE_ATOM)]);
        $age = (new AgeMeasure(Clock::fixed($now), new Thresholds()))->installed($facts)->at();
        self::assertSame($time, $age === null ? null : $age->format(\DATE_ATOM));
        self::assertSame('acme/mono', $split->lastStableDatedBy(), 'the last release is the parent-dated shared tag');
        self::assertSame($expected, array_intersect_key($levels, array_flip([Signal::S2, Signal::S5, Signal::S8, Signal::S10])));
        self::assertSame([], $causes);
    }

    /**
     * 1.4.9 is the highest tag of 1.x and shares its commit. The parent dates 1.4.9, but its own 1.x
     * row has an undated highest tag, so no parent row replaces the split's 1.x row.
     */
    public function testAParentDateOfAHighestTagOnASharedCommitDatesTheBranch(): void
    {
        $now = '2026-09-14T00:00:00+00:00';
        $split = self::metadata('acme/split', [
            ['1.4.7', 'c-shared', '2019-06-01T00:00:00+00:00'],
            ['1.4.8', 'c-shared', '2019-06-01T00:00:00+00:00'],
            ['1.4.9', 'c-shared', '2019-06-01T00:00:00+00:00'],
            ['2.0.0', 'c200', '2025-06-01T00:00:00+00:00'],
        ], $now, '1.4.9');
        $parent = self::metadata('acme/mono', [
            ['1.4.9', 'm149', '2019-05-30T00:00:00+00:00'],
            ['1.4.10', 'm1410', null],
            ['2.0.0', 'm200', '2025-06-01T00:00:00+00:00'],
        ], $now, null, ['acme/split' => 'self.version']);
        $facts = new PackageFacts(FactsBuilder::package(['name' => 'acme/split', 'version' => '1.4.9', 'time' => '2019-06-01T00:00:00+00:00', 'php' => '>=7.1']), $split->datedBy($parent), FactsBuilder::activity(false, '2026-09-01T00:00:00+00:00'));

        [$levels, $causes, $dates] = self::signals($facts, $now);
        $row = BranchRow::all($facts, new PhpFloor('8.4'), Clock::fixed($now))[1];

        self::assertSame([Signal::S5 => Signal::LEVEL_WARN, Signal::S8 => Signal::LEVEL_HIGH], array_intersect_key($levels, array_flip([Signal::S2, Signal::S5, Signal::S8, Signal::S10])));
        self::assertSame([], $causes);
        self::assertSame(['2019-05-30', 'acme/mono'], $dates[Signal::S8] ?? null);
        self::assertSame('1.x', $row->branch());
        self::assertEquals(new \DateTimeImmutable('2019-05-30T00:00:00+00:00'), $row->highestReleased());
        self::assertNull($row->highestCommitDate(), 'the parent dates the highest tag, so no commit date stands in for it');
        self::assertSame('acme/mono', $row->datedBy());
        self::assertFalse($row->newestDatedAtLeast());
    }
}
