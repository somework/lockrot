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
 * A command line that a command cannot read is exit 2 and one `lockrot: ...` line on stderr.
 * Symfony binds the command line before initialize() and lets the failure escape, and Composer's
 * application then exits 1, which lockrot reserves for findings. So the command binds it first,
 * while it still owns the exit code. mergeApplicationDefinition() and bind() are public from
 * symfony/console 2.8 (Composer 2.2 LTS) on. An exit 1 that is not lockrot's:
 * docs/ci.md#exit-1-not-from-lockrot.
 *
 * @internal
 */
trait RejectsUnreadableInput
{
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
