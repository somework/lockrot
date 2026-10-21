#!/usr/bin/env php
<?php

declare(strict_types=1);
// The corpus differential: lockrot's report-2 on the apps of tests/fixtures/apps against the model's report-2 for the same
// locks. It compares lockrot without the move check, because the model records no Composer check: ENTRY runs no check.
//
//   php tools/corpus/differential.php                         runs ENTRY on each run of runs() and diffs it against the
//                                                            model
//   php tools/corpus/differential.php --write <dir>           also writes lockrot's documents into <dir>, with the masks of
//                                                            tools/report/record (the tool version, the server port)
//   php tools/corpus/differential.php --model-input <phar> <dir>
//                                                            runs a 0.13 PHAR the same way and writes each run's html data
//                                                            block, the model's input, into <dir>, and <dir>/manifest.json
//   php tools/corpus/differential.php --provenance [<model dir> <cache dir>]
//                                                            checks only that the model documents and the cache are the ones
//                                                            that provenance.json describes
//   php tools/corpus/differential.php --adopt-model <reports dir> <input dir>
//                                                            copies the model's documents into tests/fixtures/corpus/model-report-2/
//                                                            and writes its provenance.json
//
// Every run reads only tests/fixtures/corpus/cache/ (tools/report/record --corpus) through Lockrot\Tests\Support\FixtureCliRun,
// at CLOCK, the recording day of the cache. The differential fails on a difference that
// tests/fixtures/corpus/model-divergences.json does not allow, on an allowed difference that does not occur exactly once, on
// a cache or a model document that provenance.json does not describe, and on a recording that neither lockrot nor the 0.13
// PHAR reads. It also fails on a lockrot document that breaks Lockrot\Tests\Support\FiredSignalsUnblocked.
// It compares findings only. `run.php_window` stays out: the model reads the window off its findings, lockrot off the
// whole lock (docs/verdicts.md#the-php-window), and tests/Integration/PhpWindowComposerOracleTest holds lockrot's window to Composer.
require __DIR__.'/../../vendor/autoload.php';

use Lockrot\Tests\Support\FiredSignalsUnblocked;
use Lockrot\Tests\Support\FixtureCliRun;
use Lockrot\Tests\Support\VersionMask;

const ROOT = __DIR__.'/../..';
const APPS = ROOT.'/tests/fixtures/apps';
const CACHE = ROOT.'/tests/fixtures/corpus/cache';
const MODEL = ROOT.'/tests/fixtures/corpus/model-report-2';
const DIVERGENCES = ROOT.'/tests/fixtures/corpus/model-divergences.json';
/** bin/lockrot's command with an Analyzer that checks no move, so that `run.move_check` is null as in the model. */
const ENTRY = __DIR__.'/lockrot-without-move-check';
const OPTIONS = ['--target-php=8.4', '--all'];
const CLOCK = '2026-10-14T00:00:00+00:00';
/** The release that writes the model's input. Its sha256 is the one that `gh attestation verify --repo somework/lockrot` accepted. */
const MODEL_PHAR = ['release' => 'v0.13.0', 'sha256' => '935883f2d2f52ce0f801f9ede846ee5eb42565aa5fd57b47137a82db8c611b1f'];
/** The run of drupal in which the advisory lookup of packages.drupal.org fails: lockrot must not write `clear` after it. */
const FEED_FAILED = 'drupal_drupal.feed-failed';
/** The value of a path that one side does not write. */
const ABSENT = ['$absent' => true];
/** The keys of each entry of DIVERGENCES, and its only keys. */
const ENTRY_KEYS = ['app', 'package', 'path', 'lockrot', 'model', 'reason'];
/** The fields of a finding that the differential compares. Beside them: each flag's FLAG_FIELDS as `flags.<id>.<key>`, and each key of each S9 row's `fix` as `S9.fix.<key>[<advisory id>]`. */
const FIELDS = [
    'verdict', 'lead', 'direct', 'reach', 'installed_php', 'checks_missing', 'checks_skipped', 'age', 'activity', 'gate.basis',
    'score.total', 'score.exact', 'score.band', 'score.decided_by', 'score.rounded_down', 'score.parts', 'score.terms', 'score.modifiers', 'score.without', 'score.accepted',
    'next_step.kind', 'next_step.constraint', 'next_step.version', 'next_step.to_branch', 'next_step.clears', 'next_step.leaves',
    'next_step.adds', 'next_step.clears_all', 'next_step.through', 'next_step.unverified', 'next_step.php_check', 'next_step.commands', 'next_step.crosses_major',
    'next_step.composer_moves_because', 'next_step.if_applied', 'next_step.held_by',
    'security.status', 'security.check', 'security.complete', 'security.unchecked_reason',
    'security.fix_kind', 'security.gets', 'security.partial', 'security.move_in', 'security.installed_branch_fixes',
];
/** The move check's key inside a compared object, by path: the model records no check, so a null one is not compared. */
const CHECK_KEYS = ['security.installed_branch_fixes' => 'check'];
/** The fields of each flag, by flag id. */
const FLAG_FIELDS = ['role', 'headline', 'degree'];
const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

