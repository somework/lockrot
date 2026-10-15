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
    /** zsh expands a bare word that starts with `=` to a command path. */
    private const POSIX_SAFE = '~^(?!=)[A-Za-z0-9/._:@%+=,-]+$~D';
    private const CMD_SPECIAL = '~[ ^|<>]~';
    private const POWERSHELL_BARE = '~^[A-Za-z0-9/._:-]+$~D';

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
     * The target is often `composer.bat`, and cmd.exe parses `|`, `<`, `>` and `^` again in an
     * argument that PowerShell does not double-quote. After `--%`, PowerShell passes the cmd.exe
     * rendering verbatim to an `.exe` or a `.bat` target.
     *
     * @param list<string> $argv
     */
    public static function powershell(array $argv): ?string
    {
        if (!CommandArgument::allPass($argv)) {
            return null;
        }
        if (preg_grep(self::POWERSHELL_BARE, $argv) === $argv) {
            return implode(' ', $argv);
        }

        return $argv[0].' --% '.self::cmd(\array_slice($argv, 1));
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
