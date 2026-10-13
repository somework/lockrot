<?php

declare(strict_types=1);

namespace Lockrot\Output;

/**
 * The renderings of an argv list. The text formats
 * print the POSIX rendering. An argument from the lock or a repository can hold any character, so
 * each one is quoted where a shell would read it.
 *
 * @internal
 */
final class ShellCommand
{
    private const POSIX_SAFE = '~^[A-Za-z0-9/._:@%+=,-]+$~D';
    private const CMD_SPECIAL = '~[\s^&|<>()%!"]~';

    /** @param list<string> $argv */
    public static function posix(array $argv): string
    {
        return implode(' ', array_map(static fn (string $argument): string => preg_match(self::POSIX_SAFE, $argument) === 1 ? $argument : "'".str_replace("'", "'\\''", $argument)."'", $argv));
    }

    /** @param list<string> $argv */
    public static function cmd(array $argv): string
    {
        return implode(' ', array_map(static fn (string $argument): string => $argument === '' || preg_match(self::CMD_SPECIAL, $argument) === 1 ? '"'.str_replace('"', '""', $argument).'"' : $argument, $argv));
    }
}