$argv = $_SERVER['argv'];

if (($argv[1] ?? null) === '--model-input') {
    [$phar, $out] = [$argv[2] ?? '', $argv[3] ?? ''];
    if (!is_file($phar) || $out === '') {
        fwrite(STDERR, "usage: differential.php --model-input <phar> <dir>\n");
        exit(2);
    }
    @mkdir($out, 0777, true);
    $reads = [];
    foreach (runs() as $id => [$app, $mirrors]) {
        [$html, $read] = run($phar, $app, $mirrors, array_merge(OPTIONS, ['--format=html']));
        $reads = array_merge($reads, $read);
        if (preg_match('{<script id="lockrot-data" type="application/json">(.*?)</script>}s', $html, $match) !== 1) {
            fwrite(STDERR, "differential: no data block in the page of $id\n");
            exit(1);
        }
        file_put_contents($out.'/'.$id.'.json', json_encode(['data' => json_decode($match[1], true)], JSON_FLAGS)."\n");
        fwrite(STDOUT, "differential: $id\n");
    }
    $outputs = [];
    foreach (array_keys(runs()) as $id) {
        $outputs[$id.'.json'] = hash_file('sha256', $out.'/'.$id.'.json');
    }
    $manifest = ['phar' => ['sha256' => hash_file('sha256', $phar)], 'options' => array_merge(OPTIONS, ['--format=html']), 'clock' => CLOCK, 'cache_sha256' => cacheDigest(), 'outputs' => $outputs, 'reads' => recordingFiles($reads)];
    file_put_contents($out.'/manifest.json', json_encode($manifest, JSON_FLAGS)."\n");
    exit(0);
}

if (($argv[1] ?? null) === '--adopt-model') {
    adoptModel($argv[2] ?? '', $argv[3] ?? '');
    exit(0);
}

