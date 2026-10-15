<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Verdict\CommandArgument;

/**
 * The renderings of an argv list (SPEC-0.14.md §5.6). The text formats print the POSIX rendering,
 * and the html copy menu offers cmd.exe and PowerShell. Each rendering is null for an argv with an
 * argument outside {@see CommandArgument}: no quoting is safe for it on every shell.
 *
 * @internal
 */
final class ShellCommand
{
    private const POSIX_SAFE = '~^[A-Za-z0-9/._:@%+=,-]+$~D';
    private const CMD_SPECIAL = '~[ ^|<>]~';
    private const POWERSHELL_SAFE = '~^[A-Za-z0-9/._:-]+$~D';

    /** @param list<string> $argv */
    public static function posix(array $argv): ?string
    {
        return self::rendered($argv, static fn (string $argument): string => preg_match(self::POSIX_SAFE, $argument) === 1 ? $argument : "'".$argument."'");
    }

    /** @param list<string> $argv */
    public static function cmd(array $argv): ?string
    {
        return self::rendered($argv, static fn (string $argument): string => preg_match(self::CMD_SPECIAL, $argument) === 1 ? '"'.$argument.'"' : $argument);
    }

    /**
     * A bare `@name` splats and a bare `a,b` is an array, so the bare set is narrower than POSIX's.
     *
     * @param list<string> $argv
     */
    public static function powershell(array $argv): ?string
    {
        return self::rendered($argv, static fn (string $argument): string => preg_match(self::POWERSHELL_SAFE, $argument) === 1 ? $argument : "'".$argument."'");
    }

    /**
     * @param list<string>             $argv
     * @param callable(string): string $quoted
     */
    private static function rendered(array $argv, callable $quoted): ?string
    {
        return CommandArgument::allPass($argv) ? implode(' ', array_map($quoted, $argv)) : null;
    }
}
