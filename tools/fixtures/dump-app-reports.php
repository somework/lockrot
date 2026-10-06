<?php

declare(strict_types=1);

// Dumps one `--format=json` report per app in tests/fixtures/apps, built as AcceptanceTest::analyze()
// builds it: recorded answers, no network. Run it at the base and at the head of a PR, then diff the
// two output directories. LOCKROT_TODAY sets the clock.
// The script keeps to the API of v0.13.0, so it runs unchanged on a base checkout: copy it there, or
// set LOCKROT_ROOT to that checkout. `bin/lockrot --offline` is no substitute: with a cold Composer
// cache it dates nothing, so an empty diff proves nothing.

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
use Lockrot\Output\JsonFormatter;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Verdict\VerdictEngine;

const TARGET_PHP = '8.4';
const DEFAULT_TODAY = '2026-10-01T00:00:00+00:00';
const WALLABAG_YEARS_MIN = 60;

$root = getenv('LOCKROT_ROOT');
$root = \is_string($root) && $root !== '' ? rtrim($root, '/') : \dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

$out = $argv[1] ?? null;
if (!\is_string($out) || $out === '') {
    fwrite(\STDERR, "usage: php tools/fixtures/dump-app-reports.php <out-dir>\n");
    exit(2);
}
if (!is_dir($out) && !mkdir($out, 0777, true) && !is_dir($out)) {
    fwrite(\STDERR, "cannot create {$out}\n");
    exit(2);
}

$fixtures = $root.'/tests/fixtures';
$dirs = glob($fixtures.'/apps/*', \GLOB_ONLYDIR);
if ($dirs === false || $dirs === []) {
    fwrite(\STDERR, "no fixture apps under {$fixtures}/apps\n");
    exit(1);
}
$today = getenv('LOCKROT_TODAY');
$clock = Clock::fixed(\is_string($today) && $today !== '' ? $today : DEFAULT_TODAY);

$server = FixtureRepositoryServer::fromLockFiles(array_map(static fn (string $dir): string => $dir.'/composer.lock', $dirs));
$server->start();
$counts = [];
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
            false
        );
        $report = $analyzer->analyze(LockFile::fromFile($dir.'/composer.lock'), $project, false);
        $json = (new JsonFormatter())->format($report);
        $path = $out.'/'.basename($dir).'.json';
        if (file_put_contents($path, $json) !== \strlen($json)) {
            fwrite(\STDERR, "cannot write {$path}\n");
            exit(1);
        }

        $years = 0;
        foreach ($report->findings() as $finding) {
            foreach ($finding->signals() as $signal) {
                if (\array_key_exists('years', $signal->data())) {
                    ++$years;
                }
            }
        }
        $counts[basename($dir)] = $years;
        fwrite(\STDOUT, \sprintf("%-28s %4d findings, %4d signals with years\n", basename($dir), \count($report->findings()), $years));
    }
} finally {
    $server->stop();
}

if (($counts['wallabag_wallabag'] ?? 0) < WALLABAG_YEARS_MIN) {
    fwrite(\STDERR, \sprintf("wallabag carries %d signals with years, fewer than %d: the recorded data was not served\n", $counts['wallabag_wallabag'] ?? 0, WALLABAG_YEARS_MIN));
    exit(1);
}
