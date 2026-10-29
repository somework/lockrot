<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Symfony\Component\Console\Terminal;

/**
 * The terminal width, from the first source that answers (docs/example-run.md#the-table-format).
 *
 * @internal
 */
final class TerminalWidth
{
    /** @param array<string, string> $env the process environment, as getenv() returns it */
    public static function detect(array $env): int
    {
        $width = self::fromEnv($env) ?? self::fromConsoleTerminal($env) ?? FormatContext::DEFAULT_WIDTH;

        return max(FormatContext::MIN_WIDTH, $width);
    }

    /**
     * COLUMNS, when it is a plain positive integer. Anything else is no answer, not zero.
     *
     * @param array<string, string> $env
     */
    public static function fromEnv(array $env): ?int
    {
        $columns = $env['COLUMNS'] ?? null;
        if (!\is_string($columns) || preg_match('/^[1-9][0-9]*$/', $columns) !== 1) {
            return null;
        }

        return (int) $columns;
    }

    /**
     * symfony/console 5.4 `Terminal::getWidth()`. Terminal reads `COLUMNS` more leniently than
     * {@see fromEnv()}: `abc` becomes 0, and with 0 the clamp in {@see detect()} picks the
     * narrowest width, not the default. So a `COLUMNS` that is set but rejected skips this step.
     *
     * @param array<string, string> $env
     */
    public static function fromConsoleTerminal(array $env): ?int
    {
        if (isset($env['COLUMNS'])) {
            return null;
        }

        return (new Terminal())->getWidth();
    }
}
