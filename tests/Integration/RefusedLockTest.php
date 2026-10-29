<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** A lock that Composer cannot use is exit 2 through bin/lockrot, never a clean report. */
final class RefusedLockTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/lockrot-refused-lock-'.bin2hex(random_bytes(8));
        mkdir($this->dir);
        mkdir($this->dir.'/cache');
        mkdir($this->dir.'/home');
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->dir]))->run();
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function lockBodies(): iterable
    {
        yield 'an empty object' => ['{}', 'lockrot: composer.lock: packages must be a list of package entries'];
        yield 'an empty list' => ['[]', 'lockrot: composer.lock: packages must be a list of package entries'];
        yield 'a platform constraint that Composer cannot parse' => ['{"packages": [], "platform": {"php": "not a constraint"}}', 'lockrot: composer.lock: platform cannot be loaded: '];
    }

    /** @dataProvider lockBodies */
    #[DataProvider('lockBodies')]
    public function testALockThatComposerCannotUseIsExitTwo(string $body, string $refusal): void
    {
        file_put_contents($this->dir.'/composer.lock', $body);
        $process = new Process(
            [\PHP_BINARY, \dirname(__DIR__, 2).'/bin/lockrot', '--target-php=8.4', '--offline'],
            $this->dir,
            ['COMPOSER_CACHE_DIR' => $this->dir.'/cache', 'COMPOSER_HOME' => $this->dir.'/home']
        );
        $process->setTimeout(60)->run();

        self::assertSame(2, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertSame('', $process->getOutput());
        self::assertStringContainsString($refusal, $process->getErrorOutput());
    }
}
