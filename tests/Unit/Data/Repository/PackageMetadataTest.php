<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Repository;

use Composer\Package\CompletePackage;
use Composer\Package\Loader\ArrayLoader;
use Lockrot\Clock;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Data\Repository\RepositoryMetadataLoader;
use Lockrot\Data\Repository\StableRelease;
use Lockrot\Lock\LockFile;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use PHPUnit\Framework\TestCase;

/** What PackageMetadata keeps for the release scan: SPEC-0.14 5.3, DECISIONS.md 2.34. */
final class PackageMetadataTest extends TestCase
{
    private const NOW = '2026-09-14T00:00:00+00:00';
    private const KOEL_LOCK = __DIR__.'/../../../fixtures/apps/koel_koel/composer.lock';
    /** Where the kept lists can grow a run's memory: about 1,000 releases of five short fields. */
    private const KEPT_LISTS_BYTES = 4 * 1024 * 1024;

    /** @param array<string, mixed> $config */
    private function release(array $config): CompletePackage
    {
        $package = (new ArrayLoader())->load(['name' => 'a/b'] + $config);
        self::assertInstanceOf(CompletePackage::class, $package);

        return $package;
    }

    /** @return list<CompletePackage> */
    private function history(): array
    {
        return [
            $this->release(['version' => '1.0.0', 'time' => '2020-01-01T00:00:00+00:00', 'require' => ['php' => '>=7.1']]),
            $this->release(['version' => '1.2.0', 'time' => '2021-01-01T00:00:00+00:00', 'require' => ['php' => '>=7.2', 'Psr/Log' => '^1.0', 'c/d' => '^2']]),
            $this->release(['version' => '1.2.1', 'time' => '2021-06-01T00:00:00+00:00', 'require' => ['php' => '>=7.2']]),
            $this->release(['version' => '1.3.0-RC1', 'time' => '2021-09-01T00:00:00+00:00']),
            $this->release(['version' => 'v2.0.0', 'time' => '2022-01-01T00:00:00+00:00', 'require' => ['php' => '>=8.1', 'c/d' => '^3']]),
            $this->release(['version' => '2.1.0', 'time' => '2026-12-01T00:00:00+00:00', 'require' => ['php' => '>=8.2']]),
            $this->release(['version' => 'dev-main', 'time' => '2026-01-01T00:00:00+00:00']),
        ];
    }

    /**
     * @param list<StableRelease> $releases
     *
     * @return list<string>
     */
    private static function versions(array $releases): array
    {
        return array_map(static fn (StableRelease $release): string => $release->pretty(), $releases);
    }

    public function testItKeepsTheStableReleasesAboveTheInstalledOneInVersionOrder(): void
    {
        $meta = PackageMetadata::fromPackages('a/b', array_reverse($this->history()), new \DateTimeImmutable(self::NOW), '1.2.0.0');
        $releases = $meta->releasesAbove();

        self::assertNotNull($releases);
        self::assertSame(['1.2.1', 'v2.0.0', '2.1.0'], self::versions($releases), 'pre-releases and branches are no candidates');
        self::assertSame(
            ['normalized' => '2.0.0.0', 'pretty' => 'v2.0.0', 'at' => '2022-01-01T00:00:00+00:00', 'php' => '>=8.1', 'shared_commit' => false],
            $releases[1]->toArray()
        );
        self::assertSame('>=8.2', $releases[2]->php(), 'each release keeps its own php');
    }

    /** SPEC-0.14 5.3: for a branch snapshot, every stable release is a candidate. */
    public function testABranchSnapshotKeepsEveryStableRelease(): void
    {
        $meta = PackageMetadata::fromPackages('a/b', $this->history(), new \DateTimeImmutable(self::NOW), 'dev-main');

        self::assertSame(['1.0.0', '1.2.0', '1.2.1', 'v2.0.0', '2.1.0'], self::versions($meta->releasesAbove() ?? []));
    }

