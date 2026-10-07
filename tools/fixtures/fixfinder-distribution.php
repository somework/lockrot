<?php

declare(strict_types=1);

// Runs Lockrot\Security\FixFinder on every counted advisory of every finding and prints JSON: per
// lock and in total, the count per fix class and the held_by counts per source and link. Hermetic:
// each lock directory is served from tests/fixtures/http as tools/fixtures/dump-app-reports.php
// serves it, its advisories from <dir>.json in tests/fixtures/http/advisories, or in
// LOCKROT_ADVISORY_RECORDINGS when set. A lock with no recording counts none. LOCKROT_TODAY sets the clock.
// Usage: php tools/fixtures/fixfinder-distribution.php [<lock dir> ...], default tests/fixtures/apps/*

use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Clock;
use Lockrot\Data\Advisory\RepositoryAdvisoryLoader;
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
use Lockrot\Security\Fix;
use Lockrot\Security\FixFinder;
use Lockrot\Security\LinkIndex;
use Lockrot\Signal\PhpFloor;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Verdict\VerdictEngine;

$root = \dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

const TARGET_PHP = '8.4';
const DEFAULT_TODAY = '2026-10-01T00:00:00+00:00';
const CLASSES = [Fix::UPDATE, Fix::UPGRADE, Fix::RAISE_PHP, Fix::BLOCKED, Fix::UNKNOWN, Fix::NONE];

$fixtures = $root.'/tests/fixtures';
$dirs = \array_slice($argv, 1);
if ($dirs === []) {
    $dirs = glob($fixtures.'/apps/*', \GLOB_ONLYDIR) ?: [];
}
sort($dirs);
if ($dirs === []) {
    fwrite(\STDERR, "no lock directory given and none under {$fixtures}/apps\n");
    exit(1);
}
$recordings = getenv('LOCKROT_ADVISORY_RECORDINGS');
$recordings = \is_string($recordings) && $recordings !== '' ? rtrim($recordings, '/') : $fixtures.'/http/advisories';
$today = getenv('LOCKROT_TODAY');
$clock = Clock::fixed(\is_string($today) && $today !== '' ? $today : DEFAULT_TODAY);

/** @return array<string, mixed> */
function emptyCounts(): array
{
    return [
        'vulnerable_packages' => 0,
        'advisories' => 0,
        'classes' => array_fill_keys(CLASSES, 0),
        'held_by' => ['root' => 0, 'package' => 0, 'require' => 0, 'require-dev' => 0, 'conflict' => 0, 'advisories_held' => 0],
        'branch_rows' => array_fill_keys([Fix::UPDATE, Fix::UPGRADE, Fix::RAISE_PHP, Fix::BLOCKED], 0),
    ];
}

/**
 * @param array<string, mixed> $into
 * @param array<string, mixed> $from
 *
 * @return array<string, mixed>
 */
function add(array $into, array $from): array
{
    foreach ($from as $key => $value) {
        $into[$key] = \is_array($value) ? add($into[$key], $value) : $into[$key] + $value;
    }

    return $into;
}

$result = ['target_php' => TARGET_PHP, 'today' => $clock->now()->format(\DATE_ATOM), 'locks' => [], 'total' => emptyCounts()];
foreach ($dirs as $dir) {
    $dir = rtrim($dir, '/');
    $recorded = $recordings.'/'.basename($dir).'.json';
    $server = FixtureRepositoryServer::fromLockFiles([$dir.'/composer.lock']);
    if (is_file($recorded)) {
        $server->withRecordedAdvisoryApi($dir, $recordings);
    }
    $server->start();
    try {
        $project = ProjectConfig::fromFile($dir.'/composer.json');
        $lock = LockFile::fromFile($dir.'/composer.lock');
        $auth = ForgeAuth::withTokens(new Tokens('recorded', null));
        $analyzer = new Analyzer(
            new RepositoryMetadataLoader($server->repositories(), $clock),
            new ActivityClient(new RecordedHttpClient($fixtures.'/http/github'), $auth),
            new ActivityFetchPlanner($auth),
            new RepoLocator(),
            BuiltinAllowlist::load(),
            SignalSet::default($clock, new Thresholds(), TARGET_PHP, PhpReleaseDates::load(), $project->requirePhp()),
            new VerdictEngine(),
            $clock,
            false,
            is_file($recorded) ? new RepositoryAdvisoryLoader($server->repositories()) : null
        );
        $analysis = $analyzer->analyzeWithFacts($lock->packages(false), $lock, $project, false);
    } finally {
        $server->stop();
    }

    $finder = new FixFinder(new PhpFloor(TARGET_PHP, $project->requirePhp()), LinkIndex::of($lock, $project));
    $counts = emptyCounts();
    foreach ($analysis->report()->findings() as $finding) {
        $facts = $analysis->facts($finding->package());
        if ($facts === null || $facts->advisories() === []) {
            continue;
        }
        $fixes = $finder->find($facts);
        ++$counts['vulnerable_packages'];
        foreach ($fixes->fixes() as $fix) {
            ++$counts['advisories'];
            ++$counts['classes'][$fix->kind()];
            if ($fix->heldBy() !== []) {
                ++$counts['held_by']['advisories_held'];
            }
            foreach ($fix->heldBy() as $holder) {
                ++$counts['held_by'][$holder->source()];
                ++$counts['held_by'][$holder->link()];
            }
        }
        foreach ($fixes->branches() as $row) {
            $candidate = $row->candidate();
            if ($candidate !== null) {
                ++$counts['branch_rows'][$candidate->kind()];
            }
        }
    }
    $result['locks'][basename($dir)] = ['advisories_recorded' => is_file($recorded)] + $counts;
    $result['total'] = add($result['total'], $counts);
}

echo json_encode($result, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n";
