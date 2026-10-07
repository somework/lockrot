<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Clock;
use Lockrot\Data\Forge\ActivityClient;
use Lockrot\Data\Forge\ActivityFetchPlanner;
use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\RepoLocator;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Data\Http\RecordedHttpClient;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\RepositoryMetadataLoader;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Signal\BranchRow;
use Lockrot\Signal\PhpFloor;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Verdict\VerdictEngine;
use PHPUnit\Framework\TestCase;

/**
 * The floor point moves what a branch row admits only at the edge. Over the fixture apps, no row's
 * `admits_project_php` or `misses_project_php` moves from the recorded snapshot
 * tests/fixtures/corpus/floor-point-before.json. The pull request that adds this test gives the
 * command that recorded it: https://github.com/somework/lockrot/pull/72.
 */
final class FloorPointCorpusTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../fixtures/';
    private const BEFORE = self::FIXTURES.'corpus/floor-point-before.json';
    private const NOW = '2026-10-01T00:00:00+00:00';
    private const TARGET_PHP = '8.4';

    public function testTheFloorPointMovesNoBranchRowOfTheFixtureApps(): void
    {
        $before = json_decode((string) file_get_contents(self::BEFORE), true);
        self::assertIsArray($before);
        self::assertIsArray($before['rows'] ?? null);
        self::assertNotSame([], $before['rows']);

        $dirs = glob(self::FIXTURES.'apps/*', \GLOB_ONLYDIR);
        self::assertIsArray($dirs);
        self::assertNotSame([], $dirs);
        sort($dirs);
        $server = FixtureRepositoryServer::fromLockFiles(array_map(static fn (string $dir): string => $dir.'/composer.lock', $dirs));
        $server->start();
        $clock = Clock::fixed(self::NOW);
        $after = [];
        try {
            foreach ($dirs as $dir) {
                $project = ProjectConfig::fromFile($dir.'/composer.json');
                $floor = new PhpFloor(self::TARGET_PHP, $project->requirePhp());
                $lock = LockFile::fromFile($dir.'/composer.lock');
                $analysis = self::analyzer($server, $clock, $project)->analyzeWithFacts($lock->packages(false), $lock, $project, false);
                foreach ($analysis->report()->findings() as $finding) {
                    $facts = $analysis->facts($finding->package());
                    self::assertNotNull($facts);
                    foreach (BranchRow::all($facts, $floor, $clock) as $row) {
                        $after[] = [basename($dir), $finding->package(), $row->branch(), $row->admitsProjectPhp(), $row->missesProjectPhp()];
                    }
                }
            }
        } finally {
            $server->stop();
        }

        self::assertSame(self::keyed($before['rows']), self::keyed($after), 'a branch row moved: check the floor point rule in docs/verdicts.md#within-reach before you re-record the snapshot');
    }

    /**
     * @param array<mixed> $rows
     *
     * @return array<string, array{mixed, mixed}> app, package and branch => the two values
     */
    private static function keyed(array $rows): array
    {
        $keyed = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            [$app, $package, $branch, $admits, $misses] = $row;
            self::assertIsString($app);
            self::assertIsString($package);
            self::assertIsString($branch);
            $keyed[$app.' '.$package.' '.$branch] = [$admits, $misses];
        }
        ksort($keyed);

        return $keyed;
    }

    private static function analyzer(FixtureRepositoryServer $server, Clock $clock, ProjectConfig $project): Analyzer
    {
        $auth = ForgeAuth::withTokens(new Tokens('recorded', null));

        return new Analyzer(
            new RepositoryMetadataLoader($server->repositories(), $clock),
            new ActivityClient(new RecordedHttpClient(self::FIXTURES.'http/github'), $auth),
            new ActivityFetchPlanner($auth),
            new RepoLocator(),
            BuiltinAllowlist::load(),
            SignalSet::default($clock, new Thresholds(), self::TARGET_PHP, PhpReleaseDates::load(), $project->requirePhp()),
            new VerdictEngine(),
            $clock,
            false
        );
    }
}
