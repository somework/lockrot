<?php

declare(strict_types=1);

// Writes the github, gitlab, sarif, markdown and table output of the fixture locks that
// ChangedGoldensTest pins, at --fail-on=high, built as AcceptanceTest::analyze() builds a report:
// recorded answers, no network. Run it at the base and at the head of a PR, then diff the two
// output directories: each changed file gets a row in ChangedGoldensTest::CHANGED.
// The script keeps to an API that both sides share, so it runs on a base checkout: set LOCKROT_ROOT.

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
use Lockrot\Output\FormatContext;
use Lockrot\Output\Formatters;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\FixtureRepositoryServer;

const DIRS = ['apps/wallabag_wallabag', 'apps/nextcloud_3rdparty', 'skeletons/laravel', 'apps/matomo-org_matomo'];
const FORMATS = ['github' => 'txt', 'gitlab' => 'json', 'sarif' => 'sarif', 'markdown' => 'md', 'table' => 'txt'];
const NOW = '2026-09-14T00:00:00+00:00';

$root = getenv('LOCKROT_ROOT');
$root = \is_string($root) && $root !== '' ? rtrim($root, '/') : \dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

$out = $argv[1] ?? null;
if (!\is_string($out) || $out === '') {
    fwrite(\STDERR, "usage: php tools/fixtures/dump-app-formats.php <out-dir>\n");
    exit(2);
}
if (!is_dir($out) && !mkdir($out, 0777, true) && !is_dir($out)) {
    fwrite(\STDERR, "cannot create {$out}\n");
    exit(2);
}

$fixtures = $root.'/tests/fixtures/';
$server = FixtureRepositoryServer::fromLockFiles(array_map(static fn (string $dir): string => $fixtures.$dir.'/composer.lock', DIRS));
$server->start();
try {
    $clock = Clock::fixed(NOW);
    foreach (DIRS as $dir) {
        $auth = ForgeAuth::withTokens(new Tokens('recorded', null));
        $analyzer = new Analyzer(
            new RepositoryMetadataLoader($server->repositories(), $clock),
            new ActivityClient(new RecordedHttpClient($fixtures.'http/github'), $auth),
            new ActivityFetchPlanner($auth),
            new RepoLocator(),
            BuiltinAllowlist::load(),
            SignalSet::default($clock, new Thresholds(), '8.4', PhpReleaseDates::load()),
            $clock,
            false
        );
        $report = $analyzer->analyze(LockFile::fromFile($fixtures.$dir.'/composer.lock'), ProjectConfig::fromFile($fixtures.$dir.'/composer.json'), false);
        foreach (FORMATS as $format => $extension) {
            $text = Formatters::for($format, FormatContext::create(null, 'high'))->format($report);
            $path = $out.'/'.basename($dir).'.'.$format.'.'.$extension;
            if (file_put_contents($path, $text) !== \strlen($text)) {
                fwrite(\STDERR, "cannot write {$path}\n");
                exit(1);
            }
        }
        fwrite(\STDOUT, basename($dir)."\n");
    }
} finally {
    $server->stop();
}