    /** A monorepo parent is loaded with no installed version and keeps its dates only. */
    public function testWithNoInstalledVersionItKeepsNoReleaseList(): void
    {
        $meta = PackageMetadata::fromPackages('a/b', $this->history(), new \DateTimeImmutable(self::NOW));

        self::assertNull($meta->releasesAbove());
        self::assertNull($meta->installedRequires());
        self::assertNull($meta->newestStable());
        self::assertNull($meta->newestRequires());
    }

    public function testAnInstalledVersionThatDoesNotParseKeepsNoReleaseList(): void
    {
        self::assertNull(PackageMetadata::fromPackages('a/b', $this->history(), new \DateTimeImmutable(self::NOW), 'not a version')->releasesAbove());
    }

    /** C08 reads two name lists per package: the installed release's and the newest stable one's. */
    public function testItKeepsTheRequireNamesOfTheInstalledAndOfTheNewestStableRelease(): void
    {
        $meta = PackageMetadata::fromPackages('a/b', $this->history(), new \DateTimeImmutable(self::NOW), '1.2.0.0');

        self::assertSame(['php', 'psr/log', 'c/d'], $meta->installedRequires());
        $newest = $meta->newestStable();
        self::assertNotNull($newest);
        self::assertSame('v2.0.0', $newest->pretty(), 'by version, among the releases not dated after the run clock');
        self::assertSame(['php', 'c/d'], $meta->newestRequires());
    }

    public function testAnInstalledReleaseTheRepositoryDoesNotListHasNoRequireNames(): void
    {
        $meta = PackageMetadata::fromPackages('a/b', $this->history(), new \DateTimeImmutable(self::NOW), '1.1.5.0');

        self::assertNull($meta->installedRequires());
        self::assertSame(['1.2.0', '1.2.1', 'v2.0.0', '2.1.0'], self::versions($meta->releasesAbove() ?? []));
    }

    /** A tag on a commit that three or more stable tags share has no trusted date of its own. */
    public function testAReleaseOnASharedCommitIsKeptUndated(): void
    {
        $shared = ['type' => 'git', 'url' => 'https://example.org/a/b.git', 'reference' => 'abc'];
        $versions = [
            $this->release(['version' => '1.0.0', 'time' => '2020-01-01T00:00:00+00:00', 'source' => ['type' => 'git', 'url' => 'https://example.org/a/b.git', 'reference' => 'one']]),
            $this->release(['version' => '1.0.1', 'time' => '2024-01-01T00:00:00+00:00', 'source' => $shared]),
            $this->release(['version' => '1.0.2', 'time' => '2024-01-01T00:00:00+00:00', 'source' => $shared]),
            $this->release(['version' => '1.0.3', 'time' => '2024-01-01T00:00:00+00:00', 'source' => $shared]),
        ];
        $releases = PackageMetadata::fromPackages('a/b', $versions, new \DateTimeImmutable(self::NOW), '1.0.0.0')->releasesAbove() ?? [];

        self::assertCount(3, $releases);
        foreach ($releases as $release) {
            self::assertTrue($release->sharedCommit());
            self::assertNull($release->at());
        }
    }

