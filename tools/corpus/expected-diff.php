#!/usr/bin/env php
<?php

declare(strict_types=1);
// The expected-diff check: lockrot of this checkout against lockrot of a base commit, on the apps of tests/fixtures/apps,
// with the recorded answers of tests/fixtures/corpus/cache/ and one clock. It fails on any change outside its allow list.
//
//   php tools/corpus/expected-diff.php <base commit> [--allow <json path>]... [--allow-line <prefix>]...
//
// Each `--allow` names a JSON path of report-2 that may differ, and each `--allow-line` the prefix of a text line that
// this checkout may add. Every other difference of the json document and of the table, markdown and github outputs fails
// the run with exit 1. The base is a `git archive` of the commit with this checkout's vendor/ beside it, so both sides run
// the same dependencies. Both sides run bin/lockrot, the move check included.
require __DIR__.'/../../vendor/autoload.php';

use Lockrot\Tests\Support\FixtureCliRun;

const ROOT = __DIR__.'/../..';
const APPS = ROOT.'/tests/fixtures/apps';
const CACHE = ROOT.'/tests/fixtures/corpus/cache';
/** The recording day of the cache, as tools/corpus/differential.php reads it. */
const CLOCK = '2026-10-14T00:00:00+00:00';
const OPTIONS = ['--target-php=8.4', '--all', '--fail-on=high'];
/** The text formats besides json, by the file name that each run writes. */
const TEXT = ['table' => 'table.txt', 'markdown' => 'report.md', 'github' => 'github.txt'];

$argv = $_SERVER['argv'];
$base = $argv[1] ?? '';
$allowPaths = [];
$allowLines = [];
for ($i = 2; $i < count($argv); $i += 2) {
    if ($argv[$i] === '--allow' && isset($argv[$i + 1])) {
        $allowPaths[] = $argv[$i + 1];
    } elseif ($argv[$i] === '--allow-line' && isset($argv[$i + 1])) {
        $allowLines[] = $argv[$i + 1];
    } else {
        $base = '';
        break;
    }
}
if ($base === '' || preg_match('/^[0-9a-f]{7,40}$/', $base) !== 1) {
    fwrite(STDERR, "usage: expected-diff.php <base commit> [--allow <json path>]... [--allow-line <prefix>]...\n");
    exit(2);
}
// Wide enough that no table line wraps, so that an allowed line is one line.
putenv('COLUMNS=1000');

$work = sys_get_temp_dir().'/lockrot-expected-diff-'.bin2hex(random_bytes(4));
mkdir($work.'/base', 0777, true);
try {
    shell(sprintf('git -C %s archive %s | tar -x -C %s', escapeshellarg(ROOT), escapeshellarg($base), escapeshellarg($work.'/base')));
    shell(sprintf('cp -R %s %s', escapeshellarg(ROOT.'/vendor'), escapeshellarg($work.'/base/vendor')));
    $problems = [];
    $counts = [];
    $apps = glob(APPS.'/*', GLOB_ONLYDIR) ?: [];
    foreach ($apps as $app) {
        $name = basename($app);
        $sides = [];
        foreach (['base' => $work.'/base/bin/lockrot', 'head' => ROOT.'/bin/lockrot'] as $side => $entry) {
            $sides[$side] = runOnce($entry, $app, $work.'/'.$side.'/'.$name);
        }
        foreach (paths(json_decode($sides['base']['json'], true), json_decode($sides['head']['json'], true), '') as $path) {
            if (in_array($path, $allowPaths, true)) {
                $counts[$path] = ($counts[$path] ?? 0) + 1;
            } else {
                $problems[] = $name.' json: '.$path;
            }
        }
        foreach (array_keys(TEXT) as $format) {
            [$added, $other] = lineChanges($sides['base'][$format], $sides['head'][$format], $allowLines);
            foreach ($added as $prefix) {
                $counts[$format.' line '.$prefix] = ($counts[$format.' line '.$prefix] ?? 0) + 1;
            }
            foreach ($other as $line) {
                $problems[] = $name.' '.$format.': '.$line;
            }
        }
    }
} finally {
    shell(sprintf('rm -rf %s', escapeshellarg($work)));
}

