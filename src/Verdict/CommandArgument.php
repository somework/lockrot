<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

/**
 * The command argument rule (SPEC-0.14.md §5.6). An argument comes from the lock or a repository, and
 * a reader pastes the command into cmd.exe, PowerShell or a POSIX shell. Every rendering quotes an
 * argument of this set safely. A leading `-` is only the Composer option that lockrot writes, so
 * data cannot pass an option to Composer. Any other program or subcommand runs what the data names
 * (`composer exec calc`).
 *
 * @internal
 */
final class CommandArgument
{
    private const OPTIONS = ['--no-update' => true];
    private const SUBCOMMANDS = ['update' => true, 'require' => true, 'why-not' => true];
    private const SET = '{^(?! +$)[A-Za-z0-9/._:@+=,^~*<>| -]+$}D';

    public static function passes(string $argument): bool
    {
        return preg_match(self::SET, $argument) === 1 && ($argument[0] !== '-' || isset(self::OPTIONS[$argument]));
    }

    /** A whole argument that a package name fills: data never passes an option, `--no-update` included. */
    public static function passesName(string $name): bool
    {
        return self::passes($name) && $name[0] !== '-';
    }

    /** @param list<string> $argv `composer`, its subcommand, then the arguments */
    public static function allPass(array $argv): bool
    {
        return ($argv[0] ?? null) === 'composer'
            && isset(self::SUBCOMMANDS[$argv[1] ?? ''])
            && array_filter($argv, static fn (string $argument): bool => !self::passes($argument)) === [];
    }
}
