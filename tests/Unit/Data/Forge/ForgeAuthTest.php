<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Forge;

use Composer\Config;
use Composer\IO\BufferIO;
use Lockrot\Composer\ServiceFactory;
use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\RepoRef;
use Lockrot\Data\Forge\Tokens;
use PHPUnit\Framework\Attributes\DataProvider;
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
        }, static fn (): bool => false);

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

    public function testBitbucketReadsOnlyTheAuthorizerAndNotTheHostLookup(): void
    {
        $auth = new ForgeAuth(Tokens::none(), static fn (string $host): bool => true, static fn (): bool => false);
        self::assertFalse($auth->isAuthenticated(self::ref(self::BITBUCKET)));
        self::assertFalse(ForgeAuth::anonymous()->isAuthenticated(self::ref(self::BITBUCKET)));
    }

    public function testTheGithubTokenNeverGoesToAnotherGithubHost(): void
    {
        $ghes = new RepoRef(RepoRef::GITHUB, 'ghes.example.com', 'a/b');
        $auth = new ForgeAuth(new Tokens('gh', null), static fn (string $host): bool => $host === 'ghes.example.com', static fn (): bool => false);

        self::assertNull($auth->tokenFor($ghes));
        self::assertTrue($auth->isAuthenticated($ghes), "Composer's credentials for the host still count");
        self::assertFalse(ForgeAuth::withTokens(new Tokens('gh', null))->isAuthenticated($ghes));
    }

    /**
     * @param array<string, mixed> $settings Composer `config` keys, as in auth.json
     */
    private static function composerAuth(array $settings): ForgeAuth
    {
        $io = new BufferIO();
        $config = new Config(false);
        $config->merge(['config' => $settings]);
        $io->loadConfiguration($config);

        return new ForgeAuth(Tokens::none(), ServiceFactory::composerCredentials($io), ServiceFactory::bitbucketCredentials($io));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function composerEntries(): iterable
    {
        yield 'github-oauth' => [['github-oauth' => ['github.com' => 'tok']], true];
        yield 'bearer' => [['bearer' => ['github.com' => 'tok']], true];
        yield 'http-basic' => [['http-basic' => ['github.com' => ['username' => 'u', 'password' => 'p']]], true];
        yield 'custom Authorization header' => [['custom-headers' => ['github.com' => ['Authorization: token abc']]], true];
        yield 'custom authorization header in lower case' => [['custom-headers' => ['github.com' => ['X-A: b', 'authorization: token abc']]], true];
        yield 'custom header without Authorization' => [['custom-headers' => ['github.com' => ['X-Proxy-Tag: ci']]], false];
        yield 'custom list with a non-string element' => [['custom-headers' => ['github.com' => ['Authorization: token abc', 5]]], false];
        yield 'custom header with a leading space' => [['custom-headers' => ['github.com' => [' Authorization: token abc']]], false];
        yield 'client certificate' => [['client-certificate' => ['github.com' => ['local_cert' => '/c.pem']]], false];
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @dataProvider composerEntries
     */
    #[DataProvider('composerEntries')]
    public function testOnlyAComposerEntryThatCarriesACredentialAuthenticates(array $settings, bool $authenticated): void
    {
        self::assertSame($authenticated, self::composerAuth($settings)->isAuthenticated(self::ref(self::GITHUB)));
    }

    public function testACredentialLessBitbucketEntryDoesNotAuthenticateEither(): void
    {
        $auth = self::composerAuth(['client-certificate' => ['bitbucket.org' => ['local_cert' => '/c.pem']]]);

        self::assertFalse($auth->isAuthenticated(self::ref(self::BITBUCKET)));
    }

    /** AuthHelper takes the entry of the API host first, so a header-less one there leaves the run anonymous, online or offline. */
    public function testAHeaderlessApiHostEntryDecidesAloneOffline(): void
    {
        $auth = self::composerAuth([
            'client-certificate' => ['api.bitbucket.org' => ['local_cert' => '/c.pem']],
            'http-basic' => ['bitbucket.org' => ['username' => 'u', 'password' => 'p']],
        ]);

        self::assertFalse($auth->isAuthenticated(self::ref(self::BITBUCKET)));
    }
}