$provenanceOnly = ($argv[1] ?? null) === '--provenance';
[$model, $cache] = $provenanceOnly && isset($argv[2], $argv[3]) ? [$argv[2], $argv[3]] : [MODEL, CACHE];
$provenance = json_decode((string) @file_get_contents($model.'/provenance.json'), true);
if (!is_array($provenance)) {
    fwrite(STDERR, "differential: $model/provenance.json is missing or not JSON\n");
    exit(2);
}
$problems = provenanceProblems($provenance, $model, $cache);
if ($provenanceOnly) {
    foreach ($problems as $line) {
        fwrite(STDERR, 'differential: '.$line."\n");
    }
    exit($problems === [] ? 0 : 1);
}
$write = ($argv[1] ?? null) === '--write' ? ($argv[2] ?? '') : null;
$allowed = allowedEntries();
$used = array_fill(0, count($allowed), 0);
$unreviewed = [];
$byPath = [];
$reads = [];
$counts = ['lockrot' => ['vulnerable' => 0, 'critical' => 0], 'model' => ['vulnerable' => 0, 'critical' => 0]];
foreach (runs() as $id => [$app, $mirrors]) {
    [$json, $read] = run(ENTRY, $app, $mirrors, array_merge(OPTIONS, ['--format=json', '--fail-on=high']));
    $reads = array_merge($reads, $read);
    if ($write !== null) {
        @mkdir($write, 0777, true);
        file_put_contents($write.'/'.$id.'.json', preg_replace('{http://127\.0\.0\.1:\d+}', 'http://127.0.0.1', VersionMask::apply($json)));
    }
    $document = json_decode($json, true);
    foreach (FiredSignalsUnblocked::violations(is_array($document) ? $document : []) as $violation) {
        $problems[] = $id.' '.$violation;
    }
    $ours = findings($document);
    $theirs = findings(json_decode((string) file_get_contents(MODEL.'/'.$id.'.json'), true));
    foreach (['lockrot' => $ours, 'model' => $theirs] as $side => $findings) {
        foreach ($id === FEED_FAILED ? [] : $findings as $finding) {
            $counts[$side]['vulnerable'] += in_array('vulnerable', array_column(listAt($finding, 'flags'), 'id'), true) ? 1 : 0;
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
            $index = allowedBy($allowed, $id, $package, $path, $lockrot, $model);
            if ($index === null) {
                $unreviewed[] = ['app' => $id, 'package' => $package, 'path' => $path, 'lockrot' => $lockrot, 'model' => $model];
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
foreach ($allowed as $index => $entry) {
    if ($used[$index] !== 1) {
        $problems[] = sprintf('an allowed divergence occurs %d times, not once: %s', $used[$index], json_encode($entry, JSON_UNESCAPED_SLASHES));
    }
}
$unread = array_values(array_diff(cacheFiles(), recordingFiles($reads), $provenance['model_inputs']['reads']));
if ($unread !== []) {
    $problems[] = sprintf('%d recordings that no run reads, among them %s', count($unread), implode(', ', array_slice($unread, 0, 10)));
}
foreach (array_merge($problems, array_map(static fn (array $row): string => (string) json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $unreviewed)) as $line) {
    fwrite(STDERR, 'differential: '.$line."\n");
}
fwrite(STDOUT, sprintf("differential: %d unreviewed, %d other problems\n", count($unreviewed), count($problems)));
exit($unreviewed === [] && $problems === [] ? 0 : 1);

/**
 * Each run by its document name: the app and whether the further repositories of its composer.json answer.
 *
 * @return array<string, array{string, bool}>
 */
function runs(): array
{
    $runs = [];
    foreach (glob(APPS.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $runs[basename($dir)] = [basename($dir), true];
    }
    $runs[FEED_FAILED] = ['drupal_drupal', false];

    return $runs;
}

/**
 * @param list<string> $arguments
 *
 * @return array{string, list<string>} the output and the recordings that the run read
 */
function run(string $entry, string $app, bool $mirrors, array $arguments): array
{
    $run = FixtureCliRun::forRecording(APPS.'/'.$app, CACHE, $mirrors);
    try {
        $process = $run->run($entry, APPS.'/'.$app, CLOCK, $arguments);
    } finally {
        $run->stop();
    }
    // Exit 1 is a gate that fails. Exit 2 is a run that did not finish.
    if (!in_array($process->getExitCode(), [0, 1], true)) {
        fwrite(STDERR, "differential: $entry on $app exited ".$process->getExitCode()."\n".$process->getErrorOutput());
        exit(1);
    }
    // Without mirrors, the run asks the app's own composer repositories, and the closed proxy fails each request.
    $unmirrored = $mirrors ? [] : composerRepositories(APPS.'/'.$app);
    $outside = array_filter(FixtureCliRun::outsideRequests($process), static fn (string $url): bool => [] === array_filter($unmirrored, static fn (string $repository): bool => strpos($url, $repository.'/') === 0));
    if ($outside !== []) {
        fwrite(STDERR, "differential: $entry on $app asked outside the recordings: ".implode(', ', $outside)."\n");
        exit(1);
    }
    $read = $run->recordingsRead($process);
    if (in_array('https://packagist.org/api/security-advisories/', $read, true)) {
        $read[] = 'advisories/'.$app;
    }

    return [$process->getOutput(), $read];
}

/**
 * The cache files that the reads name, as paths below the cache.
 *
 * @param list<string> $reads
 *
 * @return list<string>
 */
function recordingFiles(array $reads): array
{
    $files = [];
    foreach (array_unique($reads) as $read) {
        if (strpos($read, 'github/') === 0) {
            $files[] = $read;
        } elseif (strpos($read, 'advisories/') === 0) {
            $files[] = $read.'.json';
        } elseif (strpos($read, 'https://repo.packagist.org/p2/') === 0) {
            $files[] = 'p2/'.sha1($read).'.json';
        } elseif (strpos($read, 'https://packagist.org/') !== 0) {
            $files[] = 'repos/'.sha1($read).'.json';
        }
    }
    sort($files);

    return array_values(array_unique($files));
}

/** @return list<string> every file of the cache, as a path below it, sorted */
function cacheFiles(string $cache = CACHE): array
{
    $files = [];
    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cache, FilesystemIterator::SKIP_DOTS)) as $file) {
        $files[] = substr($file->getPathname(), strlen($cache) + 1);
    }
    sort($files);

    return $files;
}

/** The sha256 of the lines "<sha256>  <path>", one per cache file, sorted by path. */
function cacheDigest(string $cache = CACHE): string
{
    return hash('sha256', implode('', array_map(static fn (string $file): string => hash_file('sha256', $cache.'/'.$file).'  '.$file."\n", cacheFiles($cache))));
}

/**
 * What the provenance.json of $model says that the files do not hold: the clock, the digest of $cache and each model
 * document's sha256.
 *
 * @param array<string, mixed> $provenance
 *
 * @return list<string>
 */
function provenanceProblems(array $provenance, string $model = MODEL, string $cache = CACHE): array
{
    $problems = [];
    if (array_values(array_unique($provenance['clock'])) !== [CLOCK]) {
        $problems[] = 'the model documents read another clock than '.CLOCK;
    }
    if ($provenance['cache']['sha256'] !== cacheDigest($cache)) {
        $problems[] = 'the cache is not the one that the model read: its digest differs from provenance.json';
    }
    $documents = array_values(array_diff(array_map('basename', glob($model.'/*.json') ?: []), ['provenance.json']));
    $outputs = array_keys($provenance['outputs']);
    $expected = array_map(static fn (string $id): string => $id.'.json', array_keys(runs()));
    sort($documents);
    sort($outputs);
    sort($expected);
    if ($outputs !== $documents || $outputs !== $expected) {
        $problems[] = 'the model documents are not the ones of provenance.json outputs, one per run';
    }
    foreach ($provenance['outputs'] as $name => $sha256) {
        if (!is_file($model.'/'.$name) || hash_file('sha256', $model.'/'.$name) !== $sha256) {
            $problems[] = 'the model document '.$name.' differs from its sha256 in provenance.json';
        }
    }

    return $problems;
}

/**
 * Copies the reports mode's documents and writes provenance.json from its provenance, the model input's manifest and the cache.
 * Exits 2 and changes nothing when the two directories do not belong together.
 */
function adoptModel(string $reports, string $inputs): void
{
    $model = json_decode((string) @file_get_contents($reports.'/provenance.json'), true);
    $input = json_decode((string) @file_get_contents($inputs.'/manifest.json'), true);
    $problems = !is_array($model) || !is_array($input) ? ['no provenance.json in '.$reports.' or no manifest.json in '.$inputs] : adoptionProblems($model, $input, $reports, $inputs);
    if ($problems !== []) {
        fwrite(STDERR, 'differential: nothing adopted:'."\n".implode("\n", $problems)."\n");
        exit(2);
    }
    foreach (glob(MODEL.'/*.json') ?: [] as $old) {
        unlink($old);
    }
    foreach (array_keys($model['outputs']) as $name) {
        copy($reports.'/'.$name, MODEL.'/'.$name);
    }
    $provenance = [
        'written_by' => 'security-axis/spec_flags_r3_docs.py reports <dir> <out> --cache tests/fixtures/corpus/cache --php-fit <file>, in the private model folder, then tools/corpus/differential.php --adopt-model',
        'model' => $model['model'],
        'model_inputs' => [
            'written_by' => 'php tools/corpus/differential.php --model-input <phar> <dir>',
            'phar' => MODEL_PHAR + ['verified_by' => 'gh attestation verify --repo somework/lockrot'],
            'options' => $input['options'],
            'sha256' => $model['inputs'],
            'reads' => $input['reads'],
        ],
        'php_fit_sha256' => $model['php_fit'],
        'cache' => ['path' => 'tests/fixtures/corpus/cache', 'files' => count(cacheFiles()), 'sha256' => cacheDigest(), 'sha256_of' => 'the lines "<sha256>  <path below the cache>", one per file, sorted by path'],
        'clock' => $model['clock'],
        'fail_on' => $model['fail_on'],
        'outputs' => $model['outputs'],
    ];
    file_put_contents(MODEL.'/provenance.json', json_encode($provenance, JSON_FLAGS)."\n");
}

/**
 * The URL of each `composer` repository in the composer.json of $appDir, without a trailing slash.
 *
 * @return list<string>
 */
function composerRepositories(string $appDir): array
{
    $manifest = json_decode((string) file_get_contents($appDir.'/composer.json'), true);
    $urls = [];
    foreach (is_array($manifest['repositories'] ?? null) ? $manifest['repositories'] : [] as $repository) {
        if (is_array($repository) && ($repository['type'] ?? null) === 'composer' && is_string($repository['url'] ?? null)) {
            $urls[] = rtrim($repository['url'], '/');
        }
    }

    return $urls;
}

/**
 * Why the model's documents in $reports were not written from the input in $inputs, by MODEL_PHAR at CLOCK.
 *
 * @param array<string, mixed> $model the reports mode's provenance.json
 * @param array<string, mixed> $input the manifest.json of `--model-input`
 *
 * @return list<string>
 */
function adoptionProblems(array $model, array $input, string $reports, string $inputs): array
{
    $problems = [];
    if (($input['phar']['sha256'] ?? null) !== MODEL_PHAR['sha256']) {
        $problems[] = 'the model input was not written by the '.MODEL_PHAR['release'].' PHAR';
    }
    if (($input['cache_sha256'] ?? null) !== cacheDigest()) {
        $problems[] = 'the model input was written from another cache than tests/fixtures/corpus/cache/';
    }
    $read = (array) ($model['inputs'] ?? []);
    $written = (array) ($input['outputs'] ?? []);
    ksort($read);
    ksort($written);
    if ($written !== $read) {
        $problems[] = 'the model read other documents than the ones that the manifest of '.$inputs.' names';
    }
    if (($input['clock'] ?? null) !== CLOCK || array_values(array_unique((array) ($model['clock'] ?? []))) !== [CLOCK]) {
        $problems[] = 'the model input or its documents read another clock than '.CLOCK;
    }
    foreach (['inputs' => $inputs, 'outputs' => $reports] as $key => $dir) {
        foreach ((array) ($model[$key] ?? []) as $name => $sha256) {
            if (!is_file($dir.'/'.$name) || hash_file('sha256', $dir.'/'.$name) !== $sha256) {
                $problems[] = $dir.'/'.$name.' differs from its sha256 in the provenance of '.$reports;
            }
        }
    }
    $expected = array_map(static fn (string $id): string => $id.'.json', array_keys(runs()));
    sort($expected);
    foreach (['inputs', 'outputs'] as $key) {
        $names = array_keys((array) ($model[$key] ?? []));
        sort($names);
        if ($names !== $expected) {
            $problems[] = 'the '.$key.' in the provenance of '.$reports.' are not one document per run';
        }
    }

    return $problems;
}

/**
 * The entries of model-divergences.json. Each names one cell and its two values: `app` (the run), `package`, `path`, `lockrot`
 * and `model`, with a `reason`. An entry that lacks one of these keys, holds another key (such as `count`) or repeats a cell
 * exits 2: an entry that matches more than one cell, or any value, hides a regression in each cell that it covers.
 *
 * @return list<array<string, mixed>>
 */
function allowedEntries(): array
{
    $decoded = json_decode((string) @file_get_contents(DIVERGENCES), true);
    $allowed = is_array($decoded) && is_array($decoded['allowed'] ?? null) ? $decoded['allowed'] : null;
    if ($allowed === null) {
        fwrite(STDERR, "differential: tests/fixtures/corpus/model-divergences.json is missing or has no allowed list\n");
        exit(2);
    }
    $cells = [];
    foreach ($allowed as $entry) {
        $keys = is_array($entry) ? array_keys($entry) : [];
        $missing = array_diff(ENTRY_KEYS, $keys);
        $extra = array_diff($keys, ENTRY_KEYS);
        $cell = $missing === [] ? json_encode([$entry['app'], $entry['package'], $entry['path']]) : null;
        if ($missing !== [] || $extra !== [] || isset($cells[$cell])) {
            $why = $missing !== [] ? 'lacks '.implode(', ', $missing) : ($extra !== [] ? 'holds '.implode(', ', $extra) : 'repeats its cell');
            fwrite(STDERR, 'differential: an allowed divergence '.$why.': '.json_encode($entry, JSON_UNESCAPED_SLASHES)."\n");
            exit(2);
        }
        $cells[$cell] = true;
    }

    return $allowed;
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
            $value = is_array($value) && array_key_exists($key, $value) ? $value[$key] : ABSENT;
        }
        if (isset(CHECK_KEYS[$path]) && is_array($value) && array_key_exists(CHECK_KEYS[$path], $value) && $value[CHECK_KEYS[$path]] === null) {
            unset($value[CHECK_KEYS[$path]]);
        }
        $values[$path] = canonical($value);
    }
    foreach (listAt($finding, 'flags') as $flag) {
        foreach (FLAG_FIELDS as $key) {
            $values['flags.'.$flag['id'].'.'.$key] = canonical(array_key_exists($key, $flag) ? $flag[$key] : ABSENT);
        }
    }
    foreach (listAt($finding, 'signals') as $signal) {
        foreach (($signal['id'] ?? null) === 'S9' ? listAt($signal['data'] ?? [], 'advisories') : [] as $row) {
            foreach (is_array($row['fix'] ?? null) ? $row['fix'] : [] as $key => $fix) {
                $values['S9.fix.'.$key.'['.$row['id'].']'] = canonical($fix);
            }
        }
    }

    return $values;
}

