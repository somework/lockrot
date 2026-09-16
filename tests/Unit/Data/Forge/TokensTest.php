<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Forge;

use Lockrot\Data\Forge\Tokens;
use PHPUnit\Framework\TestCase;

final class TokensTest extends TestCase
{
    public function testGithubPrecedenceIsLockrotEnvThenGithubEnvThenComposer(): void
    {
        self::assertSame('a', Tokens::fromEnvironment(['LOCKROT_GITHUB_TOKEN' => 'a', 'GITHUB_TOKEN' => 'b'], 'c')->github());
        self::assertSame('b', Tokens::fromEnvironment(['GITHUB_TOKEN' => 'b'], 'c')->github());
        self::assertSame('c', Tokens::fromEnvironment([], 'c')->github());
        self::assertNull(Tokens::fromEnvironment(['GITHUB_TOKEN' => ''], null)->github());
        self::assertNull(Tokens::fromEnvironment(['GITHUB_TOKEN' => ''], '')->github());
    }

    /** Composer's gitlab-token needs no resolving: Composer sends it itself, so only the environment counts here. */
    public function testGitlabComesFromTheEnvironmentAlone(): void
    {
        self::assertSame('a', Tokens::fromEnvironment(['LOCKROT_GITLAB_TOKEN' => 'a', 'GITLAB_TOKEN' => 'b'], null)->gitlab());
        self::assertSame('b', Tokens::fromEnvironment(['GITLAB_TOKEN' => 'b'], 'ghp')->gitlab());
        self::assertNull(Tokens::fromEnvironment(['GITLAB_TOKEN' => '', 'GITHUB_TOKEN' => 'x'], null)->gitlab());
    }

    public function testNoneAndEmptyStringsAreNoToken(): void
    {
        self::assertNull(Tokens::none()->github());
        self::assertNull(Tokens::none()->gitlab());
        self::assertNull((new Tokens('', ''))->github());
        self::assertNull((new Tokens('', ''))->gitlab());
    }
}