    /** The monorepo parent dates a split's shared-commit releases, as it dates its branches. */
    public function testAParentDatesTheSharedCommitReleasesOfASplit(): void
    {
        $now = new \DateTimeImmutable(self::NOW);
        $shared = ['type' => 'git', 'url' => 'https://example.org/c.git', 'reference' => 'abc'];
        $child = PackageMetadata::fromPackages('s/c', [
            (new ArrayLoader())->load(['name' => 's/c', 'version' => 'v1.0.0', 'time' => '2020-01-01T00:00:00+00:00', 'source' => ['type' => 'git', 'url' => 'https://example.org/c.git', 'reference' => 'one']]),
            (new ArrayLoader())->load(['name' => 's/c', 'version' => 'v1.0.1', 'time' => '2024-01-01T00:00:00+00:00', 'source' => $shared]),
            (new ArrayLoader())->load(['name' => 's/c', 'version' => 'v1.0.2', 'time' => '2024-01-01T00:00:00+00:00', 'source' => $shared]),
            (new ArrayLoader())->load(['name' => 's/c', 'version' => 'v1.0.3', 'time' => '2024-01-01T00:00:00+00:00', 'source' => $shared]),
        ], $now, '1.0.0.0');
        $parent = PackageMetadata::fromPackages('s/s', [
            (new ArrayLoader())->load(['name' => 's/s', 'version' => 'v1.0.1', 'time' => '2021-02-01T00:00:00+00:00', 'replace' => ['s/c' => 'self.version'], 'source' => ['type' => 'git', 'url' => 'https://example.org/s.git', 'reference' => 'p1']]),
            (new ArrayLoader())->load(['name' => 's/s', 'version' => 'v1.0.2', 'time' => '2021-03-01T00:00:00+00:00', 'replace' => ['s/c' => 'self.version'], 'source' => ['type' => 'git', 'url' => 'https://example.org/s.git', 'reference' => 'p2']]),
        ], $now);

        $dated = $child->datedBy($parent)->releasesAbove() ?? [];

        self::assertSame(['2021-02-01', '2021-03-01', null], array_map(static fn (StableRelease $r): ?string => $r->at() === null ? null : $r->at()->format('Y-m-d'), $dated));
        self::assertTrue($dated[0]->sharedCommit(), 'the parent dates it; its tag still shares a commit');
        self::assertNull(($child->releasesAbove() ?? [])[0]->at(), 'datedBy() returns a copy');
    }

    /**
     * laravel/framework lists about 1,300 versions. Installed at its first stable tag, every stable
     * release is kept. The test compares two loads of the same 200-package lock in one process, one
     * that keeps the lists and one that keeps none (a monorepo parent's shape), so no number measured
     * on one machine is in it.
     */
    public function testTheKeptListsOfALargePackageCostLittleMemory(): void
    {
        $lock = LockFile::fromFile(self::KOEL_LOCK);
        $installed = [];
        foreach ($lock->packages(true) as $package) {
            if ($package->name() !== 'laravel/framework' && \count($installed) < 199) {
                $installed[$package->name()] = $package->normalizedVersion();
            }
        }
        $installed['laravel/framework'] = '5.0.0.0';
        self::assertCount(200, $installed);

        $server = FixtureRepositoryServer::fromLockFiles([self::KOEL_LOCK]);
        $server->start();
        try {
            $clock = Clock::fixed(self::NOW);
            $dropped = array_fill_keys(array_keys($installed), null);
            // The first load also fills Composer's class and static caches: keep it out of both sides.
            (new RepositoryMetadataLoader($server->repositories(), $clock))->load($dropped);
            [$withoutLists, $bytesWithout] = self::retained(static fn () => (new RepositoryMetadataLoader($server->repositories(), $clock))->load($dropped));
            [$withLists, $bytesWith] = self::retained(static fn () => (new RepositoryMetadataLoader($server->repositories(), $clock))->load($installed));
        } finally {
            $server->stop();
        }

        self::assertSame(array_keys($withoutLists->metadata()), array_keys($withLists->metadata()), 'both loads read the same packages');
        self::assertGreaterThan(100, \count($withLists->metadata()), 'most names have a recorded answer');
        $laravel = $withLists->metadata()['laravel/framework'] ?? null;
        self::assertNotNull($laravel);
        self::assertGreaterThan(900, \count($laravel->releasesAbove() ?? []), 'the worst case is measured');
        self::assertNull(($withoutLists->metadata()['laravel/framework'] ?? $laravel)->releasesAbove());
        if (getenv('LOCKROT_PRINT_MEMORY') === '1') {
            fwrite(\STDERR, \sprintf("\nretained: %d bytes without the lists, %d bytes with them\n", $bytesWithout, $bytesWith));
        }
        self::assertLessThanOrEqual(self::KEPT_LISTS_BYTES, $bytesWith - $bytesWithout);
    }

    /**
     * @template T
     *
     * @param callable(): T $load
     *
     * @return array{0: T, 1: int} what the load returned, still referenced, and the bytes it holds
     */
    private static function retained(callable $load): array
    {
        gc_collect_cycles();
        $before = memory_get_usage();
        $result = $load();
        gc_collect_cycles();

        return [$result, memory_get_usage() - $before];
    }
}
