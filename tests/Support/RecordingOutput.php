<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A BufferedOutput that also keeps every message as it was handed over, before the formatter saw it.
 *
 * An undecorated formatter strips console tags, so the rendered buffer cannot tell
 * `<error>lockrot: msg</error>` from `<error>lockrot: </error>msg` — both come out as
 * `lockrot: msg`. The tags decide what Composer colours as the error and what it prints plain, so a
 * test that cares about them reads the message before formatting.
 */
final class RecordingOutput extends BufferedOutput
{
    /** @var list<string> every message write() let through at this verbosity, unformatted and in order */
    public array $raw = [];

    /** @param iterable<string>|string $messages */
    public function write($messages, bool $newline = false, int $options = self::OUTPUT_NORMAL): void
    {
        // The same test Output::write() applies before printing, so a line the verbosity drops is
        // not recorded either.
        $verbosities = self::VERBOSITY_QUIET | self::VERBOSITY_NORMAL | self::VERBOSITY_VERBOSE | self::VERBOSITY_VERY_VERBOSE | self::VERBOSITY_DEBUG;
        $verbosity = ($verbosities & $options) ?: self::VERBOSITY_NORMAL;
        if ($verbosity <= $this->getVerbosity()) {
            foreach (is_iterable($messages) ? $messages : [$messages] as $message) {
                $this->raw[] = $message;
            }
        }

        parent::write($messages, $newline, $options);
    }
}
