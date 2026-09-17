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
    /** @var list<string> every message passed to write(), unformatted and in order */
    public array $raw = [];

    /** @param iterable<string>|string $messages */
    public function write($messages, bool $newline = false, int $options = self::OUTPUT_NORMAL): void
    {
        foreach (is_iterable($messages) ? $messages : [$messages] as $message) {
            $this->raw[] = $message;
        }

        parent::write($messages, $newline, $options);
    }
}
