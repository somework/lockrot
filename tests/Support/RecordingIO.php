<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Composer\IO\BufferIO;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * A BufferIO that also keeps every stderr message as it was handed over, before Composer's output
 * formatter saw it — through writeError() or, bypassing the formatter, writeErrorRaw().
 *
 * `getOutput()` alone cannot tell `<warning>text</warning>` from `<warning>text` or from
 * `text<warning>`: an undecorated formatter removes the tags, so all three render as `text`. The
 * tags are what decides whether Composer colours the line and where the colouring stops, so a test
 * that cares about them has to read the message before formatting.
 */
final class RecordingIO extends BufferIO
{
    /** @var list<string> every message writeError() or writeErrorRaw() let through at this verbosity, unformatted and in order */
    public array $errors = [];

    /** Composer's verbosity levels against Symfony's, the way ConsoleIO::doWrite() maps them. */
    private const SYMFONY_VERBOSITY = [
        self::QUIET => OutputInterface::VERBOSITY_QUIET,
        self::NORMAL => OutputInterface::VERBOSITY_NORMAL,
        self::VERBOSE => OutputInterface::VERBOSITY_VERBOSE,
        self::VERY_VERBOSE => OutputInterface::VERBOSITY_VERY_VERBOSE,
        self::DEBUG => OutputInterface::VERBOSITY_DEBUG,
    ];

    public function __construct(int $verbosity = StreamOutput::VERBOSITY_NORMAL)
    {
        parent::__construct('', $verbosity);
    }

    /**
     * @param string|string[] $messages
     * @param bool            $newline
     * @param int             $verbosity
     *
     * @return void
     */
    public function writeError($messages, $newline = true, $verbosity = self::NORMAL)
    {
        $this->record($messages, (int) $verbosity);
        parent::writeError($messages, (bool) $newline, (int) $verbosity);
    }

    /**
     * ConsoleIO::writeErrorRaw() goes straight to the output, not through writeError(), so a message
     * is recorded once whichever way it came.
     *
     * @param string|string[] $messages
     * @param bool            $newline
     * @param int             $verbosity
     *
     * @return void
     */
    public function writeErrorRaw($messages, $newline = true, $verbosity = self::NORMAL)
    {
        $this->record($messages, (int) $verbosity);
        parent::writeErrorRaw($messages, (bool) $newline, (int) $verbosity);
    }

    /** @param string|string[] $messages */
    private function record($messages, int $verbosity): void
    {
        // Only what ConsoleIO would print: a debug-level line Composer writes on its own way through
        // must not count against a test that expects exactly one message.
        $wanted = self::SYMFONY_VERBOSITY[$verbosity] ?? OutputInterface::VERBOSITY_NORMAL;
        if ($wanted <= $this->output->getVerbosity()) {
            foreach (\is_array($messages) ? $messages : [$messages] as $message) {
                $this->errors[] = $message;
            }
        }
    }

    /** The one message written to stderr, failing the test when there was not exactly one. */
    public function onlyError(): string
    {
        if (\count($this->errors) !== 1) {
            throw new \LogicException('expected exactly one stderr message, got '.\count($this->errors).": \n".implode("\n", $this->errors));
        }

        return $this->errors[0];
    }
}
