<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Terminal;

/**
 * The terminal width, from the first source that answers (docs/example-run.md#the-table-format).
 *
 * lockrot runs under symfony/console 5.4 and 2.8 (the Composer 2.2 LTS PHAR). 2.8 has no Terminal
 * class, and 5.4 has no Application::getTerminalDimensions(), so each source is guarded and is a
 * method of its own.
 *
 * @internal
 */
final class TerminalWidth
{
    /** @param array<string, string> $env the process environment, as getenv() returns it */
    public static function detect(array $env, ?Application $application): int
    {
        $width = self::fromEnv($env) ?? self::fromConsoleTerminal($env) ?? self::fromApplication($application) ?? FormatContext::DEFAULT_WIDTH;

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
     * {@see fromEnv()}: `abc` becomes 0, and the clamp in {@see detect()} then picks the
     * narrowest width, not the default. So a `COLUMNS` that is set but rejected skips this step.
     *
     * @param array<string, string> $env
     */
    public static function fromConsoleTerminal(array $env): ?int
    {
        if (isset($env['COLUMNS']) || !class_exists(Terminal::class)) {
            return null;
        }

        return (new Terminal())->getWidth();
    }

    /**
     * symfony/console 2.8 `Application::getTerminalDimensions()`, which returns `[null, null]` when
     * it cannot tell. 5.4 has no such method, so it is called through reflection: a
     * `method_exists()` guard alone leaves the call unresolvable for static analysis.
     */
    public static function fromApplication(?Application $application): ?int
    {
        if ($application === null || !method_exists($application, 'getTerminalDimensions')) {
            return null;
        }
        $dimensions = (new \ReflectionMethod($application, 'getTerminalDimensions'))->invoke($application);
        if (!\is_array($dimensions) || !isset($dimensions[0]) || !\is_int($dimensions[0]) || $dimensions[0] <= 0) {
            return null;
        }

        return $dimensions[0];
    }
}
