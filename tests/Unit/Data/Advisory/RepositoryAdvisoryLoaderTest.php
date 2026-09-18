<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Advisory;

use Composer\Repository\AdvisoryProviderInterface;
use Composer\Repository\ArrayRepository;
use Lockrot\Data\Advisory\RepositoryAdvisoryLoader;
use Lockrot\Deadline;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use PHPUnit\Framework\TestCase;

final class RepositoryAdvisoryLoaderTest extends TestCase
{
    private const WALLABAG_LOCK = __DIR__.'/../../../fixtures/apps/wallabag_wallabag/composer.lock';

    private static ?FixtureRepositoryServer $server = null;

    public static function setUpBeforeClass(): void
    {
        self::$server = FixtureRepositoryServer::fromLockFiles([self::WALLABAG_LOCK]);
        self::$server->withSecurityAdvisories([
            'doctrine/cache' => [
                self::record('PKSA-cache-1', '>=2.0,<2.3', ['cve' => 'CVE-2024-0001', 'title' => 'Cache poisoning']),
                self::record('PKSA-cache-2', '<2.0', ['cve' => 'CVE-2019-0002', 'title' => 'Older branch only']),
                ['advisoryId' => 'GHSA-partial-only', 'affectedVersions' => '>=2.2,<2.2.1'],
            ],
            'doctrine/annotations' => [
                self::record('PKSA-annotations-1', '<1.0', ['title' => 'Not the installed range']),
            ],
        ]);
        self::$server->start();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            self::$server->stop();
            self::$server = null;
        }
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private static function record(string $id, string $affected, array $extra): array
    {
        return array_merge([
            'advisoryId' => $id,
            'packageName' => 'doctrine/cache',
            'remoteId' => $id,
            'link' => 'https://example.test/'.$id,
            'affectedVersions' => $affected,
            'sources' => [['name' => 'FriendsOfPHP/security-advisories', 'remoteId' => $id]],
            'reportedAt' => '2024-03-01 12:00:00',
            'severity' => 'high',
        ], $extra);
    }

    private function server(): FixtureRepositoryServer
    {
        self::assertNotNull(self::$server);

        return self::$server;
    }

    public function testOnlyAdvisoriesMatchingTheInstalledVersionComeBackFullOrPartial(): void
    {
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            self::markTestSkipped('Composer without the advisory API');
        }
        $loader = new RepositoryAdvisoryLoader($this->server()->repositories());

        $batch = $loader->load(['doctrine/cache' => '2.2.0', 'doctrine/annotations' => '2.0.2', 'symfony/console' => 'v5.4.47']);

