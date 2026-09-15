<?php

declare(strict_types=1);

namespace Lockrot\Tests\E2E;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** @group e2e */
#[Group('e2e')]
final class PharTest extends TestCase
{
    private function phar(): string
    {
        $phar = \dirname(__DIR__, 2).'/build/lockrot.phar';
        if (!is_file($phar)) {
            self::markTestSkipped('build/lockrot.phar not built; run build/build-phar.sh');
        }

        return $phar;
    }

    /** @param list<string> $arguments */
    private function runPhar(array $arguments): Process
    {
        $process = new Process(array_merge(['php', $this->phar()], $arguments));
        $process->setTimeout(300)->run();

        return $process;
    }

    public function testPharRunsOnFixtureDirectory(): void
    {
        $phar = $this->phar();
        $process = new Process(['php', $phar, '-d', \dirname(__DIR__).'/fixtures/skeletons/laravel', '--format=json', '--target-php=8.4']);
        $process->setTimeout(300)->run();
        $json = json_decode($process->getOutput(), true);
        self::assertIsArray($json, $process->getErrorOutput());
        $counts = $json['counts'];
        self::assertIsArray($counts);
        self::assertSame(0, $counts['abandoned']);
    }

    public function testListShowsOnlyTheTwoLockrotCommands(): void
    {
        $process = $this->runPhar(['list']);
        $output = $process->getOutput();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('self-update', $output);
        self::assertStringContainsString('lockrot', $output);
        // The PHAR is a Composer application; without an explicit command list it would also offer
        // to install, update and require packages in whatever project it is pointed at.
        foreach (['install', 'update', 'require', 'remove', 'dump-autoload'] as $composerCommand) {
            self::assertStringNotContainsString("\n  ".$composerCommand.' ', $output, $composerCommand.' must not be reachable from lockrot.phar');
        }
    }

    /**
     * Composer's application turns the scripts of whatever project it is run in into commands of
     * its own. This runs from lockrot's own checkout, whose composer.json defines `cs`, so a build
     * that stopped filtering registrations would list and run it.
     */
    public function testAProjectScriptIsNotAReachableCommand(): void
    {
        $listed = $this->runPhar(['list']);
        $invoked = $this->runPhar(['cs']);

        self::assertStringNotContainsString("\n  cs ", $listed->getOutput());
        self::assertNotSame(0, $invoked->getExitCode());
        self::assertStringContainsString('Command "cs" is not defined', $invoked->getOutput().$invoked->getErrorOutput());
    }

    public function testSelfUpdateHelpIsAvailable(): void
    {
        $process = $this->runPhar(['self-update', '--help']);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('--check', $process->getOutput());
        self::assertStringContainsString('--force', $process->getOutput());
    }

    public function testTheDefaultCommandStillRunsWithoutBeingNamed(): void
    {
        $process = $this->runPhar(['-d', \dirname(__DIR__).'/fixtures/skeletons/laravel', '--format=json', '--target-php=8.4', '--offline']);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertIsArray(json_decode($process->getOutput(), true), $process->getErrorOutput());
    }
}
