<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Packagist;

use Lockrot\Data\Packagist\P2Expander;
use Lockrot\Data\Packagist\PackageMetadata;
use PHPUnit\Framework\TestCase;

final class PackageMetadataTest extends TestCase
{
    private const RAW = __DIR__.'/../../../fixtures/p2-raw/';
    private const FETCHED = '2026-09-14T00:00:00+00:00';

    /** @return list<array<string, mixed>> */
    private function versions(string $file, string $name): array
    {
        $response = json_decode((string) file_get_contents(self::RAW.$file), true);
        self::assertIsArray($response);
        /** @var array<string, mixed> $response */
        return P2Expander::expandResponse($response, $name);
    }

    public function testPhpzipLastStableRelease(): void
    {
        $meta = PackageMetadata::fromP2('phpzip/phpzip', $this->versions('phpzip_phpzip.json', 'phpzip/phpzip'), [], new \DateTimeImmutable(self::FETCHED));
        self::assertFalse($meta->isAbandoned());
        self::assertTrue($meta->hasStableRelease());
        $lastStableReleaseAt = $meta->lastStableReleaseAt();
        self::assertNotNull($lastStableReleaseAt);
        self::assertSame('2015-11-16', $lastStableReleaseAt->format('Y-m-d'));
        self::assertSame('2.0.8', $meta->lastStableVersion());
        self::assertSame(4, $meta->releaseCount());
        self::assertSame('https://github.com/Grandt/PHPZip.git', $meta->sourceUrl());
        self::assertSame('library', $meta->type());
    }

    public function testAbandonedBooleanAndReplacement(): void
    {
        $meta = PackageMetadata::fromP2('doctrine/cache', $this->versions('doctrine_cache.json', 'doctrine/cache'), [], new \DateTimeImmutable(self::FETCHED));
        self::assertTrue($meta->isAbandoned());
        self::assertNull($meta->replacement());

        $replaced = PackageMetadata::fromP2('a/b', [['name' => 'a/b', 'version' => '1.0.0', 'abandoned' => 'c/d']], [], new \DateTimeImmutable(self::FETCHED));
        self::assertTrue($replaced->isAbandoned());
        self::assertSame('c/d', $replaced->replacement());
    }

    public function testNoStableReleasesOnlyDev(): void
    {
        $meta = PackageMetadata::fromP2('lox/xhprof', [], $this->versions('lox_xhprof~dev.json', 'lox/xhprof'), new \DateTimeImmutable(self::FETCHED));
        self::assertFalse($meta->hasStableRelease());
        self::assertNull($meta->lastStableReleaseAt());
        self::assertSame(1, $meta->releaseCount());
        self::assertNull($meta->lastStableVersion());
    }

    public function testBetaCountsAsNonDevRelease(): void
    {
        $meta = PackageMetadata::fromP2('a/b', [
            ['name' => 'a/b', 'version' => '2.0.0-beta1', 'time' => '2024-05-01T00:00:00+00:00'],
            ['version' => '1.0.0', 'time' => '2020-01-01T00:00:00+00:00'],
        ], [], new \DateTimeImmutable(self::FETCHED));
        $lastStableReleaseAt = $meta->lastStableReleaseAt();
        self::assertNotNull($lastStableReleaseAt);
        self::assertSame('2024-05-01', $lastStableReleaseAt->format('Y-m-d'));
    }

    public function testTypeFromFirstVersionAndMissingTime(): void
    {
        $meta = PackageMetadata::fromP2('symfony/twig-pack', $this->versions('symfony_twig-pack.json', 'symfony/twig-pack'), [], new \DateTimeImmutable(self::FETCHED));
        self::assertSame('symfony-pack', $meta->type());
        $noTime = PackageMetadata::fromP2('a/b', [['name' => 'a/b', 'version' => '1.0.0']], [], new \DateTimeImmutable(self::FETCHED));
        self::assertTrue($noTime->hasStableRelease());
        self::assertNull($noTime->lastStableReleaseAt());
    }
}