ksort($counts);
fwrite(STDOUT, sprintf("expected-diff: %d apps against %s\n", count($apps), $base));
foreach ($counts as $what => $count) {
    fwrite(STDOUT, sprintf("  allowed %-40s %d apps\n", $what, $count));
}
foreach ($problems as $line) {
    fwrite(STDERR, 'expected-diff: '.$line."\n");
}
fwrite(STDOUT, sprintf("expected-diff: %d changes outside the allow list\n", count($problems)));
exit($problems === [] ? 0 : 1);

function shell(string $command): void
{
    exec($command.' 2>&1', $output, $code);
    if ($code !== 0) {
        fwrite(STDERR, 'expected-diff: '.$command.' failed: '.implode("\n", $output)."\n");
        exit(2);
    }
}

/**
 * One run of $entry on the app: the table on stdout, the other formats as files.
 *
 * @return array<string, string> format => output, the server port masked
 */
function runOnce(string $entry, string $app, string $out): array
{
    mkdir($out, 0777, true);
    $arguments = array_merge(OPTIONS, ['--output=json:'.$out.'/report.json']);
    foreach (['markdown', 'github'] as $format) {
        $arguments[] = '--output='.$format.':'.$out.'/'.TEXT[$format];
    }
    $run = FixtureCliRun::forRecording($app, CACHE);
    try {
        $process = $run->run($entry, $app, CLOCK, $arguments);
    } finally {
        $run->stop();
    }
    // Exit 1 is a gate that fails. Exit 2 is a run that did not finish.
    if (!in_array($process->getExitCode(), [0, 1], true)) {
        fwrite(STDERR, "expected-diff: $entry on $app exited ".$process->getExitCode()."\n".$process->getErrorOutput());
        exit(2);
    }
    $mask = static fn (string $text): string => (string) preg_replace('{http://127\.0\.0\.1:\d+}', 'http://127.0.0.1', $text);
    $outputs = ['json' => $mask((string) file_get_contents($out.'/report.json')), 'table' => $mask($process->getOutput())];
    foreach (['markdown', 'github'] as $format) {
        $outputs[$format] = $mask((string) file_get_contents($out.'/'.TEXT[$format]));
    }

    return $outputs;
}

/**
 * The JSON paths where the two documents differ: a key on one side only, or a value that differs. A list is compared
 * by index.
 *
 * @param mixed $base
 * @param mixed $head
 *
 * @return list<string>
 */
function paths($base, $head, string $at): array
{
    if (!is_array($base) || !is_array($head)) {
        return $base === $head ? [] : [$at];
    }
    $paths = [];
    foreach (array_unique(array_merge(array_keys($base), array_keys($head))) as $key) {
        $path = $at === '' ? (string) $key : $at.(is_int($key) ? '['.$key.']' : '.'.$key);
        if (!array_key_exists($key, $base) || !array_key_exists($key, $head)) {
            $paths[] = $path;
            continue;
        }
        $paths = array_merge($paths, paths($base[$key], $head[$key], $path));
    }

    return $paths;
}

/**
 * The lines that the head adds with an allowed prefix, and every other change, when the head's text without those lines
 * must equal the base's text.
 *
 * @param list<string> $allowed
 *
 * @return array{list<string>, list<string>}
 */
function lineChanges(string $base, string $head, array $allowed): array
{
    $added = [];
    $kept = [];
    foreach (explode("\n", $head) as $line) {
        $prefix = null;
        foreach ($allowed as $candidate) {
            if (strncmp($line, $candidate, strlen($candidate)) === 0) {
                $prefix = $candidate;
            }
        }
        if ($prefix === null) {
            $kept[] = $line;
        } else {
            $added[] = $prefix;
        }
    }
    $baseLines = explode("\n", $base);
    if ($kept === $baseLines) {
        return [$added, []];
    }
    $other = [];
    foreach (array_merge(array_diff($kept, $baseLines), array_diff($baseLines, $kept)) as $line) {
        $other[] = (in_array($line, $kept, true) ? '+ ' : '- ').$line;
    }

    return [$added, $other === [] ? ['the lines are reordered'] : $other];
}
