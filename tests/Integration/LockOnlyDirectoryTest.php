<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Regression coverage for LockrotCommand::quietRootVersionGuessing(), called from initialize(): a
 * lock-only directory with no .git and a composer.json that carries neither `version` nor `type` is
 * exactly the shape that makes Composer's RootPackageLoader fall back to VersionGuesser, which
 * shells out to git/hg/fossil/svn looking for something to derive a version from and then warns
 * "could not detect the root package version". Only a real subprocess through bin/lockrot exercises
 * this: the unit-level CommandTester never builds a fresh Composer instance from scratch the way
 * this lock-only path does.
 */
final class LockOnlyDirectoryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/lockrot-lockonly-'.uniqid('', true);
        mkdir($this->dir);
        mkdir($this->dir.'/cache');
        mkdir($this->dir.'/home');

        file_put_contents($this->dir.'/composer.json', (string) json_encode([
            'name' => 'acme/app',
            'require' => ['laravel/framework' => '*'],
        ]));
        copy(__DIR__.'/../fixtures/skeletons/laravel/composer.lock', $this->dir.'/composer.lock');
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->dir]))->run();
    }

    public function testNoRootVersionWarningAndNoVcsProbing(): void
    {
        $process = new Process(
            [\PHP_BINARY, \dirname(__DIR__, 2).'/bin/lockrot', '--format=json', '--target-php=8.4', '--offline'],
            $this->dir,
            [
                'COMPOSER_CACHE_DIR' => $this->dir.'/cache',
                'COMPOSER_HOME' => $this->dir.'/home',
            ]
        );
        $process->setTimeout(60)->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $json = json_decode($process->getOutput(), true);
        self::assertIsArray($json, $process->getOutput());
        self::assertStringNotContainsString('could not detect the root package', $process->getErrorOutput());
        self::assertStringNotContainsString('Deprecated', $process->getErrorOutput());
    }
}
