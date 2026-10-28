<?php

declare(strict_types=1);

namespace Lockrot\Composer;

use Lockrot\Config\Policy;
use Lockrot\Output\TerminalText;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A command line that a command cannot read, or that breaks one of its rules ({@see inputRefusal()}),
 * is exit 2 and one `lockrot: ...` line on stderr. Symfony binds the command line before
 * initialize() and lets the failure escape, and Composer's application then exits 1, which lockrot
 * reserves for findings. So the command binds it first, while it still owns the exit code. An exit 1
 * that is not lockrot's: docs/ci.md#exit-1-not-from-lockrot.
 *
 * @internal
 */
trait RejectsUnreadableInput
{
    /** Exit 2 with the reason when the command line cannot be bound or breaks a rule, null when it can run. */
    private function unreadableInput(InputInterface $input, OutputInterface $output): ?int
    {
        try {
            $this->mergeApplicationDefinition();
            $input->bind($this->getDefinition());
            $refusal = $this->inputRefusal($input);
        } catch (ExceptionInterface $e) {
            $refusal = $e->getMessage();
        }
        if ($refusal === null) {
            return null;
        }
        // Past the tag formatter: the message quotes the command line as typed (`--<fg=red>`),
        // which is not console markup ({@see TerminalText}).
        $target = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $target->writeln(TerminalText::error('lockrot: '.$refusal, $target->isDecorated()), OutputInterface::OUTPUT_RAW);

        return Policy::EXIT_ERROR;
    }

    /** The rule that a bound command line breaks, null when it breaks none. */
    protected function inputRefusal(InputInterface $input): ?string
    {
        return null;
    }

    /**
     * The first of $names that the command line gives more than once, as a refusal. The console
     * keeps only the last value of a repeated option, so a copy of the input is bound again with
     * each named option read as a list.
     *
     * @param list<string> $names options that require a value
     */
    private function repeatedOption(InputInterface $input, array $names): ?string
    {
        $definition = $this->getDefinition();
        $probe = array_values($definition->getArguments());
        foreach ($definition->getOptions() as $option) {
            $probe[] = \in_array($option->getName(), $names, true)
                ? new InputOption($option->getName(), $option->getShortcut(), InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, $option->getDescription())
                : $option;
        }
        $copy = clone $input;
        $copy->bind(new InputDefinition($probe));
        foreach ($names as $name) {
            if (\count((array) $copy->getOption($name)) > 1) {
                return '--'.$name.' is given twice';
            }
        }

        return null;
    }
}
