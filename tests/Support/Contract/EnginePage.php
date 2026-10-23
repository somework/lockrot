<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support\Contract;

use Lockrot\Tests\Support\FixtureCliRun;
use Lockrot\Tests\Support\VersionMask;

/**
 * The page cases whose moves Composer's solver checked (SPEC-0.14.md §7.9.7). Each is bin/lockrot's
 * report-2 of a fixture lock, on the recorded answers of its `http` directory under tests/fixtures/:
 * corpus/cache/ for an app, corpus/sites/ for a site (corpus/sites.provenance.json).
 * A model case document holds no move check, so only these cases show a ready move.
 * tools/report/record --engine-pages writes them to tests/fixtures/contract/page/.
 */
final class EnginePage
{
    public const DIRECTORY = ContractBundle::DIRECTORY.'/'.ContractBundle::ENGINE_PAGES;
    /** @var array<string, array{fixture: string, http: string, today: string, arguments: list<string>}> by case id, the paths under tests/fixtures/ */
    public const CASES = [
        'snipe-it' => ['fixture' => 'apps/grokability_snipe-it', 'http' => 'corpus/cache', 'today' => '2026-10-14T00:00:00+00:00', 'arguments' => ['--target-php=8.4', '--all', '--fail-on=high', '--format=json']],
        // `target_fix` refused at 8.3 by advisory blocking (SPEC-0.14.md §5.9).
        'drupal-site' => ['fixture' => 'sites/drupal-site', 'http' => 'corpus/sites', 'today' => '2026-10-22T00:00:00+00:00', 'arguments' => ['--target-php=8.3', '--all', '--fail-on=high', '--format=json']],
    ];
    private const FIXTURES = __DIR__.'/../../fixtures/';
    private const ENTRY = __DIR__.'/../../../bin/lockrot';

    /** The report-2 of case $id, with the tool version of VersionMask and the fixture server's port removed. */
    public static function record(string $id): string
    {
        if (!isset(self::CASES[$id])) {
            throw new \InvalidArgumentException('no engine page case '.$id);
        }
        $case = self::CASES[$id];
        $app = self::FIXTURES.$case['fixture'];
        $run = FixtureCliRun::forRecording($app, self::FIXTURES.$case['http']);
        try {
            $process = $run->run(self::ENTRY, $app, $case['today'], $case['arguments']);
        } finally {
            $run->stop();
        }
        // Exit 1 is a gate that fails. Exit 2 is a run that did not finish.
        if (!\in_array($process->getExitCode(), [0, 1], true) || FixtureCliRun::outsideRequests($process) !== []) {
            throw new \RuntimeException($id.': the run exited '.$process->getExitCode().' or asked outside the recordings'."\n".$process->getErrorOutput());
        }

        return (string) preg_replace('{http://127\.0\.0\.1:\d+}', 'http://127.0.0.1', VersionMask::apply($process->getOutput()));
    }
}
