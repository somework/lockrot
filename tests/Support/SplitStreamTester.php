<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\ArrayInput;

/**
 * A command tester that keeps stdout and stderr apart through {@see SplitStreamOutput}, as a CI step
 * reads them. It replaces CommandTester's `capture_stderr_separately` option, which triggers a
 * deprecation on PHP 8.5.
 */
final class SplitStreamTester
{
    private Command $command;
    private string $display = '';
    private string $errorOutput = '';

    public function __construct(Command $command)
    {
        $this->command = $command;
    }

    /**
     * @param array<string, mixed>     $input
     * @param array{decorated?: bool} $options as CommandTester takes them
     */
    public function execute(array $input, array $options = []): int
    {
        $arguments = new ArrayInput($input, $this->command->getDefinition());
        $arguments->setInteractive(false);
        $output = new SplitStreamOutput(new OutputFormatter($options['decorated'] ?? false));
        $code = $this->command->run($arguments, $output);
        $this->display = $output->fetch();
        $this->errorOutput = $output->fetchErrors();

        return $code;
    }

    /** stdout of the last run. */
    public function getDisplay(): string
    {
        return $this->display;
    }

    /** stderr of the last run. */
    public function getErrorOutput(): string
    {
        return $this->errorOutput;
    }
}
