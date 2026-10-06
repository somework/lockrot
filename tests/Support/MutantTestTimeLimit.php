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
 * A mutant that turns a loop into one that never ends holds a mutation thread until Infection's
 * timeout for that mutant, which on a line every integration test reaches is the configured cap; the
 * cap bounds the whole run of the mutant's covering tests, so it cannot come down. A single test can
 * be held far tighter: no covering test comes near the limit, so a test still running then is stuck,
 * and failing it is the detection the timeout would have made, sooner.
 *
 * PHPUnit's own time limit is not used because it stops a test by throwing an exception that extends
 * \RuntimeException: product code that catches \RuntimeException to degrade gracefully swallows it,
 * and a loop mutant inside such code never ends. Exiting from the signal handler cannot be caught.
 *
 * The alarm runs from a test's preparation to its end in this process, so it holds neither a class's
 * before-class methods nor a test in a separate process (PHPUnit bootstraps no extension in the
 * child, and the parent hears of the test only once the child is done); those keep Infection's
 * timeout. Only Infection's mutant processes, started with INFECTION=1, arm it: the initial run and
 * every ordinary test run are untouched. Without pcntl the extension does nothing.
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
