<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Version;
use Symfony\Component\Process\Process;

/**
 * Each case runs PHPUnit on one class of tests/fixtures/mutant-time-limit, whose configuration
 * sets the limit to one second. The process timeout stops a run that the limit does not stop.
 */
final class MutantTestTimeLimitTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../fixtures/mutant-time-limit/';
    private const PROCESS_TIMEOUT_SECONDS = 30;

    protected function setUp(): void
    {
        if (!interface_exists(Extension::class) || !\function_exists('pcntl_alarm')) {
            self::markTestSkipped('the limit is a PHPUnit 10 extension that needs pcntl');
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function loops(): iterable
    {
        yield 'a loop in setUpBeforeClass()' => ['LoopInSetUpBeforeClassTest', 'Lockrot\Tests\Fixtures\MutantTimeLimit\LoopInSetUpBeforeClassTest ran past 1 seconds'];
        yield 'a loop in a data provider' => ['LoopInDataProviderTest', 'Lockrot\Tests\Fixtures\MutantTimeLimit\LoopInDataProviderTest::rows ran past 1 seconds'];
        yield 'a loop in a test' => ['LoopInTestTest', 'Lockrot\Tests\Fixtures\MutantTimeLimit\LoopInTestTest::testLoops ran past 1 seconds'];
    }

    /**
     * @dataProvider loops
     */
    #[DataProvider('loops')]
    public function testAMutantProcessStopsALoopAtTheLimit(string $class, string $message): void
    {
        if ($class === 'LoopInDataProviderTest' && Version::majorVersionNumber() === 10) {
            self::markTestSkipped('PHPUnit 10 calls the data providers before it boots the extensions, so no event can set the alarm there');
        }

        $process = self::phpunitOn($class);

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString($message, $process->getErrorOutput());
    }

    /** The class's setUpBeforeClass() and its test each take most of the limit, and together more. */
    public function testEachTestAndSetUpBeforeClassGetTheirOwnLimit(): void
    {
        $process = self::phpunitOn('QuickTest');

        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
    }

    private static function phpunitOn(string $class): Process
    {
        $process = new Process([\PHP_BINARY, 'vendor/bin/phpunit', '-c', self::FIXTURES.'phpunit.xml', self::FIXTURES.$class.'.php'], __DIR__.'/../../..', ['INFECTION' => '1']);
        $process->setTimeout(self::PROCESS_TIMEOUT_SECONDS);
        $process->run();

        return $process;
    }
}
