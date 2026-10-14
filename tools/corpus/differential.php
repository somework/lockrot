#!/usr/bin/env php
<?php

declare(strict_types=1);
// The corpus differential: lockrot's report-2 on the apps of tests/fixtures/apps against the model's report-2 for the same
// locks.
//
//   php tools/corpus/differential.php                         runs bin/lockrot on each app and diffs it against the model
//   php tools/corpus/differential.php --write <dir>           also writes lockrot's documents into <dir>, with the masks of
//                                                            tools/report/record (the tool version, the server port)
//   php tools/corpus/differential.php --model-input <phar> <dir>
//                                                            runs a 0.13 PHAR the same way and writes each app's html data
//                                                            block, the model's input, into <dir>
//
// Every run reads only tests/fixtures/corpus/cache/ (tools/report/record --corpus) through Lockrot\Tests\Support\FixtureCliRun,
// at CLOCK, the recording day of the cache. The model documents must carry the same clock. A difference that
// tests/fixtures/corpus/model-divergences.json does not allow fails the run, and so does an allowed difference that no longer
// occurs.
require __DIR__.'/../../vendor/autoload.php';

use Lockrot\Tests\Support\FixtureCliRun;
use Lockrot\Tests\Support\VersionMask;

const ROOT = __DIR__.'/../..';
const APPS = ROOT.'/tests/fixtures/apps';
const CACHE = ROOT.'/tests/fixtures/corpus/cache';
const MODEL = ROOT.'/tests/fixtures/corpus/model-report-2';
const DIVERGENCES = ROOT.'/tests/fixtures/corpus/model-divergences.json';
const OPTIONS = ['--target-php=8.4', '--all'];
const CLOCK = '2026-10-14T00:00:00+00:00';
/** The fields of a finding that the differential compares. Beside them: each flag's FLAG_FIELDS as `flags.<id>.<key>`, and each key of each S9 row's `fix` as `S9.fix.<key>[<advisory id>]`. */
const FIELDS = [
    'verdict', 'lead', 'installed_php', 'checks_skipped', 'age', 'activity', 'gate.basis',
    'score.terms', 'score.modifiers', 'score.without', 'score.accepted',
    'next_step.kind', 'next_step.constraint', 'next_step.version', 'next_step.to_branch', 'next_step.clears', 'next_step.leaves',
    'next_step.adds', 'next_step.unverified', 'next_step.php_check', 'next_step.commands', 'next_step.crosses_major',
    'next_step.composer_moves_because', 'next_step.if_applied', 'next_step.held_by',
    'security.fix_kind', 'security.gets', 'security.partial', 'security.move_in', 'security.installed_branch_fixes',
];
/** The fields of each flag, by flag id. */
const FLAG_FIELDS = ['role', 'headline', 'degree'];

$argv = $_SERVER['argv'];
$apps = array_map('basename', glob(APPS.'/*', GLOB_ONLYDIR) ?: []);
$today = CLOCK;

