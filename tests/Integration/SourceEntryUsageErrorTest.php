<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * A usage error that stops bin/lockrot before a command runs is exit 2 with a `lockrot:` line, as
 * docs/ci.md#exit-codes states: exit 1 is the gate's.
 */
final class SourceEntryUsageErrorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/lockrot-usage-error-'.bin2hex(random_bytes(8));
        mkdir($this->dir);
        mkdir($this->dir.'/home');
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->dir]))->run();
    }

    /** @return iterable<string, array{0: list<string>, 1: string}> */
    public static function usageErrors(): iterable
    {
        yield 'an unknown command' => [['nope'], 'lockrot: Command "nope" is not defined.'];
        yield 'a value after a space that the console reads as the command' => [['--output', 'json:r.json'], 'lockrot: There are no commands defined in the "json" namespace.'];
        yield 'a --working-dir that does not exist' => [['--working-dir={dir}/missing'], 'lockrot: --working-dir names a directory that does not exist: {dir}/missing'];
        yield 'a -d that does not exist, before the command' => [['-d', '{dir}/missing', 'lockrot'], 'lockrot: --working-dir names a directory that does not exist: {dir}/missing'];
        yield 'help for an unknown command' => [['help', 'nope'], 'lockrot: Command "nope" is not defined.'];
        yield 'list of an unknown namespace' => [['list', 'nosuch'], 'lockrot: There are no commands defined in the "nosuch" namespace.'];
        yield 'help with an unknown option' => [['help', '--nope'], 'lockrot: The "--nope" option does not exist.'];
        yield 'help for a command with an extra argument' => [['help', 'list', 'extra'], 'lockrot: Too many arguments to "help" command, expected arguments "command_name".'];
        yield 'help for an unknown command with an extra argument' => [['help', 'nope', 'extra'], 'lockrot: Command "nope" is not defined.'];
    }

    /** @return iterable<string, array{list<string>}> */
    public static function validHelpAndList(): iterable
    {
        yield 'help for a command' => [['help', 'list']];
        yield 'help for the lockrot command' => [['help', 'lockrot']];
        yield 'list with an option and no namespace' => [['list', '--raw']];
    }

    /**
     * @dataProvider validHelpAndList
     *
     * @param list<string> $arguments
     */
    #[DataProvider('validHelpAndList')]
    public function testHelpAndListOfAKnownNameExitZero(array $arguments): void
    {
        $process = $this->lockrot($arguments);

        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertNotSame('', $process->getOutput());
    }

    /**
     * @dataProvider usageErrors
     *
     * @param list<string> $arguments
     */
    #[DataProvider('usageErrors')]
    public function testAUsageErrorBeforeTheCommandIsExitTwoWithOneLockrotLine(array $arguments, string $line): void
    {
        $process = $this->lockrot(str_replace('{dir}', $this->dir, $arguments));

        self::assertSame(2, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertSame('', $process->getOutput());
        self::assertSame(str_replace('{dir}', $this->dir, $line)."\n", $process->getErrorOutput());
    }

    /** A console exception that parent::doRun() throws is exit 2 too, not the gate's exit 1. */
    public function testAConsoleExceptionFromTheConsoleIsExitTwoWithOneLockrotLine(): void
    {
        $process = $this->lockrot(['list', '--nope']);

        self::assertSame(2, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertSame('', $process->getOutput());
        self::assertSame('lockrot: The "--nope" option does not exist.'."\n", $process->getErrorOutput());
    }

    /** @param list<string> $arguments */
    private function lockrot(array $arguments): Process
    {
        $process = new Process(
            array_merge([\PHP_BINARY, \dirname(__DIR__, 2).'/bin/lockrot'], $arguments),
            $this->dir,
            ['COMPOSER_HOME' => $this->dir.'/home', 'COMPOSER_CACHE_DIR' => $this->dir.'/home/cache', 'GITHUB_ACTIONS' => false]
        );
        $process->setTimeout(60)->run();

        return $process;
    }
}
