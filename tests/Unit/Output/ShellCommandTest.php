<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Output\ShellCommand;
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
    ];

    public function testPosixLeavesASafeArgumentBareAndSingleQuotesTheRest(): void
    {
        self::assertSame("composer require 'twig/twig:^3.27.0'", ShellCommand::posix(['composer', 'require', 'twig/twig:^3.27.0']));
        self::assertSame("composer require 'php:>=8.1' --no-update", ShellCommand::posix(['composer', 'require', 'php:>=8.1', '--no-update']));
        self::assertSame('composer update a/b c/d', ShellCommand::posix(['composer', 'update', 'a/b', 'c/d']));
        self::assertSame("composer require 'a/b:^2.0 || ^3.0'", ShellCommand::posix(['composer', 'require', 'a/b:^2.0 || ^3.0']));
        self::assertSame("x 'a/b:~1.4' 'a/b:1.*'", ShellCommand::posix(['x', 'a/b:~1.4', 'a/b:1.*']));
        self::assertSame('a/b@1.0,c+e=f', ShellCommand::posix(['a/b@1.0,c+e=f']));
    }

    public function testCmdDoubleQuotesAnArgumentWithASpaceOrAMetacharacter(): void
    {
        self::assertSame('composer require "twig/twig:^3.27.0"', ShellCommand::cmd(['composer', 'require', 'twig/twig:^3.27.0']));
        self::assertSame('composer require "php:>=8.1" --no-update', ShellCommand::cmd(['composer', 'require', 'php:>=8.1', '--no-update']));
        self::assertSame('composer update a/b', ShellCommand::cmd(['composer', 'update', 'a/b']));
        foreach (['a b', 'a|b', 'a<b', 'a>b', 'a^b'] as $argument) {
            self::assertSame('x "'.$argument.'"', ShellCommand::cmd(['x', $argument]), $argument);
        }
        self::assertSame('x @name a,b a~b a*b', ShellCommand::cmd(['x', '@name', 'a,b', 'a~b', 'a*b']));
    }

    /** A bare `@name` splats and a bare `a,b` is an array, so both are single-quoted. */
    public function testPowershellSingleQuotesAnArgumentOutsideItsBareSet(): void
    {
        self::assertSame("composer require 'php:>=8.1' --no-update", ShellCommand::powershell(['composer', 'require', 'php:>=8.1', '--no-update']));
        self::assertSame('composer update a/b c-d.e_f:1', ShellCommand::powershell(['composer', 'update', 'a/b', 'c-d.e_f:1']));
        foreach (['@name', 'a,b', 'a+b', 'a=b', 'a b', 'a^b', 'a~b', 'a*b', 'a|b', 'a<b', 'a>b'] as $argument) {
            self::assertSame("x '".$argument."'", ShellCommand::powershell(['x', $argument]), $argument);
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
}
