<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Tests\Support\FixtureCliRun;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Without a `require.php`, lockrot reads R off the lock's `platform.php` (docs/verdicts.md#capped). The corpus runner
 * tools/corpus/lockrot-without-move-check must pass that value to the analyzer, as LockrotCommand does.
 */
final class CorpusRunnerPlatformPhpTest extends TestCase
{
    private const APP = __DIR__.'/../fixtures/apps/mautic_mautic';
    private const CACHE = __DIR__.'/../fixtures/corpus/cache';
    private const ENTRY = __DIR__.'/../../tools/corpus/lockrot-without-move-check';
    /** The recording day of the corpus cache. */
    private const CLOCK = '2026-10-14T00:00:00+00:00';
    private const CAPPED = 'ezyang/htmlpurifier';

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/lockrot-corpus-platform-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->dir]))->run();
    }

    public function testRIsReadOffTheLockPlatformPhpWhenComposerJsonHasNoRequirePhp(): void
    {
        // Arrange: mautic states no require.php, and its htmlpurifier admits PHP up to 8.3.
        $project = $this->dir.'/mautic_mautic';
        mkdir($project, 0777, true);
        $manifest = json_decode((string) file_get_contents(self::APP.'/composer.json'), true);
        self::assertIsArray($manifest);
        self::assertArrayNotHasKey('php', (array) ($manifest['require'] ?? []));
        copy(self::APP.'/composer.json', $project.'/composer.json');
        $lock = json_decode((string) file_get_contents(self::APP.'/composer.lock'), true);
        self::assertIsArray($lock);
        $lock['platform'] = ['php' => '>=8.1 <8.5'];
        file_put_contents($project.'/composer.lock', json_encode($lock, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

        // Act
        $run = FixtureCliRun::forRecording($project, self::CACHE);
        try {
            $process = $run->run(self::ENTRY, $project, self::CLOCK, ['--target-php=8.4', '--all', '--format=json']);
        } finally {
            $run->stop();
        }

        // Assert
        self::assertContains($process->getExitCode(), [0, 1], $process->getErrorOutput());
        $report = JsonPath::decoded(json_decode($process->getOutput(), true));
        $capped = [];
        foreach (JsonPath::arrayAt($report, ['findings']) as $finding) {
            $finding = JsonPath::decoded($finding);
            foreach (JsonPath::stringAt($finding, ['package']) === self::CAPPED ? JsonPath::arrayAt($finding, ['signals']) : [] as $signal) {
                $signal = JsonPath::decoded($signal);
                if (JsonPath::stringAt($signal, ['id']) === 'S11') {
                    $capped[] = [JsonPath::stringAt($signal, ['data', 'newest_php']), JsonPath::stringAt($signal, ['data', 'newest_php_source'])];
                }
            }
        }
        self::assertSame([['8.4', 'platform_php']], $capped);
    }
}