/**
 * A list under $key, or none: a document that lacks it then differs on each path it would give.
 *
 * @param mixed $object
 *
 * @return list<array<string, mixed>>
 */
function listAt($object, string $key): array
{
    return is_array($object) && is_array($object[$key] ?? null) ? $object[$key] : [];
}

/**
 * @param array<string, string> $ours
 * @param array<string, string> $theirs
 *
 * @return array<string, array{mixed, mixed}> lockrot's and the model's value of each path that differs, ABSENT for a side
 *                                            that lacks the path
 */
function differences(array $ours, array $theirs): array
{
    $differences = [];
    foreach (array_unique(array_merge(array_keys($ours), array_keys($theirs))) as $path) {
        $left = $ours[$path] ?? canonical(ABSENT);
        $right = $theirs[$path] ?? canonical(ABSENT);
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
 * The index of the allowed divergence of the cell, when it names both values: `{"$absent": true}` for a side without the
 * path.
 *
 * @param list<array<string, mixed>> $allowed
 * @param mixed                      $lockrot
 * @param mixed                      $model
 */
function allowedBy(array $allowed, string $app, string $package, string $path, $lockrot, $model): ?int
{
    foreach ($allowed as $index => $entry) {
        if ($entry['path'] === $path && $entry['app'] === $app && $entry['package'] === $package
            && canonical($entry['lockrot']) === canonical($lockrot) && canonical($entry['model']) === canonical($model)) {
            return $index;
        }
    }

    return null;
}
