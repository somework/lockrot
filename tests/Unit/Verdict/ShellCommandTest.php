<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Lockrot\Verdict\ShellCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The three renderings of an argv list (SPEC-0.14.md §5.6): POSIX for the text formats, cmd.exe and
 * PowerShell for the copy menu. An argument comes from the lock or a repository, so each rendering
 * refuses an argv with an argument outside the command argument set.
 */
final class ShellCommandTest extends TestCase
{
    /** Each hostile argument, by its exploit class. */
    private const HOSTILE = [
        'cmd percent' => 'a/b:dev-x%CMDCMDLINE:~0,1%&calc&rem ',
        'cmd delayed expansion' => 'a/b:dev-x!PATH!',
        'carriage return' => "a/b\r",
        'line feed' => "a/b\ncalc",
        'nul' => "a/b\0",
        'typographic quote' => "a/b:x\u{2019};calc;\u{2019}",
        'bare stop-parsing token' => '--%',
        'option from data' => '--working-dir=x',
        'subshell' => 'a/b:v1.0.0+$(id)',
        'empty' => '',
        'spaces only' => '   ',
    ];

    public function testPosixLeavesASafeArgumentBareAndSingleQuotesTheRest(): void
    {
        self::assertSame("composer require 'twig/twig:^3.27.0'", ShellCommand::posix(['composer', 'require', 'twig/twig:^3.27.0']));
        self::assertSame("composer require 'php:>=8.1' --no-update", ShellCommand::posix(['composer', 'require', 'php:>=8.1', '--no-update']));
        self::assertSame('composer update a/b c/d', ShellCommand::posix(['composer', 'update', 'a/b', 'c/d']));
        self::assertSame("composer require 'a/b:^2.0 || ^3.0'", ShellCommand::posix(['composer', 'require', 'a/b:^2.0 || ^3.0']));
        self::assertSame("composer update 'a/b:~1.4' 'a/b:1.*'", ShellCommand::posix(['composer', 'update', 'a/b:~1.4', 'a/b:1.*']));
        self::assertSame('composer update a/b@1.0,c+e=f', ShellCommand::posix(['composer', 'update', 'a/b@1.0,c+e=f']));
    }

    /** zsh expands a bare word that starts with `=` to a command path. */
    public function testPosixSingleQuotesAnArgumentThatStartsWithAnEqualsSign(): void
    {
        self::assertSame("composer update '=a' a=b", ShellCommand::posix(['composer', 'update', '=a', 'a=b']));
    }

    public function testCmdDoubleQuotesAnArgumentWithASpaceOrAMetacharacter(): void
    {
        self::assertSame('composer require "twig/twig:^3.27.0"', ShellCommand::cmd(['composer', 'require', 'twig/twig:^3.27.0']));
        self::assertSame('composer require "php:>=8.1" --no-update', ShellCommand::cmd(['composer', 'require', 'php:>=8.1', '--no-update']));
        self::assertSame('composer update a/b', ShellCommand::cmd(['composer', 'update', 'a/b']));
        foreach (['a b', 'a|b', 'a<b', 'a>b', 'a^b'] as $argument) {
            self::assertSame('composer update "'.$argument.'"', ShellCommand::cmd(['composer', 'update', $argument]), $argument);
        }
        self::assertSame('composer update @name a,b a~b a*b', ShellCommand::cmd(['composer', 'update', '@name', 'a,b', 'a~b', 'a*b']));
    }

    /**
     * A bare `@name` splats, a bare `a,b` is an array, a bare `1.10` can read as a number, and
     * `composer.bat` hands a `|` to cmd.exe. After `--%`, PowerShell passes the cmd.exe rendering verbatim.
     */
    public function testPowershellWritesTheCmdRenderingAfterTheStopParsingToken(): void
    {
        self::assertSame('composer --% require "php:>=8.1" --no-update', ShellCommand::powershell(['composer', 'require', 'php:>=8.1', '--no-update']));
        self::assertSame('composer --% update "a/b|c"', ShellCommand::powershell(['composer', 'update', 'a/b|c']));
        self::assertSame('composer --% why-not a/b 1.10', ShellCommand::powershell(['composer', 'why-not', 'a/b', '1.10']));
        self::assertSame('composer --% update a/b', ShellCommand::powershell(['composer', 'update', 'a/b']));
        foreach (['@name', 'a,b', 'a+b', 'a=b', 'a~b', 'a*b'] as $argument) {
            self::assertSame('composer --% update '.$argument, ShellCommand::powershell(['composer', 'update', $argument]), $argument);
        }
        foreach (['a b', 'a^b', 'a|b', 'a<b', 'a>b'] as $argument) {
            self::assertSame('composer --% update "'.$argument.'"', ShellCommand::powershell(['composer', 'update', $argument]), $argument);
        }
    }

    /** @return iterable<string, array{string}> each hostile argument, by its exploit class */
    public static function hostile(): iterable
    {
        foreach (self::HOSTILE as $class => $argument) {
            yield $class => [$argument];
        }
    }

    /**
     * @dataProvider hostile
     */
    #[DataProvider('hostile')]
    public function testEveryRenderingRefusesAnArgvWithAHostileArgument(string $argument): void
    {
        $argv = ['composer', 'require', $argument];

        self::assertSame([null, null, null], [ShellCommand::posix($argv), ShellCommand::cmd($argv), ShellCommand::powershell($argv)]);
    }

    public function testEveryRenderingRefusesAnotherProgramOrSubcommand(): void
    {
        foreach ([['iex', 'calc'], ['composer', 'exec', 'calc']] as $argv) {
            self::assertSame([null, null, null], [ShellCommand::posix($argv), ShellCommand::cmd($argv), ShellCommand::powershell($argv)], implode(' ', $argv));
        }
    }
}
