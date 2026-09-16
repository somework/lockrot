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

    public function testTypeAndRepositoryComeFromTheFirstVersionWhenNothingIsDated(): void
    {
        $first = $this->load([
            'name' => 'a/b',
            'version' => '2.0.0',
            'type' => 'symfony-bundle',
            'source' => ['type' => 'git', 'url' => 'https://example.test/a/b.git', 'reference' => 'abc'],
        ]);
        $second = $this->load(['name' => 'a/b', 'version' => '1.0.0', 'source' => ['type' => 'git', 'url' => 'https://example.test/old/b.git', 'reference' => 'abc']]);

        $meta = PackageMetadata::fromPackages('a/b', [$first, $second], new \DateTimeImmutable(self::FIXED));

        self::assertSame('https://example.test/a/b.git', $meta->repositoryUrl(), 'Packagist lists newest first');
        self::assertSame('symfony-bundle', $meta->type());
    }

    /**
     * The phpstan/phpstan shape: the newest releases carry no `source`, `support.source` names the
     * live repository, and a few old releases point at a one-off build repository that has since
     * been archived. The old repository must not be the one asked.
     */
    public function testTheNewestReleasesSupportSourceOutranksAnOlderReleasesSource(): void
    {
        $newest = $this->load([
            'name' => 'phpstan/phpstan',
            'version' => '2.2.14',
            'time' => '2026-09-10T00:00:00+00:00',
            'support' => ['source' => 'https://github.com/phpstan/phpstan-src'],
        ]);
        $old = $this->load([
            'name' => 'phpstan/phpstan',
            'version' => '2.1.29',
            'time' => '2025-09-30T00:00:00+00:00',
            'source' => ['type' => 'git', 'url' => 'https://github.com/phpstan/phpstan-phar-composer-source.git', 'reference' => 'abc'],
        ]);

        $meta = PackageMetadata::fromPackages('phpstan/phpstan', [$newest, $old], new \DateTimeImmutable(self::FIXED));

        self::assertSame('https://github.com/phpstan/phpstan-src', $meta->repositoryUrl());
    }

    /** A renamed repository: the newest release names the new home, older ones the old. */
    public function testTheNewestReleasesSourceWinsWhateverTheOrder(): void
    {
        $old = $this->load(['name' => 'a/b', 'version' => '1.0.0', 'time' => '2019-01-01T00:00:00+00:00', 'source' => ['type' => 'git', 'url' => 'https://github.com/Old/Name.git', 'reference' => 'a']]);
        $new = $this->load(['name' => 'a/b', 'version' => '3.0.0', 'time' => '2026-01-01T00:00:00+00:00', 'source' => ['type' => 'git', 'url' => 'https://github.com/new/name.git', 'reference' => 'b']]);

        self::assertSame('https://github.com/new/name.git', PackageMetadata::fromPackages('a/b', [$old, $new], new \DateTimeImmutable(self::FIXED))->repositoryUrl());
        self::assertSame('https://github.com/new/name.git', PackageMetadata::fromPackages('a/b', [$new, $old], new \DateTimeImmutable(self::FIXED))->repositoryUrl());
    }

    public function testTheNewestReleasesSourceOutranksItsSupportSource(): void
    {
        $newest = $this->load([
            'name' => 'a/b',
            'version' => '2.0.0',
            'time' => '2026-01-01T00:00:00+00:00',
            'source' => ['type' => 'git', 'url' => 'https://github.com/a/b.git', 'reference' => 'a'],
            'support' => ['source' => 'https://github.com/a/b-src'],
        ]);

        self::assertSame('https://github.com/a/b.git', PackageMetadata::fromPackages('a/b', [$newest], new \DateTimeImmutable(self::FIXED))->repositoryUrl());
    }

    /** An older release's source is never substituted: a package whose newest release names no repository has none here. */
    public function testAnOlderReleasesSourceIsNotSubstituted(): void
    {
        $newest = $this->load(['name' => 'a/b', 'version' => '2.0.0', 'time' => '2026-01-01T00:00:00+00:00', 'support' => ['issues' => 'https://github.com/a/b/issues']]);
        $old = $this->load(['name' => 'a/b', 'version' => '1.0.0', 'time' => '2019-01-01T00:00:00+00:00', 'source' => ['type' => 'git', 'url' => 'https://github.com/a/b.git', 'reference' => 'a']]);

        self::assertNull(PackageMetadata::fromPackages('a/b', [$old, $newest], new \DateTimeImmutable(self::FIXED))->repositoryUrl());
    }

    /** A dated release is the anchor even when an undated one comes first in the list. */
    public function testADatedReleaseOutranksAnUndatedOne(): void
    {
        $undated = $this->load(['name' => 'a/b', 'version' => '9.0.0', 'source' => ['type' => 'git', 'url' => 'https://github.com/a/undated.git', 'reference' => 'a']]);
        $dated = $this->load(['name' => 'a/b', 'version' => '1.0.0', 'time' => '2020-01-01T00:00:00+00:00', 'source' => ['type' => 'git', 'url' => 'https://github.com/a/dated.git', 'reference' => 'a']]);

        self::assertSame('https://github.com/a/dated.git', PackageMetadata::fromPackages('a/b', [$undated, $dated], new \DateTimeImmutable(self::FIXED))->repositoryUrl());
    }

    public function testADevOnlyPackageIsReadFromItsFirstBranch(): void
    {
        $branch = $this->load(['name' => 'a/b', 'version' => 'dev-main', 'support' => ['source' => 'https://github.com/a/b']]);
        $other = $this->load(['name' => 'a/b', 'version' => 'dev-legacy', 'source' => ['type' => 'git', 'url' => 'https://github.com/a/legacy.git', 'reference' => 'a']]);

        self::assertSame('https://github.com/a/b', PackageMetadata::fromPackages('a/b', [$branch, $other], new \DateTimeImmutable(self::FIXED))->repositoryUrl());
        self::assertNull(PackageMetadata::fromPackages('a/b', [], new \DateTimeImmutable(self::FIXED))->repositoryUrl());
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
