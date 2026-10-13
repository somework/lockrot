<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Output\ShellCommand;
use PHPUnit\Framework\TestCase;

/**
 * The renderings of an argv list: POSIX for the text
 * formats, cmd.exe for the copy menu. A release such as `v1.0.0+$(id)` counts as stable, so it can
 * reach a command: each argument is quoted where a shell would read it.
 */
final class ShellCommandTest extends TestCase
{
    public function testPosixLeavesASafeArgumentBareAndSingleQuotesTheRest(): void
    {
        self::assertSame("composer require 'twig/twig:^3.27.0'", ShellCommand::posix(['composer', 'require', 'twig/twig:^3.27.0']));
        self::assertSame("composer require 'php:>=8.1' --no-update", ShellCommand::posix(['composer', 'require', 'php:>=8.1', '--no-update']));
        self::assertSame('composer update a/b c/d', ShellCommand::posix(['composer', 'update', 'a/b', 'c/d']));
        self::assertSame("composer require 'a/b:v1.0.0+$(id)'", ShellCommand::posix(['composer', 'require', 'a/b:v1.0.0+$(id)']));
        self::assertSame("echo 'it'\\''s'", ShellCommand::posix(['echo', "it's"]));
        self::assertSame("composer require ''", ShellCommand::posix(['composer', 'require', '']));
        self::assertSame('a/b@1.0,c%d+e=f', ShellCommand::posix(['a/b@1.0,c%d+e=f']));
        self::assertSame("'a/b\n'", ShellCommand::posix(["a/b\n"]), 'a trailing line break is quoted');
    }

    public function testCmdDoubleQuotesAnArgumentWithASpaceOrAMetacharacter(): void
    {
        self::assertSame('composer require "twig/twig:^3.27.0"', ShellCommand::cmd(['composer', 'require', 'twig/twig:^3.27.0']));
        self::assertSame('composer require "php:>=8.1" --no-update', ShellCommand::cmd(['composer', 'require', 'php:>=8.1', '--no-update']));
        self::assertSame('composer update a/b', ShellCommand::cmd(['composer', 'update', 'a/b']));
        self::assertSame('echo "say ""hi"""', ShellCommand::cmd(['echo', 'say "hi"']));
        self::assertSame('composer require ""', ShellCommand::cmd(['composer', 'require', '']), 'an empty argument stays an argument');
        foreach (['a b', "a\tb", 'a&b', 'a|b', 'a<b', 'a>b', 'a(b', 'a)b', 'a%b', 'a!b', 'a^b'] as $argument) {
            self::assertSame('x "'.$argument.'"', ShellCommand::cmd(['x', $argument]), $argument);
        }
    }
}
