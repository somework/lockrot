<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Advisory;

use Composer\Repository\AdvisoryProviderInterface;
use Composer\Repository\ArrayRepository;
use Lockrot\Data\Advisory\Advisory;
use Lockrot\Data\Advisory\AdvisoryIgnore;
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
                self::record('PKSA-cache-3', '>=2.2,<2.2.1', ['title' => 'No CVE assigned', 'sources' => [['name' => 'GitHub', 'remoteId' => 'GHSA-cache-3']]]),
            ],
            'doctrine/annotations' => [
                self::record('PKSA-annotations-1', '<1.0', ['title' => 'Not the installed range']),
                self::record('PKSA-annotations-2', '>=2.0,<2.1', ['title' => 'The installed range']),
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

    public function testOnlyAdvisoriesMatchingTheInstalledVersionComeBack(): void
    {
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            self::markTestSkipped('Composer without the advisory API');
        }
        $loader = new RepositoryAdvisoryLoader($this->server()->repositories());

        $batch = $loader->load(['doctrine/cache' => '2.2.0', 'doctrine/annotations' => '2.0.2', 'symfony/console' => 'v5.4.47']);

        self::assertSame([], $batch->notes());
        self::assertFalse($batch->hadNetworkFailure());
        self::assertSame(['doctrine/cache', 'doctrine/annotations'], array_keys($batch->byName()));
        $advisories = $batch->for('doctrine/cache');
        self::assertSame(['PKSA-cache-1', 'PKSA-cache-3'], array_map(static fn (Advisory $a): string => $a->id(), $advisories));
        self::assertSame('CVE-2024-0001', $advisories[0]->cve());
        self::assertSame('Cache poisoning', $advisories[0]->title());
        self::assertSame('https://example.test/PKSA-cache-1', $advisories[0]->link());
        self::assertSame('high', $advisories[0]->severity());
        self::assertNotNull($advisories[0]->reportedAt());
        self::assertSame('2024-03-01T12:00:00+00:00', $advisories[0]->reportedAt()->format(\DATE_ATOM));
        self::assertSame('PKSA-cache-3', $advisories[1]->label(), 'no CVE: named by its id');
        self::assertTrue($advisories[0]->affects('2.2.0.0'), 'the affected range travels with the advisory');
        self::assertFalse($advisories[0]->affects('99.0.0.0'));
        self::assertSame(['PKSA-annotations-2'], array_map(static fn (Advisory $a): string => $a->id(), $batch->for('doctrine/annotations')));
        self::assertSame([], $batch->for('symfony/console'));
    }

    public function testIgnoredAdvisoriesAreDroppedByIdCveSourceIdPackageOrSeverity(): void
    {
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            self::markTestSkipped('Composer without the advisory API');
        }
        $repositories = $this->server()->repositories();
        $ids = static fn (AdvisoryIgnore $ignore): array => array_map(static fn (Advisory $a): string => $a->id(), (new RepositoryAdvisoryLoader($repositories, false, null, $ignore))->load(['doctrine/cache' => '2.2.0'])->for('doctrine/cache'));

        self::assertSame(['PKSA-cache-3'], $ids(AdvisoryIgnore::fromRaw(['PKSA-cache-1'], [])), 'by advisory id');
        self::assertSame(['PKSA-cache-3'], $ids(AdvisoryIgnore::fromRaw(['CVE-2024-0001' => 'accepted risk'], [])), 'by CVE, map form');
        self::assertSame(['PKSA-cache-1'], $ids(AdvisoryIgnore::fromRaw(['GHSA-cache-3'], [])), 'by source id');
        self::assertSame([], $ids(AdvisoryIgnore::fromRaw(['doctrine/cache'], [])), 'by package');
        self::assertSame([], $ids(AdvisoryIgnore::fromRaw([], ['high'])), 'by severity');
        self::assertSame(['PKSA-cache-1', 'PKSA-cache-3'], $ids(AdvisoryIgnore::fromRaw(['PKSA-cache-2'], ['low'])), 'nothing matching');
    }

    public function testAnUnreadableIgnoreListIsANoteOnTheBatch(): void
    {
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            self::markTestSkipped('Composer without the advisory API');
        }
        $loader = new RepositoryAdvisoryLoader($this->server()->repositories(), false, null, new AdvisoryIgnore([], [], 'ignore list not read'));

        $batch = $loader->load(['doctrine/cache' => '2.2.0']);

        self::assertSame(['ignore list not read'], $batch->notes());
        self::assertCount(2, $batch->for('doctrine/cache'), 'nothing is ignored');
        self::assertFalse($batch->hadNetworkFailure());
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

        $batch = $loader->load(['doctrine/annotations' => 'not a version', 'doctrine/cache' => '2.2.0']);

        self::assertSame(['doctrine/cache'], array_keys($batch->byName()), 'the names after the unparsable one are still asked');
    }

    /**
     * A repository whose inline advisory records carry only an id and a range: Composer refuses
     * them as full advisories, and the answer is a note, not a network failure and not a finding
     * built on a record that may have been withdrawn since it was cached.
     */
    public function testARepositoryServingPartialRecordsIsANoteNotAFailure(): void
    {
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            self::markTestSkipped('Composer without the advisory API');
        }
        $partial = FixtureRepositoryServer::fromLockFiles([self::WALLABAG_LOCK]);
        $partial->withSecurityAdvisories(['doctrine/cache' => [['advisoryId' => 'GHSA-partial-only', 'affectedVersions' => '>=2.0,<3.0']]]);
        try {
            $partial->start();
            $loader = new RepositoryAdvisoryLoader($partial->repositories());

            $batch = $loader->load(['doctrine/cache' => '2.2.0']);

            self::assertSame([], $batch->byName());
            self::assertCount(1, $batch->notes());
            self::assertStringContainsString('could not be loaded as a full advisory', $batch->notes()[0]);
            self::assertStringNotContainsString("\n", $batch->notes()[0], 'one line: the var_export dump Composer appends is cut');
            self::assertStringNotContainsString('advisoryId', $batch->notes()[0]);
            self::assertFalse($batch->hadNetworkFailure());

            $behind = new RepositoryAdvisoryLoader(array_merge($partial->repositories(), $this->server()->repositories()));

            $batch = $behind->load(['doctrine/cache' => '2.2.0']);

            self::assertCount(1, $batch->notes());
            self::assertCount(2, $batch->for('doctrine/cache'), 'the repository behind the partial one still answers');
        } finally {
            $partial->stop();
        }
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

    /** Nothing to check means nothing to say, on every Composer. */
    public function testNoNamesIsSilentEvenOffline(): void
    {
        $unreachable = FixtureRepositoryServer::fromLockFiles([self::WALLABAG_LOCK]);

        $batch = (new RepositoryAdvisoryLoader($unreachable->repositories(), true))->load([]);

        self::assertSame([], $batch->notes());
        self::assertSame([], $batch->byName());
    }

    /** ComposerRepository lets a JSON ParsingException past its retry loop; it is not a RuntimeException. */
    public function testARepositoryWhosePackagesJsonIsNotJsonIsANoteAndTheNextOneStillAnswers(): void
    {
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            self::markTestSkipped('Composer without the advisory API');
        }
        $corrupt = FixtureRepositoryServer::fromLockFiles([self::WALLABAG_LOCK]);
        $corrupt->withCorruptPackagesJson();
        try {
            $corrupt->start();
            $loader = new RepositoryAdvisoryLoader(array_merge($corrupt->repositories(), $this->server()->repositories()));

            $batch = $loader->load(['doctrine/cache' => '2.2.0']);

            self::assertCount(1, $batch->notes());
            self::assertStringContainsString('does not contain valid JSON', $batch->notes()[0]);
            self::assertStringNotContainsString("\n", $batch->notes()[0]);
            self::assertFalse($batch->hadNetworkFailure(), 'the server answered; what it said was the problem');
            self::assertCount(2, $batch->for('doctrine/cache'));
        } finally {
            $corrupt->stop();
        }
    }

    /** What a repository throws is a note, whatever its class — and an empty message names the class. */
    public function testAnyThrowableFromARepositoryIsANote(): void
    {
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            self::markTestSkipped('Composer without the advisory API');
        }
        $throwing = static function (\Throwable $e): ArrayRepository {
            return new class ($e) extends ArrayRepository implements AdvisoryProviderInterface {
                private \Throwable $e;

                public function __construct(\Throwable $e)
                {
                    parent::__construct();
                    $this->e = $e;
                }

                public function getRepoName(): string
                {
                    return 'throwing repo';
                }

                public function hasSecurityAdvisories(): bool
                {
                    throw $this->e;
                }

                public function getSecurityAdvisories(array $packageConstraintMap, bool $allowPartialAdvisories = false): array
                {
                    return ['namesFound' => [], 'advisories' => []];
                }
            };
        };

        $batch = (new RepositoryAdvisoryLoader(array_merge([$throwing(new \LogicException("first line\nsecond line"))], $this->server()->repositories())))->load(['doctrine/cache' => '2.2.0']);
        self::assertSame(['security advisories unavailable from throwing repo: first line'], $batch->notes());
        self::assertFalse($batch->hadNetworkFailure());
        self::assertCount(2, $batch->for('doctrine/cache'));

        $batch = (new RepositoryAdvisoryLoader([$throwing(new \RuntimeException(''))]))->load(['doctrine/cache' => '2.2.0']);
        self::assertSame(['security advisories unavailable from throwing repo: RuntimeException'], $batch->notes());
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

    /** The budget runs out between two repositories: the first one's note is kept next to the budget note. */
    public function testABudgetSpentAfterTheFirstRepositoryKeepsItsNote(): void
    {
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            self::markTestSkipped('Composer without the advisory API');
        }
        $unreachable = FixtureRepositoryServer::fromLockFiles([self::WALLABAG_LOCK]);
        $calls = 0;
        // 0.0 while the first repository is queried, then past the 1-second budget
        $now = static function () use (&$calls): float {
            return ++$calls <= 2 ? 0.0 : 10.0;
        };
        $loader = new RepositoryAdvisoryLoader(array_merge($unreachable->repositories(), $this->server()->repositories()), false, Deadline::inSeconds(1.0, $now));

        $batch = $loader->load(['doctrine/cache' => '2.2.0']);

        self::assertCount(2, $batch->notes());
        self::assertStringStartsWith('security advisories unavailable from ', $batch->notes()[0]);
        self::assertSame(RepositoryAdvisoryLoader::NOTE_BUDGET, $batch->notes()[1]);
        self::assertTrue($batch->hadNetworkFailure());
        self::assertSame([], $batch->byName(), 'the second repository was never asked');
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
