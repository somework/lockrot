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
 * Fails a test that still runs after {@see self::SECONDS} in an Infection mutant process (INFECTION=1).
 *
 * PHPUnit's own time limit throws a \RuntimeException subclass. Product code that catches
 * \RuntimeException swallows it, and a loop mutant there never ends. An exit from the signal handler
 * cannot be caught. Without pcntl the extension does nothing. Reasons and measurements: https://github.com/somework/lockrot/pull/65.
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
