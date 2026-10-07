<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Advisory;

use Composer\Repository\AdvisoryProviderInterface;
use Composer\Repository\ArrayRepository;
use Composer\Repository\FilterRepository;
use Lockrot\Analyzer\RunNote;
use Lockrot\Data\Advisory\Advisory;
use Lockrot\Data\Advisory\AdvisoryBatch;
use Lockrot\Data\Advisory\AdvisoryCoverage;
use Lockrot\Data\Advisory\AdvisoryIgnore;
use Lockrot\Data\Advisory\AdvisoryIgnoreMatch;
use Lockrot\Data\Advisory\AdvisoryNameCoverage;
use Lockrot\Data\Advisory\IgnoredAdvisory;
use Lockrot\Data\Advisory\RepositoryAdvisoryLoader;
use Lockrot\Deadline;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use PHPUnit\Framework\TestCase;

final class RepositoryAdvisoryLoaderTest extends TestCase
{
    private const WALLABAG_LOCK = __DIR__.'/../../../fixtures/apps/wallabag_wallabag/composer.lock';
    private const BUDGET_NOTE = 'security advisories not checked: install-time budget exhausted; a priority they would raise stays one step lower';

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
            'title' => 'Title of '.$id,
            'link' => 'https://example.test/'.$id,
            'affectedVersions' => $affected,
            'sources' => [['name' => 'FriendsOfPHP/security-advisories', 'remoteId' => 'FOP-'.$id]],
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

        self::assertSame([], self::texts($batch));
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

    public function testIgnoredAdvisoriesLeaveTheCountAndAreKeptWithTheirRecord(): void
    {
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            self::markTestSkipped('Composer without the advisory API');
        }
        $repositories = $this->server()->repositories();
        $load = static fn (AdvisoryIgnore $ignore): AdvisoryBatch => (new RepositoryAdvisoryLoader($repositories, false, null, $ignore))->load(['doctrine/cache' => '2.2.0']);
        $ids = static fn (AdvisoryBatch $batch): array => array_map(static fn (Advisory $a): string => $a->id(), $batch->for('doctrine/cache'));
        $ignored = static fn (AdvisoryBatch $batch): array => array_map(static fn (IgnoredAdvisory $i): array => [$i->advisory()->id(), $i->match()->kind()], $batch->ignored('doctrine/cache'));