        self::assertSame([], $batch->notes());
        self::assertFalse($batch->hadNetworkFailure());
        self::assertSame(['doctrine/cache'], array_keys($batch->byName()));
        $advisories = $batch->for('doctrine/cache');
        self::assertSame(['PKSA-cache-1', 'GHSA-partial-only'], array_map(static fn ($a) => $a->id(), $advisories));
        self::assertSame('CVE-2024-0001', $advisories[0]->cve());
        self::assertSame('Cache poisoning', $advisories[0]->title());
        self::assertSame('https://example.test/PKSA-cache-1', $advisories[0]->link());
        self::assertSame('high', $advisories[0]->severity());
        self::assertNotNull($advisories[0]->reportedAt());
        self::assertSame('2024-03-01T12:00:00+00:00', $advisories[0]->reportedAt()->format(\DATE_ATOM));
        self::assertNull($advisories[1]->title(), 'a partial record is kept, named by its id');
        self::assertSame([], $batch->for('doctrine/annotations'));
        self::assertSame([], $batch->for('symfony/console'));
    }

    public function testAnUnparsableInstalledVersionIsSkippedNotFatal(): void
    {
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            self::markTestSkipped('Composer without the advisory API');
        }
        $loader = new RepositoryAdvisoryLoader($this->server()->repositories());

        $batch = $loader->load(['doctrine/cache' => 'not a version']);

        self::assertSame([], $batch->byName());
        self::assertSame([], $batch->notes());
    }

    public function testARepositoryWithoutAdvisoriesAndANonComposerRepositoryAreSteppedOver(): void
    {
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            self::markTestSkipped('Composer without the advisory API');
        }
        $plain = FixtureRepositoryServer::fromLockFiles([self::WALLABAG_LOCK]);
        try {
            $plain->start();
            $loader = new RepositoryAdvisoryLoader(array_merge([new ArrayRepository()], $plain->repositories(), $this->server()->repositories()));

            $batch = $loader->load(['doctrine/cache' => '2.2.0']);

            self::assertSame([], $batch->notes());
            self::assertCount(2, $batch->for('doctrine/cache'), 'the repository behind the plain one still answers');
        } finally {
            $plain->stop();
        }
    }

    public function testAnUnreachableRepositoryIsANoteAndANetworkFailureAndTheNextOneStillAnswers(): void
    {
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            self::markTestSkipped('Composer without the advisory API');
        }
        $unreachable = FixtureRepositoryServer::fromLockFiles([self::WALLABAG_LOCK]);
        $loader = new RepositoryAdvisoryLoader(array_merge($unreachable->repositories(), $this->server()->repositories()));

        $batch = $loader->load(['doctrine/cache' => '2.2.0']);

        self::assertCount(1, $batch->notes());
        self::assertStringStartsWith('security advisories unavailable from ', $batch->notes()[0]);
        self::assertTrue($batch->hadNetworkFailure());
        self::assertCount(2, $batch->for('doctrine/cache'));
    }

    public function testOfflineAsksNothing(): void
    {
        $unreachable = FixtureRepositoryServer::fromLockFiles([self::WALLABAG_LOCK]);
        $loader = new RepositoryAdvisoryLoader($unreachable->repositories(), true);

        $batch = $loader->load(['doctrine/cache' => '2.2.0']);

        self::assertSame([RepositoryAdvisoryLoader::NOTE_OFFLINE], $batch->notes());
        self::assertFalse($batch->hadNetworkFailure());
        self::assertSame([], $batch->byName());
    }

    public function testAnExhaustedBudgetAsksNothing(): void
    {
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            self::markTestSkipped('Composer without the advisory API');
        }
        $unreachable = FixtureRepositoryServer::fromLockFiles([self::WALLABAG_LOCK]);
        $loader = new RepositoryAdvisoryLoader($unreachable->repositories(), false, Deadline::inSeconds(0.0, static fn (): float => 0.0));

        $batch = $loader->load(['doctrine/cache' => '2.2.0']);

        self::assertSame([RepositoryAdvisoryLoader::NOTE_BUDGET], $batch->notes());
        self::assertFalse($batch->hadNetworkFailure());
    }

    public function testNoNamesAsksNothing(): void
    {
        $unreachable = FixtureRepositoryServer::fromLockFiles([self::WALLABAG_LOCK]);

        $batch = (new RepositoryAdvisoryLoader($unreachable->repositories()))->load([]);

        self::assertSame([], $batch->notes());
        self::assertSame([], $batch->byName());
    }

    /** On the Composer 2.2 LTS the whole check is one note; on 2.4+ that note never appears. */
    public function testTheComposerVersionNoteMatchesTheApi(): void
    {
        $loader = new RepositoryAdvisoryLoader($this->server()->repositories());

        $batch = $loader->load(['doctrine/cache' => '2.2.0']);

        if (interface_exists(AdvisoryProviderInterface::class)) {
            self::assertNotContains(RepositoryAdvisoryLoader::NOTE_COMPOSER_TOO_OLD, $batch->notes());
        } else {
            self::assertSame([RepositoryAdvisoryLoader::NOTE_COMPOSER_TOO_OLD], $batch->notes());
            self::assertSame([], $batch->byName());
        }
    }
}
