<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Composer\Repository\AdvisoryProviderInterface;
use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Clock;
use Lockrot\Data\Advisory\RepositoryAdvisoryLoader;
use Lockrot\Data\Forge\ActivityClient;
use Lockrot\Data\Forge\ActivityFetchPlanner;
use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\RepoLocator;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\RepositoryMetadataLoader;
use Lockrot\Lock\LockedPackage;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\FakeHttpClient;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Verdict\VerdictEngine;
use PHPUnit\Framework\TestCase;

/**
 * The one advisory lookup of a run, counted on the recorded wallabag fixture: one POST per
 * advisory-capable repository, every package the run checks by name (Q7), packages-dev only with
 * `--dev` (O14). Re-record the answer with `bin/record-fixtures tests/fixtures/apps/wallabag_wallabag`.
 */
final class AdvisoryLookupTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../fixtures/apps/wallabag_wallabag';
    private const NOW = '2026-09-14T00:00:00+00:00';

    private ?FixtureRepositoryServer $server = null;

    protected function setUp(): void
    {
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            self::markTestSkipped('Composer without the advisory API');
        }
        $this->server = FixtureRepositoryServer::fromLockFiles([self::FIXTURE.'/composer.lock']);
        $this->server->withRecordedAdvisoryApi(self::FIXTURE);
        $this->server->start();
    }

    protected function tearDown(): void
    {
        if ($this->server !== null) {
            $this->server->stop();
            $this->server = null;
        }
    }

    /** @return list<list<string>> the names of each advisory POST of one run */
    private function lookups(bool $dev): array
    {
        $server = $this->server;
        self::assertNotNull($server);
        $clock = Clock::fixed(self::NOW);
        $auth = ForgeAuth::withTokens(new Tokens('recorded', null));
        $analyzer = new Analyzer(
            new RepositoryMetadataLoader($server->repositories(), $clock),
            new ActivityClient(new FakeHttpClient(), $auth),
            new ActivityFetchPlanner($auth),
            new RepoLocator(),
            BuiltinAllowlist::load(),
            SignalSet::default($clock, new Thresholds(), '8.4', PhpReleaseDates::load()),
            new VerdictEngine(),
            $clock,
            false,
            new RepositoryAdvisoryLoader($server->repositories())
        );
        $before = \count($server->advisoryRequests());

        $analyzer->analyze(LockFile::fromFile(self::FIXTURE.'/composer.lock'), ProjectConfig::fromFile(self::FIXTURE.'/composer.json'), $dev);

        return \array_slice($server->advisoryRequests(), $before);
    }

    /** @return list<string> */
    private static function names(bool $dev): array
    {
        $names = array_map(static fn (LockedPackage $package): string => $package->name(), LockFile::fromFile(self::FIXTURE.'/composer.lock')->packages($dev));
        sort($names);

        return $names;
    }

    public function testOneRequestAsksEveryProductionPackageAndNoDevOne(): void
    {
        $requests = $this->lookups(false);

        self::assertCount(1, $requests, 'one POST per advisory-capable repository for the whole run');
        $asked = $requests[0];
        sort($asked);
        self::assertSame(self::names(false), $asked);
        self::assertSame([], array_values(array_intersect(array_diff(self::names(true), self::names(false)), $asked)), 'no packages-dev name without --dev');
    }

    public function testWithDevTheOneRequestAsksEveryDevPackageToo(): void
    {
        $requests = $this->lookups(true);

        self::assertCount(1, $requests);
        $asked = $requests[0];
        sort($asked);
        self::assertNotSame(self::names(false), self::names(true), 'the fixture has packages-dev');
        self::assertSame(self::names(true), $asked);
    }
}
