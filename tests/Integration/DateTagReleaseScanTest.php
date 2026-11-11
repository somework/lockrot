<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * A stable tag that normalizes to digits only (the date version 20200101) above the installed
 * release. The repository is a local `file://` Composer repository, so `--offline` still reads it.
 */
final class DateTagReleaseScanTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/lockrot-date-tag-'.bin2hex(random_bytes(8));
        foreach (['', '/proj', '/repo', '/home', '/cache'] as $sub) {
            mkdir($this->dir.$sub);
        }
        $release = static fn (string $version, string $time, string $reference): array => [
            'name' => 'v/p',
            'version' => $version,
            'time' => $time,
            'source' => ['type' => 'git', 'url' => 'https://example.invalid/p.git', 'reference' => $reference],
        ];
        file_put_contents($this->dir.'/repo/packages.json', (string) json_encode(['packages' => ['v/p' => [
            '3.1.0' => $release('3.1.0', '2023-01-01T00:00:00+00:00', 'a1'),
            '3.2.0' => $release('3.2.0', '2024-01-01T00:00:00+00:00', 'a2'),
            '20200101' => $release('20200101', '2020-01-01T00:00:00+00:00', 'a0'),
        ]]]));
        file_put_contents($this->dir.'/proj/composer.json', (string) json_encode([
            'name' => 'x/y',
            'require' => ['v/p' => '^3.1'],
            'repositories' => [['type' => 'composer', 'url' => 'file://'.$this->dir.'/repo'], ['packagist.org' => false]],
        ]));
        file_put_contents($this->dir.'/proj/composer.lock', (string) json_encode([
            'content-hash' => 'x',
            'packages' => [$release('3.1.0', '2023-01-01T00:00:00+00:00', 'a1') + [
                'dist' => ['type' => 'zip', 'url' => 'https://example.invalid/p.zip'],
                'type' => 'library',
                'notification-url' => 'https://packagist.org/downloads/',
            ]],
            'packages-dev' => [],
            'aliases' => [],
            'minimum-stability' => 'stable',
            'stability-flags' => [],
            'prefer-stable' => false,
            'prefer-lowest' => false,
            'platform' => [],
            'platform-dev' => [],
        ]));
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->dir]))->run();
    }

    public function testFinding34EveryFormatReportsALockWithADateTagAboveTheInstalledRelease(): void
    {
        foreach (['--format=table', '--format=json', '--format=html', '--explain=v/p', '--generate-baseline'] as $argument) {
            $process = $this->lockrot($argument);

            self::assertSame(0, $process->getExitCode(), $argument.': '.$process->getErrorOutput());
            self::assertStringNotContainsString('lockrot failed', $process->getErrorOutput(), $argument);
        }

        $report = json_decode($this->lockrot('--format=json')->getOutput(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertSame('v/p', JsonPath::stringAt($report, ['findings', 0, 'package']));
        self::assertSame('read', JsonPath::stringAt($report, ['findings', 0, 'metadata', 'status']));
        self::assertFileExists($this->dir.'/proj/lockrot-baseline.json');
    }

    private function lockrot(string $argument): Process
    {
        $process = new Process(
            [\PHP_BINARY, \dirname(__DIR__, 2).'/bin/lockrot', $argument, '--target-php=8.4', '--offline'],
            $this->dir.'/proj',
            [
                'COMPOSER_CACHE_DIR' => $this->dir.'/cache',
                'COMPOSER_HOME' => $this->dir.'/home',
                'LOCKROT_TODAY' => '2026-09-14T00:00:00+00:00',
            ]
        );
        $process->setTimeout(60)->run();

        return $process;
    }
}
