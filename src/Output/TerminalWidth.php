<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Terminal;

/**
 * How wide the terminal `composer lockrot` is printing into is, in the four places the answer can
 * come from, best first.
 *
 * lockrot runs under two very different symfony/console versions: 5.4 under Composer 2.10, and
 * 2.8.52 inside the Composer 2.2 LTS PHAR. 2.8 has no Terminal class at all — it answers the same
 * question through Application::getTerminalDimensions() (2.8.52 Application.php:736), a method 5.4
 * no longer has. Neither call can be made unconditionally, so each source is guarded and each is a
 * method of its own: under 5.4 the Terminal step always answers, which makes the two later steps
 * unreachable from this side, and testing them at all means testing them directly.
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
        $width = self::fromEnv($env) ?? self::fromConsoleTerminal() ?? self::fromApplication($application) ?? FormatContext::DEFAULT_WIDTH;

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

    /** symfony/console 5.4 `Terminal::getWidth()` (Terminal.php:25); the class does not exist in 2.8. */
    public static function fromConsoleTerminal(): ?int
    {
        if (!class_exists(Terminal::class)) {
            return null;
        }

        return (new Terminal())->getWidth();
    }

    /**
     * symfony/console 2.8 `Application::getTerminalDimensions(): array{width, height}`
     * (2.8.52 Application.php:736), which returns `[null, null]` when it could not tell. The method
     * is gone in 5.4, so it is reached through reflection rather than named on a type that no longer
     * declares it — a `method_exists()` guard alone would leave the call itself unresolvable.
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