if (($argv[1] ?? null) === '--model-input') {
    [$phar, $out] = [$argv[2] ?? '', $argv[3] ?? ''];
    if (!is_file($phar) || $out === '') {
        fwrite(STDERR, "usage: differential.php --model-input <phar> <dir>\n");
        exit(2);
    }
    @mkdir($out, 0777, true);
    foreach ($apps as $app) {
        $html = run($phar, $app, $today, array_merge(OPTIONS, ['--format=html']));
        if (preg_match('{<script id="lockrot-data" type="application/json">(.*?)</script>}s', $html, $match) !== 1) {
            fwrite(STDERR, "differential: no data block in the page of $app\n");
            exit(1);
        }
        file_put_contents($out.'/'.$app.'.json', json_encode(['data' => json_decode($match[1], true)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        fwrite(STDOUT, "differential: $app\n");
    }
    exit(0);
}

assertModelClock();
$write = ($argv[1] ?? null) === '--write' ? ($argv[2] ?? '') : null;
$allowed = json_decode((string) file_get_contents(DIVERGENCES), true)['allowed'];
$used = array_fill(0, count($allowed), 0);
$unreviewed = [];
$byPath = [];
$counts = ['lockrot' => ['vulnerable' => 0, 'critical' => 0], 'model' => ['vulnerable' => 0, 'critical' => 0]];
foreach ($apps as $app) {
    $json = run(ROOT.'/bin/lockrot', $app, $today, array_merge(OPTIONS, ['--format=json', '--fail-on=high']));
    if ($write !== null) {
        @mkdir($write, 0777, true);
        file_put_contents($write.'/'.$app.'.json', preg_replace('{http://127\.0\.0\.1:\d+}', 'http://127.0.0.1', VersionMask::apply($json)));
    }
    $ours = findings(json_decode($json, true));
    $theirs = findings(json_decode((string) file_get_contents(MODEL.'/'.$app.'.json'), true));
    foreach (['lockrot' => $ours, 'model' => $theirs] as $side => $findings) {
        foreach ($findings as $finding) {
            $counts[$side]['vulnerable'] += in_array('vulnerable', array_column($finding['flags'], 'id'), true) ? 1 : 0;
            $counts[$side]['critical'] += $finding['verdict'] === 'critical' ? 1 : 0;
        }
    }
    foreach (array_unique(array_merge(array_keys($ours), array_keys($theirs))) as $package) {
        $differences = isset($ours[$package], $theirs[$package])
            ? differences(values($ours[$package]), values($theirs[$package]))
            : ['finding' => [isset($ours[$package]), isset($theirs[$package])]];
        foreach ($differences as $path => [$lockrot, $model]) {
            $field = (string) preg_replace('{\[.*\]$}', '', $path);
            $byPath[$field] = ($byPath[$field] ?? 0) + 1;
            $index = allowedBy($allowed, $app, $package, $path, $lockrot, $model);
            if ($index === null) {
                $unreviewed[] = compact('app', 'package', 'path', 'lockrot', 'model');
            } else {
                ++$used[$index];
            }
        }
    }
}

ksort($byPath);
fwrite(STDOUT, "differences by field (allowed and not):\n");
foreach ($byPath as $path => $count) {
    fwrite(STDOUT, sprintf("  %-40s %d\n", $path, $count));
}
fwrite(STDOUT, sprintf("vulnerable: lockrot %d, model %d; critical: lockrot %d, model %d\n", $counts['lockrot']['vulnerable'], $counts['model']['vulnerable'], $counts['lockrot']['critical'], $counts['model']['critical']));
$stale = array_keys(array_filter($used, static fn (int $count): bool => $count === 0));
foreach ($stale as $index) {
    fwrite(STDERR, 'differential: an allowed divergence no longer occurs: '.json_encode($allowed[$index], JSON_UNESCAPED_SLASHES)."\n");
}
foreach ($unreviewed as $row) {
    fwrite(STDERR, 'differential: '.json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
}
fwrite(STDOUT, sprintf("differential: %d unreviewed, %d allowed divergences unused\n", count($unreviewed), count($stale)));
exit($unreviewed === [] && $stale === [] ? 0 : 1);

function assertModelClock(): void
{
    $clocks = array_unique(array_values(json_decode((string) file_get_contents(MODEL.'/provenance.json'), true)['clock']));
    if ($clocks !== [CLOCK]) {
        fwrite(STDERR, 'differential: the model documents read the clock '.implode(', ', $clocks).', not '.CLOCK."\n");
        exit(2);
    }
}

/** @param list<string> $arguments */
function run(string $entry, string $app, string $today, array $arguments): string
{
    $run = FixtureCliRun::forRecording(APPS.'/'.$app, CACHE);
    try {
        $process = $run->run($entry, APPS.'/'.$app, $today, $arguments);
    } finally {
        $run->stop();
    }
    // Exit 1 is a gate that fails. Exit 2 is a run that did not finish.
    if (!in_array($process->getExitCode(), [0, 1], true)) {
        fwrite(STDERR, "differential: $entry on $app exited ".$process->getExitCode()."\n".$process->getErrorOutput());
        exit(1);
    }

    return $process->getOutput();
}

/**
 * @param mixed $document
 *
 * @return array<string, array<string, mixed>> the findings by package
 */
function findings($document): array
{
    $byPackage = [];
    foreach (is_array($document) && is_array($document['findings'] ?? null) ? $document['findings'] : [] as $finding) {
        $byPackage[(string) $finding['package']] = $finding;
    }

    return $byPackage;
}

/**
 * The compared values of one finding, by path.
 *
 * @param array<string, mixed> $finding
 *
 * @return array<string, string> each value as canonical JSON
 */
function values(array $finding): array
{
    $values = [];
    foreach (FIELDS as $path) {
        $value = $finding;
        foreach (explode('.', $path) as $key) {
            $value = is_array($value) && array_key_exists($key, $value) ? $value[$key] : null;
        }
        $values[$path] = canonical($value);
    }
    foreach ($finding['flags'] as $flag) {
        foreach (FLAG_FIELDS as $key) {
            $values['flags.'.$flag['id'].'.'.$key] = canonical($flag[$key] ?? null);
        }
    }
    foreach ($finding['signals'] as $signal) {
        foreach ($signal['id'] === 'S9' ? $signal['data']['advisories'] : [] as $row) {
            foreach (is_array($row['fix'] ?? null) ? $row['fix'] : [] as $key => $fix) {
                $values['S9.fix.'.$key.'['.$row['id'].']'] = canonical($fix);
            }
        }
    }

    return $values;
}

/**
 * @param array<string, string> $ours
 * @param array<string, string> $theirs
 *
 * @return array<string, array{mixed, mixed}> lockrot's and the model's value of each path that differs, a path that one side
 *                                            lacks included
 */
function differences(array $ours, array $theirs): array
{
    $differences = [];
    foreach (array_unique(array_merge(array_keys($ours), array_keys($theirs))) as $path) {
        $left = $ours[$path] ?? 'absent';
        $right = $theirs[$path] ?? 'absent';
        if ($left !== $right) {
            $differences[$path] = [json_decode($left, true), json_decode($right, true)];
        }
    }

    return $differences;
}

/** @param mixed $value */
function canonical($value): string
{
    return (string) json_encode(normalised($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Objects with sorted keys, and a whole float as an integer: the model writes JSON from Python.
 *
 * @param mixed $value
 *
 * @return mixed
 */
function normalised($value)
{
    if (is_float($value) && floor($value) === $value) {
        return (int) $value;
    }
    if (!is_array($value)) {
        return $value;
    }
    $value = array_map('normalised', $value);
    if (array_keys($value) !== range(0, count($value) - 1)) {
        ksort($value);
    }

    return $value;
}

/**
 * The index of the first allowed divergence that matches. An entry matches on each key it has: `app`, `package`, `path` (a
 * path or a prefix that ends in `*`), `lockrot` and `model` (each side's value).
 *
 * @param list<array<string, mixed>> $allowed
 * @param mixed                      $lockrot
 * @param mixed                      $model
 */
function allowedBy(array $allowed, string $app, string $package, string $path, $lockrot, $model): ?int
{
    foreach ($allowed as $index => $entry) {
        $pattern = (string) ($entry['path'] ?? '*');
        $pathMatches = substr($pattern, -1) === '*' ? strpos($path, substr($pattern, 0, -1)) === 0 : $pattern === $path;
        if ($pathMatches
            && ($entry['app'] ?? $app) === $app
            && ($entry['package'] ?? $package) === $package
            && (!array_key_exists('lockrot', $entry) || canonical($entry['lockrot']) === canonical($lockrot))
            && (!array_key_exists('model', $entry) || canonical($entry['model']) === canonical($model))) {
            return $index;
        }
    }

    return null;
}
