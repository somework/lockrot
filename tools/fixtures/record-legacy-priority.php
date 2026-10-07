<?php

declare(strict_types=1);

// Writes tests/fixtures/legacy/priority-0.13.0.json: the priority and the no-fix list of every
// finding over tests/fixtures/apps, which LegacyPriorityPinTest compares with the head. Run it with
// LOCKROT_ROOT set to a v0.13.0 checkout: the code comes from there, every recorded answer from this
// tree. The run serves wallabag's recorded advisory answer to every app, as the test does. The
// script keeps to the API of v0.13.0.

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
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Verdict\VerdictEngine;

const TARGET_PHP = '8.4';
const NOW = '2026-10-01T00:00:00+00:00';

$root = getenv('LOCKROT_ROOT');
if (!\is_string($root) || $root === '') {
    fwrite(\STDERR, "set LOCKROT_ROOT to a v0.13.0 checkout\n");
    exit(2);
}
require rtrim($root, '/').'/vendor/autoload.php';

$here = \dirname(__DIR__, 2);
$fixtures = $here.'/tests/fixtures';
$dirs = glob($fixtures.'/apps/*', \GLOB_ONLYDIR);
if ($dirs === false || $dirs === []) {
    fwrite(\STDERR, "no fixture apps under {$fixtures}/apps\n");
    exit(1);
}
sort($dirs);
$envelope = json_decode((string) file_get_contents($fixtures.'/http/advisories/wallabag_wallabag.json'), true);
$answer = \is_array($envelope) && \is_string($envelope['body'] ?? null) ? json_decode($envelope['body'], true) : null;
if (!\is_array($answer) || !\is_array($answer['advisories'] ?? null)) {
    fwrite(\STDERR, "no recorded advisory answer for wallabag_wallabag\n");
    exit(1);
}

$locks = array_map(static fn (string $dir): string => $dir.'/composer.lock', $dirs);
$server = FixtureRepositoryServer::fromLockFiles($locks, $fixtures.'/http/p2');
$served = [];
foreach ($locks as $lock) {
    foreach (LockFile::fromFile($lock)->packages(false) as $package) {
        $served[$package->name()] = true;
    }
}
$server->withSecurityAdvisories(array_intersect_key($answer['advisories'], $served));
$server->start();
$clock = Clock::fixed(NOW);
$pins = [];
try {
    foreach ($dirs as $dir) {
        $project = ProjectConfig::fromFile($dir.'/composer.json');
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
            new RepositoryAdvisoryLoader($server->repositories())
        );
        $app = [];
        foreach ($analyzer->analyze(LockFile::fromFile($dir.'/composer.lock'), $project, false)->findings() as $finding) {
            $app[$finding->package()] = ['priority' => $finding->priority(), 'no_fix_expected' => $finding->noFixExpected()];
        }
        ksort($app);
        $pins[basename($dir)] = $app;
    }
} finally {
    $server->stop();
}

$out = $fixtures.'/legacy/priority-0.13.0.json';
if (!is_dir(\dirname($out)) && !mkdir(\dirname($out), 0777, true) && !is_dir(\dirname($out))) {
    fwrite(\STDERR, 'cannot create '.\dirname($out)."\n");
    exit(1);
}
$json = json_encode($pins, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n";
if (file_put_contents($out, $json) !== \strlen($json)) {
    fwrite(\STDERR, "cannot write {$out}\n");
    exit(1);
}
fwrite(\STDOUT, \sprintf("%d apps, %d findings\n", \count($pins), array_sum(array_map('count', $pins))));
