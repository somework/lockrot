<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

/**
 * The renderings of an argv list (SPEC-0.14.md §5.6). The text formats print the POSIX rendering,
 * and the html copy menu offers cmd.exe and PowerShell. Each rendering is null for an argv that
 * {@see CommandArgument} refuses: no quoting is safe for it on every shell.
 *
 * @internal
 */
final class ShellCommand
{
    /** zsh expands a bare word that starts with `=` to a command path. */
    private const POSIX_SAFE = '~^(?!=)[A-Za-z0-9/._:@%+=,-]+$~D';
    private const CMD_SPECIAL = '~[ ^|<>]~';

    /** @param list<string> $argv */
    public static function posix(array $argv): ?string
    {
        return CommandArgument::allPass($argv) ? implode(' ', array_map(static fn (string $argument): string => preg_match(self::POSIX_SAFE, $argument) === 1 ? $argument : "'".$argument."'", $argv)) : null;
    }

    /** @param list<string> $argv */
    public static function cmd(array $argv): ?string
    {
        return CommandArgument::allPass($argv) ? self::cmdWords($argv) : null;
    }

    /**
     * The target is often `composer.bat`, and cmd.exe parses `|`, `<`, `>` and `^` again in an
     * argument that PowerShell does not double-quote. PowerShell can also read a bare `1.10` as a
     * number. After `--%`, it passes the cmd.exe rendering verbatim to an `.exe` or a `.bat` target.
     *
     * @param list<string> $argv
     */
    public static function powershell(array $argv): ?string
    {
        return CommandArgument::allPass($argv) ? $argv[0].' --% '.self::cmdWords(\array_slice($argv, 1)) : null;
    }

    /** @param list<string> $words */
    private static function cmdWords(array $words): string
    {
        return implode(' ', array_map(static fn (string $word): string => preg_match(self::CMD_SPECIAL, $word) === 1 ? '"'.$word.'"' : $word, $words));
    }
}
