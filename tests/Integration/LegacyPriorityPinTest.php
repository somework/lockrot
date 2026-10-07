<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Clock;
use Lockrot\Data\Advisory\RepositoryAdvisoryLoader;
use Lockrot\Data\Forge\ActivityClient;
use Lockrot\Data\Forge\ActivityFetchPlanner;
use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\RepoLocator;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Data\Http\HttpResult;
use Lockrot\Data\Http\RecordedHttpClient;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\RepositoryMetadataLoader;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Verdict\VerdictEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Report-1's `priority` and `no_fix_expected` of every finding over the fixture apps, against the
 * values that v0.13.0 computed from the same recorded answers. The file is never rewritten from
 * this tree: `tools/fixtures/record-legacy-priority.php` records it on a v0.13.0 checkout.
 */
final class LegacyPriorityPinTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../fixtures';
    private const PINS = self::FIXTURES.'/legacy/priority-0.13.0.json';
    private const TARGET_PHP = '8.4';
    private const NOW = '2026-10-01T00:00:00+00:00';

    private static ?FixtureRepositoryServer $server = null;

    public static function setUpBeforeClass(): void
    {
        $locks = array_map(static fn (string $dir): string => $dir.'/composer.lock', self::dirs());
        self::$server = FixtureRepositoryServer::fromLockFiles($locks);
        $served = [];
        foreach ($locks as $lock) {
            foreach (LockFile::fromFile($lock)->packages(false) as $package) {
                $served[$package->name()] = true;
            }
        }
        self::$server->withSecurityAdvisories(array_intersect_key(self::recordedAdvisories(), $served));
        self::$server->start();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            self::$server->stop();
            self::$server = null;
        }
    }

    public function testThePinsCoverEveryFixtureApp(): void
    {
        self::assertSame(array_keys(self::pins()), array_map('basename', self::dirs()));
    }

    /** @return iterable<string, array{string}> */
    public static function apps(): iterable
    {
        foreach (self::dirs() as $dir) {
            yield basename($dir) => [$dir];
        }
    }

    /** @dataProvider apps */
    #[DataProvider('apps')]
    public function testEveryFindingKeepsItsPriorityAndItsNoFixList(string $dir): void
    {
        $server = self::$server;
        self::assertNotNull($server);
        $clock = Clock::fixed(self::NOW);
        $project = ProjectConfig::fromFile($dir.'/composer.json');
        $auth = ForgeAuth::withTokens(new Tokens('recorded', null));
        $analyzer = new Analyzer(
            new RepositoryMetadataLoader($server->repositories(), $clock),
            new ActivityClient(new RecordedHttpClient(self::FIXTURES.'/http/github'), $auth),
            new ActivityFetchPlanner($auth),
            new RepoLocator(),
            BuiltinAllowlist::load(),
            SignalSet::default($clock, new Thresholds(), self::TARGET_PHP, PhpReleaseDates::load(), $project->requirePhp()),
            new VerdictEngine(),
            $clock,
            false,
            new RepositoryAdvisoryLoader($server->repositories())
        );
        $actual = [];
        foreach ($analyzer->analyze(LockFile::fromFile($dir.'/composer.lock'), $project, false)->findings() as $finding) {
            $actual[$finding->package()] = ['priority' => $finding->priority(), 'no_fix_expected' => $finding->noFixExpected()];
        }
        ksort($actual);

        self::assertSame(self::pins()[basename($dir)] ?? null, $actual);
    }

    /** @return list<string> */
    private static function dirs(): array
    {
        $dirs = glob(self::FIXTURES.'/apps/*', \GLOB_ONLYDIR);
        self::assertIsArray($dirs);
        self::assertNotSame([], $dirs);
        sort($dirs);

        return $dirs;
    }

    /** @return array<mixed> */
    private static function pins(): array
    {
        $pins = json_decode((string) file_get_contents(self::PINS), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($pins);

        return $pins;
    }

    /** @return array<string, list<array<string, mixed>>> */
    private static function recordedAdvisories(): array
    {
        $raw = file_get_contents(self::FIXTURES.'/http/advisories/wallabag_wallabag.json');
        self::assertIsString($raw);
        $result = HttpResult::fromEnvelopeJson('https://packagist.org/api/security-advisories/', $raw);
        self::assertNotNull($result);
        $answer = json_decode((string) $result->body(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);
        self::assertIsArray($answer['advisories']);
        /** @var array<string, list<array<string, mixed>>> $advisories */
        $advisories = $answer['advisories'];

        return $advisories;
    }
}
