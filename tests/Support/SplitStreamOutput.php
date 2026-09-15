<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A buffered stdout with a genuinely separate buffered stderr, so a test can assert that a command
 * keeps stdout clean.
 *
 * CommandTester's `capture_stderr_separately` option would do the same, but on PHP 8.5 it triggers
 * a `ReflectionProperty::setAccessible()` deprecation inside
 * Symfony\Component\Console\Tester\TesterTrait::initOutput(), which pollutes otherwise-pristine
 * test output. Command::run() only needs an InputInterface and an OutputInterface, so this double
 * gives the same stream separation directly.
 */
final class SplitStreamOutput extends BufferedOutput implements ConsoleOutputInterface
{
    private OutputInterface $errorOutput;

    public function __construct()
    {
        parent::__construct();
        $this->errorOutput = new BufferedOutput();
    }

    public function getErrorOutput(): OutputInterface
    {
        return $this->errorOutput;
    }

    public function setErrorOutput(OutputInterface $error): void
    {
        $this->errorOutput = $error;
    }

    public function section(): ConsoleSectionOutput
    {
        throw new \LogicException('ConsoleSectionOutput is not supported by this test double');
    }

    /** Everything written to stderr so far, draining the buffer as BufferedOutput::fetch() does. */
    public function fetchErrors(): string
    {
        $error = $this->errorOutput;

        return $error instanceof BufferedOutput ? $error->fetch() : '';
    }
}
