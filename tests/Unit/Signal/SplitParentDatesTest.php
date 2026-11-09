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

    /** @return array{0: array<string, string>, 1: array<string, list<string>>} the level by signal id, and the causes of the S10 `release_dates` entry */
    private static function signals(PackageFacts $facts, string $now): array
    {
        $levels = [];
        $causes = [];
        foreach (SignalSet::default(Clock::fixed($now), new Thresholds(), '8.4', PhpReleaseDates::load())->evaluate($facts) as $signal) {
            $levels[$signal->id()] = $signal->level();
            $data = $signal->data();
            if ($data instanceof NotCheckedData) {
                foreach ($data->checks() as $check) {
                    if ($check->check() === 'release_dates') {
                        $causes = $check->causes();
                    }
                }
            }
        }

        return [$levels, $causes];
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
}
