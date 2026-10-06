<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Event\TestRunner\Finished as TestRunnerFinished;
use PHPUnit\Event\TestRunner\FinishedSubscriber as TestRunnerFinishedSubscriber;
use PHPUnit\Event\TestSuite\Started as TestSuiteStarted;
use PHPUnit\Event\TestSuite\StartedSubscriber as TestSuiteStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Ends a mutant's test run as a failure once a single test, or a class's before-class methods, has
 * run for {@see self::SECONDS}.
 *
 * A mutant that turns a loop into one that never ends (a queue that never empties, a write offset
 * that never moves) holds one of the mutation job's threads until Infection's timeout for that
 * mutant: five times the time its covering tests took in the initial run, capped by infection.json5.
 * On a line every integration test reaches, that is the cap. On 2026-10-06 eight such mutants, four
 * in each of the `data` and `verdicts` shards, held a thread for 180 seconds each. The cap bounds
 * the whole run of a mutant's covering tests, which on those lines takes about a minute locally and
 * more on a slow runner, so it cannot come down; a single test can be held far tighter. The slowest
 * test that covers any line takes under 4 seconds (FindingFactsConsistencyTest), so a test still
 * running after 60 seconds is stuck, and failing it there is the same detection the timeout made,
 * sooner. A mutant whose loop sits on a line only fast tests reach already gets a timeout of a few
 * seconds from Infection, so the alarm never comes into play for it.
 *
 * A test that runs in a separate process is not held: PHPUnit bootstraps no extension in the child,
 * and the parent only hears of the test once the child is done. Such a test keeps Infection's timeout.
 *
 * Only Infection's mutant processes, which it starts with INFECTION=1, arm the alarm: the initial
 * run, where a corpus sweep covering nothing takes over 30 seconds, and every ordinary test run are
 * untouched. Without pcntl the extension does nothing, and Infection's own timeout still applies.
 *
 * Registered in phpunit.xml.dist, so a class that fails to load fails every ordinary test run too;
 * PHPUnit 10 or later (phpunit9.xml.dist leaves it out).
 */
final class MutantTestTimeLimit implements Extension
{
    public const SECONDS = 60;

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        if (getenv('INFECTION') !== '1' || !\function_exists('pcntl_alarm')) {
            return;
        }

        $running = new \ArrayObject(['test' => '']);
        pcntl_async_signals(true);
        pcntl_signal(\SIGALRM, static function () use ($running): void {
            fwrite(\STDERR, \sprintf("\n%s ran past %d seconds: stopped as a test that never ends.\n", $running['test'], self::SECONDS));

            exit(1);
        });

        $facade->registerSubscribers(
            // A suite starts before its class's setUpBeforeClass() runs; the event named for the
            // before-class methods only fires once they have returned.
            new class ($running) implements TestSuiteStartedSubscriber {
                /** @var \ArrayObject<string, string> */
                private \ArrayObject $running;

                /** @param \ArrayObject<string, string> $running */
                public function __construct(\ArrayObject $running)
                {
                    $this->running = $running;
                }

                public function notify(TestSuiteStarted $event): void
                {
                    $this->running['test'] = $event->testSuite()->name();
                    pcntl_alarm(MutantTestTimeLimit::SECONDS);
                }
            },
            new class ($running) implements PreparationStartedSubscriber {
                /** @var \ArrayObject<string, string> */
                private \ArrayObject $running;

                /** @param \ArrayObject<string, string> $running */
                public function __construct(\ArrayObject $running)
                {
                    $this->running = $running;
                }

                public function notify(PreparationStarted $event): void
                {
                    $this->running['test'] = $event->test()->id();
                    pcntl_alarm(MutantTestTimeLimit::SECONDS);
                }
            },
            new class () implements FinishedSubscriber {
                public function notify(Finished $event): void
                {
                    pcntl_alarm(0);
                }
            },
            new class () implements TestRunnerFinishedSubscriber {
                public function notify(TestRunnerFinished $event): void
                {
                    pcntl_alarm(0);
                }
            }
        );
    }
}