        $byId = $load(AdvisoryIgnore::fromRaw(['PKSA-cache-1' => 'reviewed'], []));
        self::assertSame(['PKSA-cache-3'], $ids($byId), 'by advisory id');
        self::assertSame([['PKSA-cache-1', AdvisoryIgnoreMatch::ID]], $ignored($byId));
        self::assertSame('reviewed', $byId->ignored('doctrine/cache')[0]->match()->reason());
        self::assertSame('CVE-2024-0001', $byId->ignored('doctrine/cache')[0]->advisory()->cve());
        self::assertSame([['PKSA-cache-1', AdvisoryIgnoreMatch::CVE]], $ignored($load(AdvisoryIgnore::fromRaw(['CVE-2024-0001' => 'accepted risk'], []))), 'by CVE, map form');
        self::assertSame([['PKSA-cache-3', AdvisoryIgnoreMatch::REMOTE_ID]], $ignored($load(AdvisoryIgnore::fromRaw(['GHSA-cache-3'], []))), 'by source id');
        self::assertSame([], $ids($load(AdvisoryIgnore::fromRaw(['doctrine/cache'], []))), 'by package');
        self::assertSame([['PKSA-cache-1', AdvisoryIgnoreMatch::SEVERITY], ['PKSA-cache-3', AdvisoryIgnoreMatch::SEVERITY]], $ignored($load(AdvisoryIgnore::fromRaw([], ['high']))), 'by severity');
        $nothing = $load(AdvisoryIgnore::fromRaw(['PKSA-cache-2'], ['low']));
        self::assertSame(['PKSA-cache-1', 'PKSA-cache-3'], $ids($nothing), 'an ignored advisory that does not affect the installed version');
        self::assertSame([], $nothing->ignored('doctrine/cache'), 'only an advisory that affects the installed version is listed as ignored');
        self::assertSame(['PKSA-cache-1', 'PKSA-cache-3'], array_map(static fn (Advisory $a): string => $a->id(), $nothing->every('doctrine/cache')), 'every version, without the ignored one');
        $coverage = $byId->coverage()->for('doctrine/cache');
        self::assertNotNull($coverage);
        self::assertSame(3, $coverage->records(), 'an ignored advisory is still a record');
    }

    public function testAnUnreadableIgnoreListIsANoteOnTheBatch(): void
    {
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            self::markTestSkipped('Composer without the advisory API');
        }
        $loader = new RepositoryAdvisoryLoader($this->server()->repositories(), false, null, AdvisoryIgnore::unreadable('Unknown key "licenses"'));

        $batch = $loader->load(['doctrine/cache' => '2.2.0']);

        self::assertSame(["Composer's advisory ignore list not read (Unknown key \"licenses\"); every advisory counts"], self::texts($batch));
        self::assertSame(RunNote::ADVISORY_IGNORE_UNREADABLE, $batch->notes()[0]->code());
        self::assertSame(['message' => 'Unknown key "licenses"'], $batch->notes()[0]->data());
        self::assertCount(2, $batch->for('doctrine/cache'), 'nothing is ignored');
        self::assertSame([false], self::networkFailures($batch));
    }

    public function testAnUnparsableInstalledVersionIsSkippedNotFatal(): void
    {
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            self::markTestSkipped('Composer without the advisory API');
        }
        $loader = new RepositoryAdvisoryLoader($this->server()->repositories());

        $batch = $loader->load(['doctrine/cache' => 'not a version']);

        self::assertSame([], $batch->byName());
        self::assertSame([], self::texts($batch));

        $batch = $loader->load(['doctrine/annotations' => 'not a version', 'doctrine/cache' => '2.2.0']);

        self::assertSame(['doctrine/cache'], array_keys($batch->byName()), 'the names after the unparsable one are still asked');
    }

    /**
     * Composer refuses an inline advisory record with only an id and a range as a full advisory.
     * The answer is a note, not a network failure, and no finding rests on a record that a
     * repository can withdraw after the cache holds it.
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
            self::assertCount(1, self::texts($batch));
            self::assertStringContainsString('could not be loaded as a full advisory', self::texts($batch)[0]);
            self::assertStringNotContainsString("\n", self::texts($batch)[0], 'one line: the var_export dump Composer appends is cut');
            self::assertStringNotContainsString('advisoryId', self::texts($batch)[0]);
            self::assertSame([false], self::networkFailures($batch));

            $behind = new RepositoryAdvisoryLoader(array_merge($partial->repositories(), $this->server()->repositories()));

            $batch = $behind->load(['doctrine/cache' => '2.2.0']);

            self::assertCount(1, self::texts($batch));
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

            self::assertSame([], self::texts($batch));
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

        self::assertCount(1, self::texts($batch));
        self::assertStringStartsWith('security advisories unavailable from ', self::texts($batch)[0]);
        self::assertSame(RunNote::ADVISORIES_UNAVAILABLE, $batch->notes()[0]->code());
        self::assertSame([true], self::networkFailures($batch), 'a transport failure');
        self::assertCount(2, $batch->for('doctrine/cache'));
    }

    /** Nothing to check means nothing to say, on every Composer. */
    public function testNoNamesIsSilentEvenOffline(): void
    {
        $unreachable = FixtureRepositoryServer::fromLockFiles([self::WALLABAG_LOCK]);

        $batch = (new RepositoryAdvisoryLoader($unreachable->repositories(), true))->load([]);

        self::assertSame([], self::texts($batch));
        self::assertSame([], $batch->byName());
    }

    /** ComposerRepository lets a JSON ParsingException past its retry loop. It is not a RuntimeException. */
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

            self::assertCount(1, self::texts($batch));
            self::assertStringContainsString('does not contain valid JSON', self::texts($batch)[0]);
            self::assertStringNotContainsString("\n", self::texts($batch)[0]);
            self::assertSame([false], self::networkFailures($batch), 'the server answered; what it said was the problem');
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
        self::assertSame(['security advisories unavailable from throwing repo: first line'], self::texts($batch));
        self::assertSame(['composer_repository' => 'throwing repo', 'message' => 'first line'], $batch->notes()[0]->data());
        self::assertSame([false], self::networkFailures($batch));
        self::assertCount(2, $batch->for('doctrine/cache'));

        $batch = (new RepositoryAdvisoryLoader([$throwing(new \RuntimeException(''))]))->load(['doctrine/cache' => '2.2.0']);
        self::assertSame(['security advisories unavailable from throwing repo: RuntimeException'], self::texts($batch));
    }

    public function testOfflineAsksNothing(): void
    {
        $unreachable = FixtureRepositoryServer::fromLockFiles([self::WALLABAG_LOCK]);
        $loader = new RepositoryAdvisoryLoader($unreachable->repositories(), true);

        $batch = $loader->load(['doctrine/cache' => '2.2.0']);

        self::assertSame(['offline: security advisories not checked; a priority they would raise stays one step lower'], self::texts($batch));
        self::assertSame(['reason' => 'offline', 'composer_repositories_checked' => 0], $batch->notes()[0]->data());
        self::assertSame([false], self::networkFailures($batch));
        self::assertSame([], $batch->byName());
    }

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

        self::assertCount(2, self::texts($batch));
        self::assertStringStartsWith('security advisories unavailable from ', self::texts($batch)[0]);
        self::assertSame(self::BUDGET_NOTE, self::texts($batch)[1]);
        self::assertSame(['reason' => 'install_time_budget', 'composer_repositories_checked' => 1], $batch->notes()[1]->data(), 'the first repository was asked, and failed, before the budget ran out: it counts all the same');
        self::assertSame([true, false], self::networkFailures($batch));
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

        self::assertSame([self::BUDGET_NOTE], self::texts($batch));
        self::assertSame(['reason' => 'install_time_budget', 'composer_repositories_checked' => 0], $batch->notes()[0]->data());
        self::assertSame([false], self::networkFailures($batch));
    }

    /** On the Composer 2.2 LTS the whole check is one note. On 2.4 or newer that note never appears. */
    public function testTheComposerVersionNoteMatchesTheApi(): void
    {
        $loader = new RepositoryAdvisoryLoader($this->server()->repositories());

        $batch = $loader->load(['doctrine/cache' => '2.2.0']);

        if (interface_exists(AdvisoryProviderInterface::class)) {
            self::assertSame([], $batch->notes());
        } else {
            self::assertSame(['security advisories not checked (needs Composer 2.4 or newer); a priority they would raise stays one step lower'], self::texts($batch));
            self::assertSame(['reason' => 'composer_too_old', 'composer_repositories_checked' => 0], $batch->notes()[0]->data());
            self::assertSame([], $batch->byName());
            self::assertSame(AdvisoryCoverage::COMPOSER_TOO_OLD, self::nameCoverage($batch, 'doctrine/cache')->reason());
            self::assertSame([], $batch->coverage()->repositories(), 'Composer 2.2 names no repository advisory-capable');
        }
    }

    /** @var list<FixtureRepositoryServer> */
    private array $started = [];

    protected function tearDown(): void
    {
        foreach ($this->started as $server) {
            $server->stop();
        }
        $this->started = [];
    }

    /**
     * A started repository that serves advisories through an API, as packagist.org does.
     *
     * @param array<string, list<array<string, mixed>>> $advisories
     * @param list<string>                              $patterns
     */
    private function api(array $advisories, array $patterns = []): FixtureRepositoryServer
    {
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            self::markTestSkipped('Composer without the advisory API');
        }
        $server = FixtureRepositoryServer::fromLockFiles([self::WALLABAG_LOCK]);
        $server->withAdvisoryApi($advisories, $patterns);
        $server->start();
        $this->started[] = $server;

        return $server;
    }

    /** @return array{answer: string, reason: ?string, records: ?int} */
    private static function feed(AdvisoryBatch $batch, string $name, int $index): array
    {
        $coverage = $batch->coverage()->for($name);
        self::assertNotNull($coverage, $name.' has a record');
        $feed = $coverage->feeds()[$index] ?? null;
        self::assertNotNull($feed, $name.' has feed '.$index);

        return ['answer' => $feed['answer'], 'reason' => $feed['reason'], 'records' => $feed['records']];
    }

    private static function nameCoverage(AdvisoryBatch $batch, string $name): AdvisoryNameCoverage
    {
        $coverage = $batch->coverage()->for($name);
        self::assertNotNull($coverage, $name.' has a record');

        return $coverage;
    }

    /** @return list<array{string, ?string}> each repository's outcome and reason, in configured order */
    private static function outcomes(AdvisoryBatch $batch): array
    {
        return array_map(static fn (array $repository): array => [$repository['outcome'], $repository['reason']], $batch->coverage()->repositories());
    }

    public function testOneRequestPerRepositoryAsksEveryNameForEveryVersion(): void
    {
        $server = $this->api(['doctrine/cache' => [self::record('PKSA-cache-1', '>=2.0,<2.3', []), self::record('PKSA-cache-2', '<2.0', [])], 'doctrine/annotations' => []]);

        $batch = (new RepositoryAdvisoryLoader($server->repositories()))->load(['doctrine/cache' => '2.2.0', 'doctrine/annotations' => '2.0.2', 'acme/unknown' => '1.0.0']);

        self::assertCount(1, $server->advisoryRequests(), 'one POST for the whole run');
        self::assertSame(['doctrine/cache', 'doctrine/annotations', 'acme/unknown'], $server->advisoryRequests()[0]);
        self::assertSame(['PKSA-cache-1'], array_map(static fn (Advisory $a): string => $a->id(), $batch->for('doctrine/cache')), 'attributed to the installed version here');
        self::assertSame(['PKSA-cache-1', 'PKSA-cache-2'], array_map(static fn (Advisory $a): string => $a->id(), $batch->every('doctrine/cache')), 'every version is kept');
        self::assertSame([['composer_repository' => $server->repositories()[0]->getRepoName(), 'answer' => AdvisoryCoverage::ANSWERED, 'reason' => null, 'message' => null, 'records' => 2]], self::nameCoverage($batch, 'doctrine/cache')->feeds());
        self::assertSame(['answer' => AdvisoryCoverage::ANSWERED, 'reason' => null, 'records' => 0], self::feed($batch, 'doctrine/annotations', 0), 'a known name echoed with []');
        self::assertSame(['answer' => AdvisoryCoverage::ANSWERED, 'reason' => null, 'records' => 0], self::feed($batch, 'acme/unknown', 0), 'an unknown name the answer leaves out');
        self::assertSame(2, self::nameCoverage($batch, 'doctrine/cache')->records());
        self::assertNull(self::nameCoverage($batch, 'doctrine/cache')->reason());
        self::assertTrue($batch->complete());
        $repository = $batch->coverage()->repositories()[0];
        self::assertSame($server->repositories()[0]->getRepoName(), $repository['composer_repository']);
        self::assertSame([AdvisoryCoverage::ANSWERED, null, null, 2, 1], [$repository['outcome'], $repository['reason'], $repository['message'], $repository['records'], $repository['packages_with_records']]);
        $two = $this->api(['doctrine/cache' => [self::record('PKSA-cache-1', '*', [])], 'doctrine/annotations' => [self::record('PKSA-annotations-1', '*', [])]]);
        $both = (new RepositoryAdvisoryLoader($two->repositories()))->load(['doctrine/cache' => '2.2.0', 'doctrine/annotations' => '2.0.2']);
        self::assertSame([2, 2], [$both->coverage()->repositories()[0]['records'], $both->coverage()->repositories()[0]['packages_with_records']]);
        self::assertSame(0, $batch->coverage()->otherRepositories());
        self::assertSame([AdvisoryCoverage::SCOPE_ALL, AdvisoryCoverage::SOURCE_DEFAULT], [$batch->coverage()->scope(), $batch->coverage()->scopeSource()]);
    }

    public function testAnAnswerWithOnlyUnknownNamesIsAList(): void
    {
        $server = $this->api(['doctrine/cache' => []]);

        $batch = (new RepositoryAdvisoryLoader($server->repositories()))->load(['acme/unknown' => '1.0.0']);

        self::assertSame([], self::texts($batch));
        self::assertSame(['answer' => AdvisoryCoverage::ANSWERED, 'reason' => null, 'records' => 0], self::feed($batch, 'acme/unknown', 0));
        self::assertTrue($batch->complete());
    }

    public function testAMetadataPathRepositoryCountsTheRecordsOfThePackageFile(): void
    {
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            self::markTestSkipped('Composer without the advisory API');
        }
        $batch = (new RepositoryAdvisoryLoader($this->server()->repositories()))->load(['doctrine/cache' => '2.2.0', 'symfony/console' => 'v5.4.47']);

        self::assertSame(['answer' => AdvisoryCoverage::ANSWERED, 'reason' => null, 'records' => 3], self::feed($batch, 'doctrine/cache', 0));
        self::assertSame(['answer' => AdvisoryCoverage::ANSWERED, 'reason' => null, 'records' => 0], self::feed($batch, 'symfony/console', 0));
        self::assertSame(['PKSA-cache-1', 'PKSA-cache-3'], array_map(static fn (Advisory $a): string => $a->id(), $batch->for('doctrine/cache')));
    }

    public function testANameOutsideTheRepositorysPatternsIsNeverSentAndCountsNoRecord(): void
    {
        $server = $this->api(['doctrine/cache' => [self::record('PKSA-cache-1', '>=2.0,<2.3', [])], 'symfony/console' => [self::record('PKSA-console', '*', [])]], ['doctrine/*']);

        $batch = (new RepositoryAdvisoryLoader($server->repositories()))->load(['doctrine/cache' => '2.2.0', 'symfony/console' => 'v5.4.47']);

        self::assertSame([['doctrine/cache']], $server->advisoryRequests());
        self::assertSame(['answer' => AdvisoryCoverage::ANSWERED, 'reason' => null, 'records' => 0], self::feed($batch, 'symfony/console', 0), 'lockrot cannot see the filter: the same answer as no record');
        self::assertSame([], $batch->for('symfony/console'));
    }

    public function testTwoRepositoriesServingOneIdCountItOnceForTheName(): void
    {
        $first = $this->api(['doctrine/cache' => [self::record('PKSA-cache-1', '>=2.0,<2.3', []), self::record('PKSA-cache-8', '<1.0', [])]]);
        $second = $this->api(['doctrine/cache' => [self::record('PKSA-cache-1', '>=2.0,<2.3', []), self::record('PKSA-cache-9', '<1.0', [])]]);

        $batch = (new RepositoryAdvisoryLoader(array_merge($first->repositories(), $second->repositories())))->load(['doctrine/cache' => '2.2.0']);

        self::assertSame(2, self::feed($batch, 'doctrine/cache', 0)['records']);
        self::assertSame(2, self::feed($batch, 'doctrine/cache', 1)['records']);
        self::assertSame(3, self::nameCoverage($batch, 'doctrine/cache')->records(), 'PKSA-cache-1 counts once');
        self::assertCount(1, $batch->for('doctrine/cache'));
    }

    public function testATransportFailureOnOneOfTwoRepositoriesIsAFailedFeedAndANetworkFailure(): void
    {
        $unreachable = FixtureRepositoryServer::fromLockFiles([self::WALLABAG_LOCK]);
        $answering = $this->api(['doctrine/cache' => []]);

        $batch = (new RepositoryAdvisoryLoader(array_merge($unreachable->repositories(), $answering->repositories())))->load(['doctrine/cache' => '2.2.0']);

        $failed = self::nameCoverage($batch, 'doctrine/cache')->feeds()[0];
        self::assertSame($batch->coverage()->repositories()[0]['composer_repository'], $failed['composer_repository']);
        self::assertSame($batch->coverage()->repositories()[0]['message'], $failed['message']);
        self::assertSame([AdvisoryCoverage::FAILED, AdvisoryCoverage::LOOKUP_FAILED, null], [$failed['answer'], $failed['reason'], $failed['records']]);
        self::assertSame(AdvisoryCoverage::ANSWERED, self::feed($batch, 'doctrine/cache', 1)['answer']);
        self::assertSame(AdvisoryCoverage::LOOKUP_FAILED, self::nameCoverage($batch, 'doctrine/cache')->reason());
        self::assertSame(0, self::nameCoverage($batch, 'doctrine/cache')->records(), 'what the other feed returned still counts');
        self::assertSame([[AdvisoryCoverage::FAILED, AdvisoryCoverage::TRANSPORT], [AdvisoryCoverage::ANSWERED, null]], self::outcomes($batch));
        $message = $batch->coverage()->repositories()[0]['message'];
        self::assertIsString($message);
        self::assertStringNotContainsString("\n", $message);
        self::assertSame([true], self::networkFailures($batch));
        self::assertFalse($batch->complete());
    }

    public function testAnAnswerLockrotCannotReadIsAnInvalidResponseAndNoNetworkFailure(): void
    {
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            self::markTestSkipped('Composer without the advisory API');
        }
        $corrupt = FixtureRepositoryServer::fromLockFiles([self::WALLABAG_LOCK]);
        $corrupt->withCorruptPackagesJson();
        $corrupt->start();
        $this->started[] = $corrupt;

        $batch = (new RepositoryAdvisoryLoader($corrupt->repositories()))->load(['doctrine/cache' => '2.2.0']);

        self::assertSame(['answer' => AdvisoryCoverage::FAILED, 'reason' => AdvisoryCoverage::LOOKUP_FAILED, 'records' => null], self::feed($batch, 'doctrine/cache', 0));
        self::assertSame([[AdvisoryCoverage::FAILED, AdvisoryCoverage::INVALID_RESPONSE]], self::outcomes($batch));
        self::assertNull(self::nameCoverage($batch, 'doctrine/cache')->records(), 'no feed answered');
        self::assertSame(AdvisoryCoverage::LOOKUP_FAILED, self::nameCoverage($batch, 'doctrine/cache')->reason());
        self::assertSame([false], self::networkFailures($batch));
    }

    public function testTheDeadlineBetweenTwoRepositoriesLeavesTheSecondNotAsked(): void
    {
        $first = $this->api(['doctrine/cache' => []]);
        $second = $this->api(['doctrine/cache' => []]);
        $third = $this->api(['doctrine/cache' => []]);
        $calls = 0;
        $now = static function () use (&$calls): float {
            return ++$calls <= 2 ? 0.0 : 10.0;
        };

        $batch = (new RepositoryAdvisoryLoader(array_merge($first->repositories(), $second->repositories(), $third->repositories()), false, Deadline::inSeconds(1.0, $now)))->load(['doctrine/cache' => '2.2.0']);

        self::assertSame([[AdvisoryCoverage::ANSWERED, null], [AdvisoryCoverage::NOT_ASKED, AdvisoryCoverage::INSTALL_TIME_BUDGET], [AdvisoryCoverage::NOT_ASKED, AdvisoryCoverage::INSTALL_TIME_BUDGET]], self::outcomes($batch));
        self::assertSame([], $third->advisoryRequests());
        self::assertCount(1, self::nameCoverage($batch, 'doctrine/cache')->feeds(), 'only the feed reached before the deadline');
        self::assertSame(AdvisoryCoverage::INSTALL_TIME_BUDGET, self::nameCoverage($batch, 'doctrine/cache')->reason());
        self::assertSame([], $second->advisoryRequests());
        self::assertSame([self::BUDGET_NOTE], self::texts($batch));
        self::assertFalse($batch->complete());
    }

    /** @return array{AdvisoryProviderInterface&\Composer\Repository\RepositoryInterface, \ArrayObject<int, string>} */
    private static function spy(bool $hasFeed = true): array
    {
        /** @var \ArrayObject<int, string> $calls */
        $calls = new \ArrayObject();
        $repository = new class ($calls, $hasFeed) extends ArrayRepository implements AdvisoryProviderInterface {
            /** @var \ArrayObject<int, string> */
            private \ArrayObject $calls;
            private bool $hasFeed;

            /** @param \ArrayObject<int, string> $calls */
            public function __construct(\ArrayObject $calls, bool $hasFeed)
            {
                parent::__construct();
                $this->calls = $calls;
                $this->hasFeed = $hasFeed;
            }

            public function getRepoName(): string
            {
                return 'spy repo (https://user:secret@spy.example/?token=x)';
            }

            public function hasSecurityAdvisories(): bool
            {
                $this->calls->append('hasSecurityAdvisories');

                return $this->hasFeed;
            }

            public function getSecurityAdvisories(array $packageConstraintMap, bool $allowPartialAdvisories = false): array
            {
                $this->calls->append('getSecurityAdvisories');

                return ['namesFound' => [], 'advisories' => []];
            }
        };

        return [$repository, $calls];
    }

    public function testOfflineAsksNoRepositoryAndRecordsEachAsNotAsked(): void
    {
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            self::markTestSkipped('Composer without the advisory API');
        }
        [$spy, $calls] = self::spy();

        [$other] = self::spy();
        $batch = (new RepositoryAdvisoryLoader([$spy, $other], true, null, AdvisoryIgnore::unreadable('bad')))->load(['doctrine/cache' => '2.2.0']);

        self::assertSame([], $calls->getArrayCopy(), 'not even hasSecurityAdvisories()');
        self::assertSame([[AdvisoryCoverage::NOT_ASKED, AdvisoryCoverage::OFFLINE], [AdvisoryCoverage::NOT_ASKED, AdvisoryCoverage::OFFLINE]], self::outcomes($batch));
        self::assertSame([RunNote::ADVISORY_IGNORE_UNREADABLE, RunNote::ADVISORIES_NOT_CHECKED], array_map(static fn (RunNote $note): string => $note->code(), $batch->notes()));
        self::assertSame('spy repo (https://spy.example/)', $batch->coverage()->repositories()[0]['composer_repository'], 'credentials stripped');
        self::assertSame([], self::nameCoverage($batch, 'doctrine/cache')->feeds());
        self::assertSame(AdvisoryCoverage::OFFLINE, self::nameCoverage($batch, 'doctrine/cache')->reason());
        self::assertNull(self::nameCoverage($batch, 'doctrine/cache')->records());
        self::assertFalse($batch->complete());
    }

    /** Composer's policy stops blocking, never `composer audit`: lockrot still asks and counts. */
    public function testAPolicyThatTurnsComposersAdvisoryChecksOffStillAsksAndNotesTheKey(): void
    {
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            self::markTestSkipped('Composer without the advisory API');
        }
        [$spy, $calls] = self::spy();
        $ignore = AdvisoryIgnore::disabled('policy.advisories.audit', 'ignore');

        $batch = (new RepositoryAdvisoryLoader([$spy], false, null, $ignore))->load(['doctrine/cache' => '2.2.0']);

        self::assertSame(['hasSecurityAdvisories', 'getSecurityAdvisories'], $calls->getArrayCopy());
        self::assertSame([[AdvisoryCoverage::ANSWERED, null]], self::outcomes($batch));
        self::assertNull(self::nameCoverage($batch, 'doctrine/cache')->reason());
        self::assertSame([RunNote::ADVISORIES_DISABLED_BY_POLICY], array_map(static fn (RunNote $note): string => $note->code(), $batch->notes()));
        self::assertSame(['policy_key' => 'policy.advisories.audit', 'value' => 'ignore'], $batch->notes()[0]->data());
        self::assertSame([false], self::networkFailures($batch));
    }

    public function testNoRepositoryWithAFeedIsNoFeedAndAVcsRepositoryIsCountedNeverNamed(): void
    {
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            self::markTestSkipped('Composer without the advisory API');
        }
        [$withoutFeed] = self::spy(false);
        $vcs = new class () extends ArrayRepository {
            public function getRepoName(): string
            {
                throw new \LogicException('a vcs repository is never named: its driver can start network I/O');
            }
        };

        $batch = (new RepositoryAdvisoryLoader([$vcs, $withoutFeed]))->load(['doctrine/cache' => '2.2.0']);

        self::assertSame(1, $batch->coverage()->otherRepositories());
        self::assertSame([[AdvisoryCoverage::NO_FEED, null]], self::outcomes($batch));
        self::assertSame(AdvisoryCoverage::NO_FEED, self::nameCoverage($batch, 'doctrine/cache')->reason());
        self::assertSame([], self::nameCoverage($batch, 'doctrine/cache')->feeds());
        self::assertFalse($batch->complete());

        $none = (new RepositoryAdvisoryLoader([$vcs]))->load(['doctrine/cache' => '2.2.0']);
        self::assertSame([], $none->coverage()->repositories());
        self::assertSame(AdvisoryCoverage::NO_FEED, self::nameCoverage($none, 'doctrine/cache')->reason());
    }

    public function testBranchSnapshotsAreAttributedAsComposerAttributesThem(): void
    {
        $server = $this->api(['acme/core' => [self::record('PKSA-core', '>=4.3.0,<4.4.13', []), self::record('PKSA-main', '<5.0', [])]]);

        $alias = (new RepositoryAdvisoryLoader($server->repositories()))->load(['acme/core' => '4.3.x-dev']);
        $main = (new RepositoryAdvisoryLoader($server->repositories()))->load(['acme/core' => 'dev-main']);

        self::assertSame(['PKSA-core', 'PKSA-main'], array_map(static fn (Advisory $a): string => $a->id(), $alias->for('acme/core')), 'a branch alias reads as the highest version of its line');
        self::assertSame([], $main->for('acme/core'), 'Composer matches a dev branch against no numeric range');
        self::assertSame(2, self::nameCoverage($main, 'acme/core')->records());
    }

    public function testAnAliasVersionIsAttributedAsComposerAttributesIt(): void
    {
        $server = $this->api(['acme/core' => [self::record('PKSA-core', '>=4.3.0,<4.4.13', [])]]);

        $batch = (new RepositoryAdvisoryLoader($server->repositories()))->load(['acme/core' => 'dev-main'], [], ['acme/core' => ['4.3.9999999.9999999-dev']]);

        self::assertSame(['PKSA-core'], array_map(static fn (Advisory $a): string => $a->id(), $batch->for('acme/core')), 'dev-main aliased 4.3.x-dev reads as its alias too');
    }

    public function testEachRepositorysRecordIsAttributedByItsOwnRange(): void
    {
        $first = $this->api(['doctrine/cache' => [self::record('PKSA-shared', '<1.0', [])]]);
        $second = $this->api(['doctrine/cache' => [self::record('PKSA-shared', '>=2.0,<2.3', [])]]);

        $batch = (new RepositoryAdvisoryLoader(array_merge($first->repositories(), $second->repositories())))->load(['doctrine/cache' => '2.2.0']);

        self::assertSame(['PKSA-shared'], array_map(static fn (Advisory $a): string => $a->id(), $batch->for('doctrine/cache')), 'the second repository\'s range affects 2.2.0');
        self::assertTrue($batch->for('doctrine/cache')[0]->affects('2.2.0.0'));
    }

    /** Composer judges each repository's record of an id on its own: a record no rule ignores counts. */
    public function testARecordThatNoRuleIgnoresCountsWhateverAnotherRepositorysRecordSays(): void
    {
        $first = $this->api(['doctrine/cache' => [self::record('PKSA-shared', '>=2.0,<2.3', ['cve' => 'CVE-2024-0009'])]]);
        $second = $this->api(['doctrine/cache' => [self::record('PKSA-shared', '>=2.0,<2.3', [])]]);
        $ignore = AdvisoryIgnore::fromRaw(['CVE-2024-0009' => 'reviewed'], []);

        $batch = (new RepositoryAdvisoryLoader(array_merge($first->repositories(), $second->repositories()), false, null, $ignore))->load(['doctrine/cache' => '2.2.0']);
        $both = (new RepositoryAdvisoryLoader($first->repositories(), false, null, $ignore))->load(['doctrine/cache' => '2.2.0']);

        self::assertSame(['PKSA-shared'], array_map(static fn (Advisory $a): string => $a->id(), $batch->for('doctrine/cache')));
        self::assertSame([], $batch->ignored('doctrine/cache'));
        self::assertSame(['PKSA-shared'], array_map(static fn (Advisory $a): string => $a->id(), $batch->every('doctrine/cache')), 'every() keeps an id that one record keeps');
        self::assertSame([], $both->for('doctrine/cache'), 'the control: its only record is ignored');
        self::assertCount(1, $both->ignored('doctrine/cache'));

        $other = $this->api(['doctrine/cache' => [self::record('PKSA-shared', '>=2.0,<2.3', ['cve' => 'CVE-2024-0010'])]]);
        $twice = (new RepositoryAdvisoryLoader(array_merge($first->repositories(), $other->repositories()), false, null, AdvisoryIgnore::fromRaw(['CVE-2024-0009' => 'first', 'CVE-2024-0010' => 'second'], [])))->load(['doctrine/cache' => '2.2.0']);
        self::assertSame(['first'], array_map(static fn (IgnoredAdvisory $i): ?string => $i->match()->reason(), $twice->ignored('doctrine/cache')), 'both records ignored: the first one is kept');
    }

    public function testAFilteredRepositoryIsNamedOnlyWhenItWrapsAnAdvisoryCapableOne(): void
    {
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            self::markTestSkipped('Composer without the advisory API');
        }
        $vcs = new class () extends ArrayRepository {
            public function getRepoName(): string
            {
                throw new \LogicException('a vcs repository is never named: its driver can start network I/O');
            }
        };
        [$feed] = self::spy();

        $batch = (new RepositoryAdvisoryLoader([new FilterRepository($vcs, ['only' => ['acme/*']]), new FilterRepository($feed, ['only' => ['acme/*']])]))->load(['acme/pkg' => '1.0.0']);

        self::assertSame(1, $batch->coverage()->otherRepositories());
        self::assertSame([[AdvisoryCoverage::ANSWERED, null]], self::outcomes($batch));
    }

    public function testARepositoryWithoutAFeedLeavesTheLookupComplete(): void
    {
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            self::markTestSkipped('Composer without the advisory API');
        }
        [$withoutFeed] = self::spy(false);
        $answering = $this->api(['doctrine/cache' => []]);

        $batch = (new RepositoryAdvisoryLoader(array_merge([$withoutFeed], $answering->repositories())))->load(['doctrine/cache' => '2.2.0']);

        self::assertTrue($batch->complete());
        self::assertSame([[AdvisoryCoverage::NO_FEED, null], [AdvisoryCoverage::ANSWERED, null]], self::outcomes($batch));
        self::assertNull(self::nameCoverage($batch, 'doctrine/cache')->reason());
    }

    public function testAVcsOnlyLockUnderTheComposerRepositoriesScopeHasANotAskedFeedPerRepository(): void
    {
        $server = $this->api(['acme/fork' => []]);

        $batch = (new RepositoryAdvisoryLoader($server->repositories(), false, null, null, AdvisoryCoverage::SCOPE_COMPOSER_REPOSITORIES, AdvisoryCoverage::SOURCE_CONFIG))->load(['acme/fork' => '1.0.0'], ['acme/fork']);

        self::assertSame([], $server->advisoryRequests());
        self::assertSame([['composer_repository' => $server->repositories()[0]->getRepoName(), 'answer' => AdvisoryCoverage::NOT_ASKED, 'reason' => AdvisoryCoverage::NOT_FROM_COMPOSER_REPOSITORY, 'message' => null, 'records' => null]], self::nameCoverage($batch, 'acme/fork')->feeds());
    }

    public function testNoNameToAskKeepsTheConfiguredScope(): void
    {
        $batch = (new RepositoryAdvisoryLoader([], false, null, null, AdvisoryCoverage::SCOPE_COMPOSER_REPOSITORIES, AdvisoryCoverage::SOURCE_CONFIG))->load([]);

        self::assertSame([AdvisoryCoverage::SCOPE_COMPOSER_REPOSITORIES, AdvisoryCoverage::SOURCE_CONFIG], [$batch->coverage()->scope(), $batch->coverage()->scopeSource()]);
        self::assertSame([[], 0], [$batch->coverage()->repositories(), $batch->coverage()->otherRepositories()]);
    }

    public function testAnUnparseableVersionIsAskedButNotAttributed(): void
    {
        $server = $this->api(['doctrine/cache' => [self::record('PKSA-cache-1', '*', [])]]);

        $batch = (new RepositoryAdvisoryLoader($server->repositories()))->load(['doctrine/cache' => 'not a version']);

        self::assertSame([['doctrine/cache']], $server->advisoryRequests());
        self::assertSame([], $batch->for('doctrine/cache'));
        self::assertSame([], $batch->ignored('doctrine/cache'));
        self::assertSame(AdvisoryCoverage::UNPARSEABLE_VERSION, self::nameCoverage($batch, 'doctrine/cache')->reason());
        self::assertSame(['answer' => AdvisoryCoverage::ANSWERED, 'reason' => null, 'records' => 1], self::feed($batch, 'doctrine/cache', 0));
    }

    public function testTheComposerRepositoriesScopeAsksOnlyNamesFromAComposerRepository(): void
    {
        $server = $this->api(['acme/fork' => [self::record('PKSA-fork', '*', [])], 'doctrine/cache' => []]);
        $versions = ['doctrine/cache' => '2.2.0', 'acme/fork' => '1.0.0'];

        $scoped = (new RepositoryAdvisoryLoader($server->repositories(), false, null, null, AdvisoryCoverage::SCOPE_COMPOSER_REPOSITORIES, AdvisoryCoverage::SOURCE_CONFIG))->load($versions, ['acme/fork']);

        self::assertSame([['doctrine/cache']], $server->advisoryRequests());
        self::assertSame([], $scoped->for('acme/fork'));
        self::assertSame([['composer_repository' => $server->repositories()[0]->getRepoName(), 'answer' => AdvisoryCoverage::NOT_ASKED, 'reason' => AdvisoryCoverage::NOT_FROM_COMPOSER_REPOSITORY, 'message' => null, 'records' => null]], self::nameCoverage($scoped, 'acme/fork')->feeds());
        self::assertSame(AdvisoryCoverage::NOT_FROM_COMPOSER_REPOSITORY, self::nameCoverage($scoped, 'acme/fork')->reason());
        self::assertNull(self::nameCoverage($scoped, 'acme/fork')->records());
        self::assertSame([AdvisoryCoverage::SCOPE_COMPOSER_REPOSITORIES, AdvisoryCoverage::SOURCE_CONFIG], [$scoped->coverage()->scope(), $scoped->coverage()->scopeSource()]);
        self::assertNull(self::nameCoverage($scoped, 'doctrine/cache')->reason());

        $all = (new RepositoryAdvisoryLoader($server->repositories()))->load($versions, ['acme/fork']);

        self::assertSame(['PKSA-fork'], array_map(static fn (Advisory $a): string => $a->id(), $all->for('acme/fork')), 'the default scope asks a package from vcs by name');
        self::assertNull(self::nameCoverage($all, 'acme/fork')->reason());
    }

    /** @return list<string> */
    private static function texts(AdvisoryBatch $batch): array
    {
        return array_map(static fn (RunNote $note): string => $note->text(), $batch->notes());
    }

    /** @return list<bool> each note's `sets_network_failures`, in order */
    private static function networkFailures(AdvisoryBatch $batch): array
    {
        return array_map(static fn (RunNote $note): bool => $note->setsNetworkFailures(), $batch->notes());
    }
}
