<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support\Contract;

use Lockrot\Tests\Support\FixtureCliRun;
use Lockrot\Tests\Support\VersionMask;

/**
 * The page cases whose moves Composer's solver checked (SPEC-0.14.md §7.9.7). Each is bin/lockrot's
 * report-2 of an app of tests/fixtures/apps, on the recorded answers of tests/fixtures/corpus/cache/.
 * A model case document holds no move check, so only these cases show a ready move.
 * tools/report/record --engine-pages writes them to tests/fixtures/contract/page/.
 */
final class EnginePage
{
    public const DIRECTORY = ContractBundle::DIRECTORY.'/'.ContractBundle::ENGINE_PAGES;
    /** @var array<string, array{app: string, today: string, arguments: list<string>}> by case id */
    public const CASES = [
        'snipe-it' => ['app' => 'grokability_snipe-it', 'today' => '2026-10-14T00:00:00+00:00', 'arguments' => ['--target-php=8.4', '--all', '--fail-on=high', '--format=json']],
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
        $app = self::FIXTURES.'apps/'.$case['app'];
        $run = FixtureCliRun::forRecording($app, self::FIXTURES.'corpus/cache');
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
