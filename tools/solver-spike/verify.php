<?php

declare(strict_types=1);

// Spike: verify each update and require move of a lockrot report with Composer's Solver, offline.
// Usage: php tools/solver-spike/verify.php <project dir> <report.json> <out.json> [machine|ignore-ext] [extra.json]
// extra.json: a list of {label, allow, inline, root, transitive} moves to solve beside the report's moves.

use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Repository\ComposerRepository;
use Lockrot\Analyzer\MoveVerifier;

// SPIKE_AUTOLOAD points at another Composer, for example phar:///usr/bin/composer/vendor/autoload.php.
require getenv('SPIKE_AUTOLOAD') ?: __DIR__.'/../../vendor/autoload.php';
if (!class_exists(MoveVerifier::class, false)) {
    require __DIR__.'/../../src/Analyzer/MoveVerifier.php';
}

ini_set('memory_limit', '4G');
[, $dir, $reportPath, $outPath] = $argv;
$platformMode = $argv[4] ?? MoveVerifier::PLATFORM_IGNORE_EXT;

$report = json_decode((string) file_get_contents($reportPath), true, 512, \JSON_THROW_ON_ERROR);
$io = new NullIO();
$composer = (new Factory())->createComposer($io, $dir.'/composer.json', true, $dir, true, true);
$root = $composer->getPackage();
$locked = $composer->getLocker()->getLockedRepository(true);
$overrides = $composer->getConfig()->get('platform');

// The names that lockrot loaded: one finding per analysed package. A name that the lock takes from a
// path or VCS repository keeps only its locked version: that repository shadows Packagist.
$names = [];
foreach ($report['findings'] as $finding) {
    if ($finding['from_composer_repository'] !== false) {
        $names[] = $finding['package'];
    }
}
$names = array_values(array_unique($names));

$t0 = hrtime(true);
$m0 = memory_get_usage();
$versions = [];
$repositories = array_values(array_filter($composer->getRepositoryManager()->getRepositories(), static fn ($r) => $r instanceof ComposerRepository));
$stable = ['stable' => BasePackage::STABILITY_STABLE, 'RC' => BasePackage::STABILITY_RC, 'beta' => BasePackage::STABILITY_BETA, 'alpha' => BasePackage::STABILITY_ALPHA];
$dev = ['dev' => BasePackage::STABILITY_DEV];
foreach ($repositories as $repository) {
    foreach ([$stable, $dev] as $stabilities) {
        $todo = array_values(array_diff($names, array_keys($versions)));
        foreach (array_chunk($todo, 10) as $chunk) {
            try {
                $result = $repository->loadPackages(array_fill_keys($chunk, null), $stabilities, []);
            } catch (\RuntimeException $e) {
                continue;
            }
            foreach ($result['packages'] as $package) {
                $versions[$package->getName()][] = $package;
            }
        }
    }
}
// The path or VCS repository of such a name offers the locked version, as Composer reads it there.
foreach ($report['findings'] as $finding) {
    if ($finding['from_composer_repository'] === false) {
        foreach ($locked->findPackages($finding['package']) as $package) {
            $versions[$finding['package']][] = clone $package;
        }
    }
}
$loadSeconds = (hrtime(true) - $t0) / 1e9;
$loadBytes = memory_get_usage() - $m0;

// SPIKE_BLOCK=0 turns the advisory filter off, as `policy.advisories.block: false` or Composer before 2.9 does.
$affected = [];
if (getenv('SPIKE_BLOCK') !== '0' && MoveVerifier::blocksAdvisories()) {
    foreach ($repositories as $repository) {
        $answer = $repository->getSecurityAdvisories(array_fill_keys($names, new Composer\Semver\Constraint\MatchAllConstraint()), true);
        foreach ($answer['advisories'] as $name => $advisories) {
            foreach ($advisories as $advisory) {
                $affected[$name][] = $advisory->affectedVersions;
            }
        }
    }
}
$verifier = new MoveVerifier($root, $locked, $overrides, $platformMode, $affected);
$bare = static fn (?string $v): ?string => $v === null ? null : ltrim($v, 'v');

