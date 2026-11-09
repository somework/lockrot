<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal;

use Composer\Package\Loader\ArrayLoader;
use Lockrot\Clock;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Signal\Data\NotCheckedData;
use Lockrot\Signal\PackageFacts;
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
     * @param list<array{0: string, 1: string, 2: string}> $tags version, commit, time
     * @param array<string, string>                        $replace
     */
    private static function metadata(string $name, array $tags, string $now, ?string $installed = null, array $replace = []): PackageMetadata
    {
        $loader = new ArrayLoader();
        $packages = [];
        foreach ($tags as [$version, $commit, $time]) {
            $packages[] = $loader->load(['name' => $name, 'version' => $version, 'time' => $time, 'require' => ['php' => '>=7.1'], 'replace' => $replace, 'source' => ['type' => 'git', 'url' => 'https://github.com/'.$name.'.git', 'reference' => $commit]]);
        }

        return PackageMetadata::fromPackages($name, $packages, new \DateTimeImmutable($now), $installed);
    }

    /** @return array{0: array<string, string>, 1: array<string, list<string>>, 2: array<string, string>} the level by signal id, the causes of the S10 `release_dates` entry, and the summary by signal id */
    private static function signals(PackageFacts $facts, string $now): array
    {
        $levels = [];
        $causes = [];
        $summaries = [];
        foreach (SignalSet::default(Clock::fixed($now), new Thresholds(), '8.4', PhpReleaseDates::load())->evaluate($facts) as $signal) {
            $levels[$signal->id()] = $signal->level();
            $summaries[$signal->id()] = $signal->summary();
            $data = $signal->data();
            if ($data instanceof NotCheckedData) {
                foreach ($data->checks() as $check) {
                    if ($check->check() === 'release_dates') {
                        $causes = $check->causes();
                    }
                }
            }
        }

        return [$levels, $causes, $summaries];
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

    /** @return iterable<string, array{bool, array<string, string>, string, non-empty-string}> */
    public static function provideSplitsWhoseInstalledSharedTagTheParentDates(): iterable
    {
        yield 'a releasing 2.x above the installed 1.x' => [true, [Signal::S5 => Signal::LEVEL_WARN, Signal::S8 => Signal::LEVEL_HIGH], Signal::S8, 'branch 1.x last released 2019-05-30 (7.4 years ago, dated by acme/mono); '];
        yield 'no line above the installed 1.x' => [false, [Signal::S2 => Signal::LEVEL_HIGH, Signal::S5 => Signal::LEVEL_WARN], Signal::S2, 'last release 2019-05-30 (7.4 years ago, dated by acme/mono)'];
    }

    /**
     * Ruling R16-16: v1.4.9 shares its commit with v1.4.7 and v1.4.8, and the parent dates it
     * 2019-05-30. S2, S5 and S8 all read that date, and S10 names no `release_dates` cause.
     *
     * @dataProvider provideSplitsWhoseInstalledSharedTagTheParentDates
     *
     * @param array<string, string> $expected the level by signal id of S2, S5, S8 and S10
     * @param non-empty-string      $summary  the start of the summary of $judge
     */
    #[DataProvider('provideSplitsWhoseInstalledSharedTagTheParentDates')]
    public function testAParentDateOfASharedCommitTagIsTrustedByEverySignal(bool $withTwoX, array $expected, string $judge, string $summary): void
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

        [$levels, $causes, $summaries] = self::signals(new PackageFacts($package, $split->datedBy($parent), FactsBuilder::activity(false, '2026-10-01T00:00:00+00:00')), $now);

        self::assertSame($expected, array_intersect_key($levels, array_flip([Signal::S2, Signal::S5, Signal::S8, Signal::S10])));
        self::assertSame([], $causes);
        self::assertStringStartsWith($summary, $summaries[$judge] ?? '');
    }
}
