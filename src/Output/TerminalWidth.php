<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Terminal;

/**
 * How wide the terminal `composer lockrot` is printing into is, in the four places the answer can
 * come from, best first.
 *
 * lockrot runs under two very different symfony/console versions: 5.4 under Composer 2.10, and 2.8
 * inside the Composer 2.2 LTS PHAR. 2.8 has no Terminal class at all — it answers the same question
 * through Application::getTerminalDimensions(), a method 5.4 does not have. Neither call can be
 * made unconditionally, so each source is guarded and each is a method of its own: under 5.4 the
 * Terminal step always answers, which makes the two later steps unreachable from this side.
 */
final class TerminalWidth
{
    /**
     * COLUMNS first, then this console version's own idea of the width, then the default — clamped
     * so a terminal too narrow to render a list into is treated as no answer at all.
     *
     * @param array<string, string> $env the process environment, as getenv() returns it
     */
    public static function detect(array $env, ?Application $application): int
    {
        $width = self::fromEnv($env) ?? self::fromConsoleTerminal($env) ?? self::fromApplication($application) ?? FormatContext::DEFAULT_WIDTH;

        return max(FormatContext::MIN_WIDTH, $width);
    }

    /**
     * COLUMNS, when it is a plain positive integer. Anything else — unset, empty, padded, fractional
     * or non-numeric — is not an answer, rather than an answer of zero.
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
     * symfony/console 5.4 `Terminal::getWidth()`; the class does not exist in 2.8.
     *
     * Terminal reads `COLUMNS` itself, and far more leniently than {@see fromEnv()} does — whenever
     * the variable is merely present it answers `(int) trim($value)`, so `abc` becomes 0 and the
     * clamp in {@see detect()} would turn that into the narrowest width lockrot accepts instead of
     * the documented default. A `COLUMNS` step 1 has already looked at and rejected is therefore
     * out of play for this step too: handing it over would only get the same junk read a second
     * time, by a reader that does not check it.
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
     * symfony/console 2.8 `Application::getTerminalDimensions(): array{width, height}`, which
     * returns `[null, null]` when it could not tell. The method is gone in 5.4, so it is reached
     * through reflection rather than named on a type that no longer declares it — a
     * `method_exists()` guard alone would leave the call itself unresolvable.
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
