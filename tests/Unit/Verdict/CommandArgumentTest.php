<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Lockrot\Verdict\CommandArgument;
use PHPUnit\Framework\TestCase;

/** The command argument rule of SPEC-0.14.md §5.6: the one set that every argv lockrot writes passes. */
final class CommandArgumentTest extends TestCase
{
    public function testAnArgumentOfTheSetPasses(): void
    {
        foreach (['composer', 'symfony/yaml', 'php:>=8.1', 'twig/twig:^3.27.0', 'a/b:^2.0 || ^3.0', 'a/b:~1.4,<2.0', 'a/b:1.*', 'v1.0.0+build.1', '@name', 'a,b', 'x=y', 'dev-main', '--no-update'] as $argument) {
            self::assertTrue(CommandArgument::passes($argument), $argument);
        }
    }

    /** cmd.exe expands `%VAR%` inside double quotes. */
    public function testAPercentSignFails(): void
    {
        self::assertFalse(CommandArgument::passes('x%CMDCMDLINE:~0,1%&calc&rem '));
        self::assertFalse(CommandArgument::passes('dev-x%PATH%'));
    }

    /** cmd.exe expands `!VAR!` under delayed expansion. */
    public function testAnExclamationMarkFails(): void
    {
        self::assertFalse(CommandArgument::passes('dev-x!PATH!'));
    }

    /** A CR or LF splits a pasted line. */
    public function testALineBreakFails(): void
    {
        foreach (["a/b\r", "a/b\n", "a/b\ncalc", "\n"] as $argument) {
            self::assertFalse(CommandArgument::passes($argument), json_encode($argument, \JSON_THROW_ON_ERROR));
        }
    }

    public function testANulFails(): void
    {
        self::assertFalse(CommandArgument::passes("a/b\0"));
    }

    /** PowerShell closes a single-quoted string at U+2018 to U+201B, and a quote closes any quoting. */
    public function testEveryQuoteCharacterFails(): void
    {
        foreach (["x\u{2018};calc;\u{2018}", "x\u{2019};calc;\u{2019}", "x\u{201A}", "x\u{201B}", "it's", 'say "hi"', 'a`b'] as $argument) {
            self::assertFalse(CommandArgument::passes($argument), $argument);
        }
    }

    public function testAShellMetacharacterOutsideTheSetFails(): void
    {
        foreach (['v1.0.0+$(id)', 'a#b', 'a&b', 'a;b', 'a(b', 'a)b', "a\tb", 'é'] as $argument) {
            self::assertFalse(CommandArgument::passes($argument), $argument);
        }
    }

    /** A bare `--%` stops PowerShell parsing, and a name or version from data cannot pass an option to Composer. */
    public function testALeadingDashPassesOnlyAsTheOptionThatLockrotWrites(): void
    {
        foreach (['--%', '--working-dir=x', '-d', '-', '--no-update=1'] as $argument) {
            self::assertFalse(CommandArgument::passes($argument), $argument);
        }
        self::assertTrue(CommandArgument::passes('a-b'));
    }

    /** A package name from data never starts with `-`, not even as `--no-update`. */
    public function testANameFromDataPassesOnlyWithoutALeadingDash(): void
    {
        self::assertTrue(CommandArgument::passesName('a-b/c-d'));
        self::assertFalse(CommandArgument::passesName('--no-update'));
        self::assertFalse(CommandArgument::passesName('--working-dir=x'));
        self::assertFalse(CommandArgument::passesName('a/b%PATH%'));
    }

    public function testAnEmptyArgumentFails(): void
    {
        self::assertFalse(CommandArgument::passes(''));
    }

    /** Any other program or subcommand runs what the data names (`iex calc`, `composer exec calc`). */
    public function testAnArgvPassesOnlyAsComposerWithAnAllowedSubcommand(): void
    {
        foreach (['update', 'require', 'why-not'] as $subcommand) {
            self::assertTrue(CommandArgument::allPass(['composer', $subcommand, 'a/b']), $subcommand);
        }
        foreach ([['iex', 'calc'], ['composer', 'exec', 'calc'], ['composer', 'config', 'a', 'b'], ['bin/composer', 'update'], ['a,b', 'update'], ['-x', 'update'], ['composer'], []] as $argv) {
            self::assertFalse(CommandArgument::allPass($argv), implode(' ', $argv));
        }
    }

    public function testAnArgumentOfSpacesOnlyFails(): void
    {
        self::assertFalse(CommandArgument::passes(' '));
        self::assertFalse(CommandArgument::passes('   '));
        self::assertTrue(CommandArgument::passes('a/b:>=1.2 <2.0'));
    }

    public function testAnArgvPassesOnlyWhenEachArgumentPasses(): void
    {
        self::assertTrue(CommandArgument::allPass(['composer', 'require', 'php:>=8.1', '--no-update']));
        self::assertFalse(CommandArgument::allPass(['composer', 'why-not', 'a/b', '--working-dir=x']));
        self::assertFalse(CommandArgument::allPass(['composer', 'update', "a/b\n"]));
    }
}
