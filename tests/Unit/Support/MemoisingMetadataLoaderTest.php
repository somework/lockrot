<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Support;

use Composer\Package\Loader\ArrayLoader;
use Lockrot\Data\Repository\MetadataBatch;
use Lockrot\Data\Repository\MetadataLoaderInterface;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Data\Repository\StableRelease;
use Lockrot\Tests\Support\MemoisingMetadataLoader;
use PHPUnit\Framework\TestCase;

/**
 * The memo is shared by every test that runs over a fixture app. The release list depends on the
 * installed version, so a memo by name alone hands one test another test's list.
 */
final class MemoisingMetadataLoaderTest extends TestCase
{
    public function testTwoInstalledVersionsOfOneNameAreTwoLoads(): void
    {
        $inner = self::countingLoader();
        $memo = new MemoisingMetadataLoader($inner);

        $low = $memo->load(['a/b' => '1.0.0.0'])->metadata()['a/b'] ?? null;
        $high = $memo->load(['a/b' => '2.0.0.0'])->metadata()['a/b'] ?? null;

        self::assertSame(2, $inner->calls);
        self::assertNotNull($low);
        self::assertNotNull($high);
        self::assertSame(['2.0.0', '3.0.0'], self::versions($low->releasesAbove()));
        self::assertSame(['3.0.0'], self::versions($high->releasesAbove()));
    }

    public function testARepeatedPairIsLoadedOnce(): void
    {
        $inner = self::countingLoader();
        $memo = new MemoisingMetadataLoader($inner);

        $first = $memo->load(['a/b' => '1.0.0.0', 'c/d' => null]);
        $second = $memo->load(['a/b' => '1.0.0.0', 'c/d' => null]);

        self::assertSame(1, $inner->calls);
        self::assertSame($first->metadata(), $second->metadata());
        $parent = $second->metadata()['c/d'] ?? null;
        self::assertNotNull($parent);
        self::assertNull($parent->releasesAbove(), 'a null version keeps no list');
    }

    public function testOnlyTheMissingPairsReachTheInnerLoader(): void
    {
        $inner = self::countingLoader();
        $memo = new MemoisingMetadataLoader($inner);

        $memo->load(['a/b' => '1.0.0.0']);
        $batch = $memo->load(['a/b' => '1.0.0.0', 'c/d' => '1.0.0.0', 'x/missing' => '1.0.0.0']);

        self::assertSame([['a/b' => '1.0.0.0'], ['c/d' => '1.0.0.0', 'x/missing' => '1.0.0.0']], $inner->asked);
        self::assertSame(['a/b', 'c/d'], array_keys($batch->metadata()));
        self::assertSame(['x/missing'], $batch->notFound());
    }

    /**
     * @param list<StableRelease>|null $releases
     *
     * @return list<string>
     */
    private static function versions(?array $releases): array
    {
        return array_map(static fn (StableRelease $release): string => $release->pretty(), $releases ?? []);
    }

    /** @return MetadataLoaderInterface&object{calls: int, asked: list<array<string, ?string>>} */
    private static function countingLoader(): MetadataLoaderInterface
    {
        return new class () implements MetadataLoaderInterface {
            public int $calls = 0;
            /** @var list<array<string, ?string>> */
            public array $asked = [];

            public function load(array $installedByName): MetadataBatch
            {
                ++$this->calls;
                $this->asked[] = $installedByName;
                $metadata = [];
                $notFound = [];
                foreach ($installedByName as $name => $installed) {
                    if ($name === 'x/missing') {
                        $notFound[] = $name;
                        continue;
                    }
                    $versions = [];
                    foreach (['1.0.0', '2.0.0', '3.0.0'] as $version) {
                        $versions[] = (new ArrayLoader())->load(['name' => $name, 'version' => $version]);
                    }
                    $metadata[$name] = PackageMetadata::fromPackages($name, $versions, new \DateTimeImmutable('2026-09-14T00:00:00+00:00'), $installed);
                }

                return new MetadataBatch($metadata, $notFound, []);
            }
        };
    }
}
