<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Repository;

use Lockrot\Clock;
use Lockrot\Data\Repository\RepositoryMetadataLoader;
use Lockrot\Lock\LockFile;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use PHPUnit\Framework\TestCase;

final class RepositoryMetadataLoaderTest extends TestCase
{
    private const WALLABAG_LOCK = __DIR__.'/../../../fixtures/apps/wallabag_wallabag/composer.lock';
    private const MATOMO_LOCK = __DIR__.'/../../../fixtures/apps/matomo-org_matomo/composer.lock';
    private const FIXED = '2026-09-14T00:00:00+00:00';

    private static ?FixtureRepositoryServer $server = null;
    private static ?RepositoryMetadataLoader $loader = null;

    public static function setUpBeforeClass(): void
    {
        self::$server = FixtureRepositoryServer::fromLockFiles([self::WALLABAG_LOCK, self::MATOMO_LOCK]);
        self::$server->start();
        // One loader (and so one HttpDownloader/curl-multi handle) shared across every test in this
        // class: repeatedly building a fresh RepositoryManager against the same long-lived php -S
        // process left stale connections behind that eventually wedged the server, measured while
        // writing this test — reusing a single loader avoids that and matches how a real analyzer
        // run would use it (one instance queried many times).
        self::$loader = new RepositoryMetadataLoader(self::$server->repositories(), Clock::fixed(self::FIXED));
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            self::$server->stop();
            self::$server = null;
        }
        self::$loader = null;
    }

    private function loader(): RepositoryMetadataLoader
    {
        $loader = self::$loader;
        self::assertNotNull($loader);

        return $loader;
    }

    /** @return list<string> */
    private function wallabagNames(): array
    {
        $lock = LockFile::fromFile(self::WALLABAG_LOCK);
        $names = [];
        foreach ($lock->packages(false) as $package) {
            $names[] = $package->name();
        }

        return $names;
    }

    public function testPhpzipIsFoundWithStableRelease(): void
    {
        $batch = $this->loader()->load(['phpzip/phpzip']);

        $meta = $batch->metadata()['phpzip/phpzip'] ?? null;
        self::assertNotNull($meta);
        self::assertTrue($meta->hasStableRelease());
        self::assertSame('2.0.8', $meta->lastStableVersion());
        $at = $meta->lastStableReleaseAt();
        self::assertNotNull($at);
        self::assertSame('2015-11-16', $at->format('Y-m-d'));
        self::assertFalse($meta->isAbandoned());
    }

    public function testLoxXhprofIsFoundViaDevAndIsAbandonedWithNoStableRelease(): void
    {
        $batch = $this->loader()->load(['lox/xhprof']);

        $meta = $batch->metadata()['lox/xhprof'] ?? null;
        self::assertNotNull($meta);
        self::assertTrue($meta->isAbandoned());
        self::assertFalse($meta->hasStableRelease());
    }

    public function testWallabagRulerzHasNoStableRelease(): void
    {
        $batch = $this->loader()->load(['wallabag/rulerz']);

        $meta = $batch->metadata()['wallabag/rulerz'] ?? null;
        self::assertNotNull($meta);
        self::assertFalse($meta->hasStableRelease());
    }

    public function testNonexistentPackageIsNotFoundAndNotFailed(): void
    {
        $batch = $this->loader()->load(['nonexistent/zzz']);

        self::assertSame(['nonexistent/zzz'], $batch->notFound());
        self::assertSame([], $batch->failed());
    }

    public function testTwentyFiveNamesAllResolveAcrossChunkedCalls(): void
    {
        $names = \array_slice($this->wallabagNames(), 0, 25);

        $batch = $this->loader()->load($names);

        foreach ($names as $name) {
            self::assertArrayHasKey($name, $batch->metadata(), $name.' should have resolved');
        }
        self::assertSame([], $batch->failed());
    }

    public function testFullWallabagListResolvesUnderMemoryBudget(): void
    {
        $names = $this->wallabagNames();

        $batch = $this->loader()->load($names);

        self::assertCount(\count($names), $batch->metadata());
        self::assertLessThan(64 * 1024 * 1024, memory_get_peak_usage(true));
    }

    public function testUnreachableServerWithEmptyCacheMarksEveryNameFailed(): void
    {
        // A freshly constructed, never-started server: its port is not bound and its cache dir is
        // empty, so every request genuinely fails instead of being served from a warm cache.
        $server = FixtureRepositoryServer::fromLockFiles([self::WALLABAG_LOCK]);
        $loader = new RepositoryMetadataLoader($server->repositories(), Clock::fixed(self::FIXED));

        $batch = $loader->load(['phpzip/phpzip', 'wallabag/rulerz']);

        self::assertSame([], $batch->notFound());
        self::assertSame([], $batch->metadata());
        foreach (['phpzip/phpzip', 'wallabag/rulerz'] as $name) {
            self::assertArrayHasKey($name, $batch->failed());
            self::assertNotSame('', $batch->failed()[$name]);
        }

        $server->stop();
    }

    public function testNoRepositoriesMeansEveryNameIsNotFound(): void
    {
        $loader = new RepositoryMetadataLoader([], Clock::fixed(self::FIXED));

        $batch = $loader->load(['a/b', 'c/d']);

        self::assertSame(['a/b', 'c/d'], $batch->notFound());
        self::assertSame([], $batch->failed());
        self::assertSame([], $batch->metadata());
    }

    public function testDataDateEqualsTheFixedClock(): void
    {
        $batch = $this->loader()->load(['phpzip/phpzip']);

        $meta = $batch->metadata()['phpzip/phpzip'] ?? null;
        self::assertNotNull($meta);
        self::assertSame(self::FIXED, $meta->dataDate()->format(\DATE_ATOM));
    }
}