// A partial update of a name outside the lock moves nothing. An empty allow list is a full update. When it fails, every move on this lock
// fails for the same reason, and that reason is not a hold of the move.
$baseline = $verifier->verify($versions, ['lockrot-spike/none'], [], [], 0);

$moves = [];
foreach ($report['findings'] as $finding) {
    $step = $finding['next_step'] ?? null;
    if ($step === null || !\in_array($step['kind'], ['update', 'require'], true) || ($step['commands'] ?? []) === []) {
        continue;
    }
    $name = $finding['package'];
    $isRequire = $step['kind'] === 'require';
    $constraint = $isRequire ? $step['constraint'] : '^'.$bare($step['version']);
    $forms = [
        'as-is' => $isRequire ? [[], [$name => $constraint], 0] : [[], [], 0],
        'inline' => $isRequire ? [[], [$name => $constraint], 0] : [[$name => $constraint], [], 0],
        'inline-w' => $isRequire ? [[], [$name => $constraint], 1] : [[$name => $constraint], [], 1],
        'inline-W' => $isRequire ? [[], [$name => $constraint], 2] : [[$name => $constraint], [], 2],
    ];
    $results = [];
    foreach ($forms as $form => [$inline, $rootChange, $transitive]) {
        $r = $verifier->verify($versions, [$name], $inline, $rootChange, $transitive);
        $r['target'] = $r['changes'][$name][1] ?? ($r['ok'] ? $finding['version'] : null);
        $results[$form] = $r;
    }
    $moves[] = [
        'package' => $name,
        'installed' => $finding['version'],
        'kind' => $step['kind'],
        'lockrot_version' => $step['version'],
        'constraint' => $constraint,
        'command' => $step['commands'][0],
        'held_by' => $step['held_by'],
        'results' => $results,
    ];
}

$extra = [];
foreach (isset($argv[5]) ? json_decode((string) file_get_contents($argv[5]), true, 512, \JSON_THROW_ON_ERROR) : [] as $spec) {
    $extra[$spec['label']] = $verifier->verify($versions, $spec['allow'], $spec['inline'] ?? [], $spec['root'] ?? [], $spec['transitive'] ?? 0);
}

$combined = null;
$members = $report['security']['update_now'] ?? [];
if ($members !== []) {
    $inline = [];
    foreach ($report['findings'] as $finding) {
        if (\in_array($finding['package'], $members, true) && ($finding['next_step']['version'] ?? null) !== null) {
            $inline[$finding['package']] = '^'.$bare($finding['next_step']['version']);
        }
    }
    $combined = [
        'members' => $members,
        'as-is' => $verifier->verify($versions, $members, [], [], 0),
        'inline' => $verifier->verify($versions, $members, $inline, [], 0),
        'inline-w' => $verifier->verify($versions, $members, $inline, [], 1),
        'inline-W' => $verifier->verify($versions, $members, $inline, [], 2),
    ];
}

file_put_contents($outPath, json_encode([
    'dir' => $dir,
    'platform_mode' => $platformMode,
    'composer' => Composer\Composer::getVersion(),
    'advisory_names' => \count($affected),
    'names_loaded' => \count($versions),
    'versions_loaded' => array_sum(array_map('count', $versions)),
    'load_seconds' => $loadSeconds,
    'load_bytes' => $loadBytes,
    'peak_bytes' => memory_get_peak_usage(),
    'moves' => $moves,
    'baseline' => ['ok' => $baseline['ok'], 'holds' => $baseline['holds'], 'problem' => $baseline['problem']],
    'update_now' => $combined,
    'extra' => $extra,
], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
echo "moves: ".\count($moves)." load: ".round($loadSeconds, 2)."s peak: ".round(memory_get_peak_usage() / 1048576)."MB\n";
