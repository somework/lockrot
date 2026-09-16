<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Forge;

use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\RepoRef;
use Lockrot\Data\Forge\Tokens;
use PHPUnit\Framework\TestCase;

final class ForgeAuthTest extends TestCase
{
    private const GITHUB = ['github', 'github.com', 'o/r'];
    private const GITLAB_COM = ['gitlab', 'gitlab.com', 'g/p'];
    private const GITLAB_SELF = ['gitlab', 'git.example.com', 'g/p'];
    private const BITBUCKET = ['bitbucket', 'bitbucket.org', 'w/r'];

    /** @param array{string, string, string} $ref */
    private static function ref(array $ref): RepoRef
    {
        return new RepoRef($ref[0], $ref[1], $ref[2]);
    }

    public function testLockrotsTokensGoToTheirOwnHostOnly(): void
    {
        $auth = ForgeAuth::withTokens(new Tokens('gh', 'gl'));

        self::assertSame('gh', $auth->tokenFor(self::ref(self::GITHUB)));
        self::assertSame('gl', $auth->tokenFor(self::ref(self::GITLAB_COM)));
        self::assertNull($auth->tokenFor(self::ref(self::GITLAB_SELF)), 'a gitlab.com token is never sent to another instance');
        self::assertNull($auth->tokenFor(self::ref(self::BITBUCKET)), 'lockrot has no Bitbucket token of its own');

        self::assertTrue($auth->isAuthenticated(self::ref(self::GITHUB)));
        self::assertTrue($auth->isAuthenticated(self::ref(self::GITLAB_COM)));
        self::assertFalse($auth->isAuthenticated(self::ref(self::GITLAB_SELF)));
        self::assertFalse($auth->isAuthenticated(self::ref(self::BITBUCKET)));
    }

    public function testComposersCredentialsForTheHostAuthenticateWithoutAnyLockrotToken(): void
    {
        $asked = [];
        $auth = new ForgeAuth(Tokens::none(), static function (string $host) use (&$asked): bool {
            $asked[] = $host;

            return \in_array($host, ['git.example.com', 'github.com'], true);
        });

        self::assertTrue($auth->isAuthenticated(self::ref(self::GITLAB_SELF)));
        self::assertTrue($auth->isAuthenticated(self::ref(self::GITHUB)), 'http-basic for github.com is a credential Composer sends');
        self::assertFalse($auth->isAuthenticated(self::ref(self::GITLAB_COM)));
        self::assertNull($auth->tokenFor(self::ref(self::GITLAB_SELF)), 'Composer sends its own header; lockrot adds none');
        self::assertSame(['git.example.com', 'github.com', 'gitlab.com'], $asked);
    }

    public function testTheBitbucketAuthorizerRunsOnceAndOnlyWhenAsked(): void
    {
        $calls = 0;
        $auth = new ForgeAuth(Tokens::none(), static fn (string $host): bool => false, static function () use (&$calls): bool {
            ++$calls;

            return true;
        });

        self::assertFalse($auth->isAuthenticated(self::ref(self::GITHUB)));
        self::assertSame(0, $calls, 'nothing Bitbucket was asked about yet');
        self::assertTrue($auth->isAuthenticated(self::ref(self::BITBUCKET)));
        self::assertTrue($auth->isAuthenticated(self::ref(self::BITBUCKET)));
        self::assertSame(1, $calls);
    }

    public function testWithoutAnAuthorizerBitbucketFallsBackToComposersCredentialLookup(): void
    {
        $auth = new ForgeAuth(Tokens::none(), static fn (string $host): bool => $host === 'bitbucket.org');
        self::assertTrue($auth->isAuthenticated(self::ref(self::BITBUCKET)));
        self::assertFalse(ForgeAuth::anonymous()->isAuthenticated(self::ref(self::BITBUCKET)));
    }
}
