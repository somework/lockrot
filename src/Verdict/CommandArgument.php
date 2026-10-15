<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

/**
 * The command argument rule (SPEC-0.14.md §5.6). An argument comes from the lock or a repository, and
 * a reader pastes the command into cmd.exe, PowerShell or a POSIX shell. Every rendering quotes an
 * argument of this set safely. Any other program or subcommand runs what the data names
 * (`composer exec calc`).
 *
 * @internal
 */
final class CommandArgument
{
    /** The Composer option that lockrot writes, by its subcommand: anywhere else, a leading `-` came from data. */
    private const OPTIONS = ['require' => ['--no-update' => true]];
    private const SUBCOMMANDS = ['update' => true, 'require' => true, 'why-not' => true];
    private const SET = '{^(?! +$)[A-Za-z0-9/._:@+=,^~*<>| -]+$}D';

    public static function passes(string $argument): bool
    {
        return preg_match(self::SET, $argument) === 1;
    }

    /** @param list<string> $argv `composer`, its subcommand, then the arguments */
    public static function allPass(array $argv): bool
    {
        $subcommand = $argv[1] ?? '';
        if (($argv[0] ?? null) !== 'composer' || !isset(self::SUBCOMMANDS[$subcommand])) {
            return false;
        }
        foreach ($argv as $argument) {
            if (!self::passes($argument) || ($argument[0] === '-' && !isset(self::OPTIONS[$subcommand][$argument]))) {
                return false;
            }
        }

        return true;
    }
}
