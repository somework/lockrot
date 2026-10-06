<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Event\TestRunner\Finished as TestRunnerFinished;
use PHPUnit\Event\TestRunner\FinishedSubscriber as TestRunnerFinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Ends a mutant's test run as a failure once a single test has run for {@see self::SECONDS}.
 *
 * A mutant that turns a loop into one that never ends (a queue that never empties, a write offset
 * that never moves) holds one of the mutation job's threads until Infection's timeout for that
 * mutant: five times the time its covering tests took in the initial run, capped by infection.json5.
 * On a line every integration test reaches, that is the cap, which cannot come down because it bounds
 * the whole run of a mutant's covering tests. A single test can be held far tighter: no test that
 * covers a line comes near the limit below, so a test still running then is stuck, and failing it is
 * the same detection the timeout made, sooner. The process exits from the alarm instead of throwing:
 * code under test that catches \RuntimeException would swallow an exception and keep looping.
 * Measurements: PR #65.
 *
 * The alarm is armed when PHPUnit prepares a test in this process and cleared when it finishes, so
 * nothing else is held: not a class's setUpBeforeClass(), and not a test in a separate process, of
 * which the parent hears only once the child is done (PHPUnit bootstraps no extension in the child).
 * Those keep Infection's own timeout; no mutant's loop was found in either.
 *
 * Only Infection's mutant processes, which it starts with INFECTION=1, arm the alarm: the initial
 * run, where a corpus sweep covering nothing runs longer than the limit, and every ordinary test run are
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
