<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Repository;

use Composer\Package\CompletePackage;
use Composer\Package\Loader\ArrayLoader;
use Lockrot\Data\Repository\PackageMetadata;
use PHPUnit\Framework\TestCase;

final class PackageMetadataFromPackagesTest extends TestCase
{
    private const FIXED = '2026-09-14T00:00:00+00:00';

    /** @param array<string, mixed> $config */
    private function load(array $config): CompletePackage
    {
        $loader = new ArrayLoader();
        $package = $loader->load($config);
        self::assertInstanceOf(CompletePackage::class, $package);

        return $package;
    }

    public function testAbandonedTrueWithNoReplacement(): void
    {
        $version = $this->load(['name' => 'a/b', 'version' => '1.0.0', 'abandoned' => true]);

        $meta = PackageMetadata::fromPackages('a/b', [$version], new \DateTimeImmutable(self::FIXED));

        self::assertTrue($meta->isAbandoned());
        self::assertNull($meta->replacement());
    }

    public function testAbandonedStringReplacement(): void
    {
        $version = $this->load(['name' => 'a/b', 'version' => '1.0.0', 'abandoned' => 'c/d']);

        $meta = PackageMetadata::fromPackages('a/b', [$version], new \DateTimeImmutable(self::FIXED));

        self::assertTrue($meta->isAbandoned());
        self::assertSame('c/d', $meta->replacement());
    }

    public function testDevOnlyHasNoStableRelease(): void
    {
        $version = $this->load(['name' => 'a/b', 'version' => 'dev-master']);

        $meta = PackageMetadata::fromPackages('a/b', [$version], new \DateTimeImmutable(self::FIXED));

        self::assertFalse($meta->hasStableRelease());
        self::assertNull($meta->lastStableReleaseAt());
        self::assertNull($meta->lastStableVersion());
        self::assertSame(1, $meta->releaseCount());
    }

    public function testStableOrderingPicksGreatestReleaseDate(): void
    {
        $older = $this->load(['name' => 'a/b', 'version' => '1.0.0', 'time' => '2020-01-01T00:00:00+00:00']);
        $newer = $this->load(['name' => 'a/b', 'version' => '2.0.0', 'time' => '2024-05-01T00:00:00+00:00']);
        $devMaster = $this->load(['name' => 'a/b', 'version' => 'dev-master', 'time' => '2025-01-01T00:00:00+00:00']);

        $meta = PackageMetadata::fromPackages('a/b', [$older, $newer, $devMaster], new \DateTimeImmutable(self::FIXED));

        self::assertTrue($meta->hasStableRelease());
        $at = $meta->lastStableReleaseAt();
        self::assertNotNull($at);
        self::assertInstanceOf(\DateTimeImmutable::class, $at);
        self::assertSame('2024-05-01', $at->format('Y-m-d'));
        self::assertSame('2.0.0', $meta->lastStableVersion());
        self::assertSame(3, $meta->releaseCount());
    }

    public function testNoDatesLeavesLastStableNull(): void
    {
        $version = $this->load(['name' => 'a/b', 'version' => '1.0.0']);

        $meta = PackageMetadata::fromPackages('a/b', [$version], new \DateTimeImmutable(self::FIXED));

        self::assertTrue($meta->hasStableRelease());
        self::assertNull($meta->lastStableReleaseAt());
        self::assertNull($meta->lastStableVersion());
    }

    public function testSourceUrlAndTypeFromFirstVersion(): void
    {
        $first = $this->load([
            'name' => 'a/b',
            'version' => '1.0.0',
            'type' => 'symfony-bundle',
            'source' => ['type' => 'git', 'url' => 'https://example.test/a/b.git', 'reference' => 'abc'],
        ]);
        $second = $this->load(['name' => 'a/b', 'version' => '2.0.0']);

        $meta = PackageMetadata::fromPackages('a/b', [$first, $second], new \DateTimeImmutable(self::FIXED));

        self::assertSame('https://example.test/a/b.git', $meta->sourceUrl());
        self::assertSame('symfony-bundle', $meta->type());
    }

    public function testSourceUrlFallsBackToFirstNonNull(): void
    {
        $first = $this->load(['name' => 'a/b', 'version' => '1.0.0']);
        $second = $this->load([
            'name' => 'a/b',
            'version' => '2.0.0',
            'source' => ['type' => 'git', 'url' => 'https://example.test/a/b.git', 'reference' => 'abc'],
        ]);

        $meta = PackageMetadata::fromPackages('a/b', [$first, $second], new \DateTimeImmutable(self::FIXED));

        self::assertSame('https://example.test/a/b.git', $meta->sourceUrl());
    }

    public function testTypeDefaultsToLibrary(): void
    {
        $version = $this->load(['name' => 'a/b', 'version' => '1.0.0']);

        $meta = PackageMetadata::fromPackages('a/b', [$version], new \DateTimeImmutable(self::FIXED));

        self::assertSame('library', $meta->type());
    }

    public function testDataDateEqualsGivenClockValue(): void
    {
        $version = $this->load(['name' => 'a/b', 'version' => '1.0.0']);
        $dataDate = new \DateTimeImmutable(self::FIXED);

        $meta = PackageMetadata::fromPackages('a/b', [$version], $dataDate);

        self::assertSame(self::FIXED, $meta->dataDate()->format(\DATE_ATOM));
    }
}
