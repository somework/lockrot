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
    /** The Composer options that lockrot writes, by subcommand, after every package argument: anywhere else, a leading `-` came from data. */
    private const OPTIONS = [
        'update' => ['--with-dependencies' => true, '--with-all-dependencies' => true],
        'require' => ['--dev' => true, '--no-update' => true, '--with-dependencies' => true, '--with-all-dependencies' => true],
        'why-not' => ['--locked' => true],
    ];
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
        // An update of no package rewrites the lock's platform (`run.php_window.target_fix`).
        if ($argv === ['composer', 'update', '--lock']) {
            return true;
        }
        $package = false;
        $option = false;
        foreach (\array_slice($argv, 2) as $argument) {
            if (!self::passes($argument)) {
                return false;
            }
            $isOption = $argument[0] === '-';
            // An option before a package, or a package after an option, came from data.
            if ($isOption ? !isset(self::OPTIONS[$subcommand][$argument]) || !$package : $option) {
                return false;
            }
            if ($isOption) {
                $option = true;
            } else {
                $package = true;
            }
        }

        return true;
    }
}
