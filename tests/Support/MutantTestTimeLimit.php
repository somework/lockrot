<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use PHPUnit\Event\Test\DataProviderMethodCalled;
use PHPUnit\Event\Test\DataProviderMethodCalledSubscriber;
use PHPUnit\Event\Test\DataProviderMethodFinished;
use PHPUnit\Event\Test\DataProviderMethodFinishedSubscriber;
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
 * PHPUnit's own time limit throws a \RuntimeException subclass, which product code can catch, so a
 * loop mutant there never ends. The signal handler exits instead, and nothing can catch an exit.
 * PHPUnit runs setUpBeforeClass() and the data providers outside a test, so the suite start and each
 * provider call set the alarm too. PHPUnit 10 calls the providers before it boots the extensions,
 * so on PHPUnit 10 the limit misses a provider. Without pcntl the extension does nothing.
 * Reasons and measurements: https://github.com/somework/lockrot/pull/65.
 */
final class MutantTestTimeLimit implements Extension
{
    public const SECONDS = 60;

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        if (getenv('INFECTION') !== '1' || !\function_exists('pcntl_alarm')) {
            return;
        }

        $seconds = $parameters->has('seconds') ? max(1, (int) $parameters->get('seconds')) : self::SECONDS;
        $running = new \ArrayObject(['what' => '']);
        $start = static function (string $what) use ($running, $seconds): void {
            $running['what'] = $what;
            pcntl_alarm($seconds);
        };
        $stop = static function (): void {
            pcntl_alarm(0);
        };
        pcntl_async_signals(true);
        pcntl_signal(\SIGALRM, static function () use ($running, $seconds): void {
            fwrite(\STDERR, \sprintf("\n%s ran past %d seconds: stopped as a test that never ends.\n", $running['what'], $seconds));

            exit(1);
        });

        $facade->registerSubscribers(
            new class ($start) implements TestSuiteStartedSubscriber {
                /** @var \Closure(string): void */
                private \Closure $start;

                /** @param \Closure(string): void $start */
                public function __construct(\Closure $start)
                {
                    $this->start = $start;
                }

                public function notify(TestSuiteStarted $event): void
                {
                    ($this->start)($event->testSuite()->name());
                }
            },
            new class ($start) implements DataProviderMethodCalledSubscriber {
                /** @var \Closure(string): void */
                private \Closure $start;

                /** @param \Closure(string): void $start */
                public function __construct(\Closure $start)
                {
                    $this->start = $start;
                }

                public function notify(DataProviderMethodCalled $event): void
                {
                    ($this->start)($event->dataProviderMethod()->className().'::'.$event->dataProviderMethod()->methodName());
                }
            },
            new class ($start) implements PreparationStartedSubscriber {
                /** @var \Closure(string): void */
                private \Closure $start;

                /** @param \Closure(string): void $start */
                public function __construct(\Closure $start)
                {
                    $this->start = $start;
                }

                public function notify(PreparationStarted $event): void
                {
                    ($this->start)($event->test()->id());
                }
            },
            new class ($stop) implements DataProviderMethodFinishedSubscriber {
                /** @var \Closure(): void */
                private \Closure $stop;

                /** @param \Closure(): void $stop */
                public function __construct(\Closure $stop)
                {
                    $this->stop = $stop;
                }

                public function notify(DataProviderMethodFinished $event): void
                {
                    ($this->stop)();
                }
            },
            new class ($stop) implements FinishedSubscriber {
                /** @var \Closure(): void */
                private \Closure $stop;

                /** @param \Closure(): void $stop */
                public function __construct(\Closure $stop)
                {
                    $this->stop = $stop;
                }

                public function notify(Finished $event): void
                {
                    ($this->stop)();
                }
            },
            new class ($stop) implements TestRunnerFinishedSubscriber {
                /** @var \Closure(): void */
                private \Closure $stop;

                /** @param \Closure(): void $stop */
                public function __construct(\Closure $stop)
                {
                    $this->stop = $stop;
                }

                public function notify(TestRunnerFinished $event): void
                {
                    ($this->stop)();
                }
            }
        );
    }
}
