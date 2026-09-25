<?php

declare(strict_types=1);

namespace Lockrot\Composer;

use Lockrot\Config\Policy;
use Lockrot\Output\TerminalText;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A command line one of lockrot's commands cannot read — an option it does not have, an option
 * without the value it needs, a value given to a flag, an argument too many — is a usage error, and
 * lockrot reports every configuration or usage error the same way: exit 2 and one `lockrot: ...`
 * line on stderr.
 *
 * Symfony's Command::run() binds the command line to the command's definition before initialize()
 * and execute() run, and lets the failure escape; Composer's application then renders it in a box
 * of its own and exits 1, which is the code lockrot reserves for findings. So the command binds it
 * first, the same way run() is about to, while it still owns the exit code. Binding twice is how
 * Symfony works anyway (the application binds its own definition first, the command binds again),
 * and mergeApplicationDefinition() and bind() are public from symfony/console 2.8 (Composer 2.2 LTS)
 * on.
 *
 * What never reaches a command stays outside lockrot: an unknown command name, or Composer stopping
 * before it has chosen a command, is Composer's exit 1.
 *
 * @internal
 */
trait RejectsUnreadableInput
{
    /** Exit 2 with the reason when the command line cannot be bound; null when it can. */
    private function unreadableInput(InputInterface $input, OutputInterface $output): ?int
    {
        try {
            $this->mergeApplicationDefinition();
            $input->bind($this->getDefinition());
        } catch (ExceptionInterface $e) {
            // Past the tag formatter: the message quotes the command line as typed (`--<fg=red>`),
            // which is not console markup ({@see TerminalText}).
            $target = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $target->writeln(TerminalText::error('lockrot: '.$e->getMessage(), $target->isDecorated()), OutputInterface::OUTPUT_RAW);

            return Policy::EXIT_ERROR;
        }

        return null;
    }
}
