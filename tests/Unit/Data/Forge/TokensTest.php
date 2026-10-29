<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Forge;

use Lockrot\Data\Forge\Tokens;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testGithubTokenIsWithheldWhenGithubServerUrlNamesAnotherHost(): void
    {
        $tokens = Tokens::fromEnvironment(['GITHUB_TOKEN' => 'b', 'GITHUB_SERVER_URL' => 'https://ghes.example.com'], null);

        self::assertNull($tokens->github());
        self::assertSame(['GITHUB_TOKEN' => 'ghes.example.com'], $tokens->withheld());
    }

    /**
     * @dataProvider githubServerUrls
     */
    #[DataProvider('githubServerUrls')]
    public function testGithubTokenCountsOnlyWhenItsIssuerIsGithubCom(?string $serverUrl, ?string $expected): void
    {
        $env = ['GITHUB_TOKEN' => 'b'] + ($serverUrl === null ? [] : ['GITHUB_SERVER_URL' => $serverUrl]);

        self::assertSame($expected, Tokens::fromEnvironment($env, null)->github());
    }

    /** @return iterable<string, array{?string, ?string}> */
    public static function githubServerUrls(): iterable
    {
        yield 'GitHub Enterprise Cloud' => ['https://octo.ghe.com', null];
        yield 'a port is part of the host' => ['https://github.com:443', null];
        yield 'not a URL' => ['not a url ::', null];
        yield 'a scheme with no host' => ['https://', null];
        yield 'a bare host' => ['ghes.example.com', null];
        yield 'github.com' => ['https://github.com', 'b'];
        yield 'github.com in another case, with a slash' => ['https://GitHub.com/', 'b'];
        yield 'unset' => [null, 'b'];
        yield 'empty' => ['', 'b'];
    }

    /**
     * @param array<string, string> $issuers
     * @param array<string, string> $withheld
     *
     * @dataProvider ghHosts
     */
    #[DataProvider('ghHosts')]
    public function testGithubTokenCountsOnlyWhenGhHostNamesGithubCom(array $issuers, ?string $expected, array $withheld): void
    {
        $tokens = Tokens::fromEnvironment(['GITHUB_TOKEN' => 'b'] + $issuers, null);

        self::assertSame($expected, $tokens->github());
        self::assertSame($withheld, $tokens->withheld());
    }

    /** @return iterable<string, array{array<string, string>, ?string, array<string, string>}> */
    public static function ghHosts(): iterable
    {
        yield 'a GHE.com host' => [['GH_HOST' => 'acme.ghe.com'], null, ['GITHUB_TOKEN' => 'acme.ghe.com']];
        yield 'github.com Actions with a GHE.com gh host' => [['GITHUB_SERVER_URL' => 'https://github.com', 'GH_HOST' => 'acme.ghe.com'], null, ['GITHUB_TOKEN' => 'acme.ghe.com']];
        yield 'a gh host of github.com' => [['GH_HOST' => 'github.com'], 'b', []];
        yield 'an empty gh host' => [['GH_HOST' => ''], 'b', []];
    }

    public function testAWithheldGithubTokenFallsThroughToComposersToken(): void
    {
        self::assertSame('c', Tokens::fromEnvironment(['GITHUB_TOKEN' => 'b', 'GITHUB_SERVER_URL' => 'https://ghes.example.com'], 'c')->github());
    }

    public function testLockrotsOwnGithubTokenHasNoIssuerCheck(): void
    {
        $tokens = Tokens::fromEnvironment(['LOCKROT_GITHUB_TOKEN' => 'a', 'GITHUB_TOKEN' => 'b', 'GITHUB_SERVER_URL' => 'https://ghes.example.com'], null);

        self::assertSame('a', $tokens->github());
        self::assertSame([], $tokens->withheld());
    }

    /**
     * @param array<string, string> $issuers
     *
     * @dataProvider gitlabIssuers
     */
    #[DataProvider('gitlabIssuers')]
    public function testGitlabTokenCountsOnlyWhenEveryIssuerNamesGitlabCom(array $issuers, ?string $expected): void
    {
        self::assertSame($expected, Tokens::fromEnvironment(['GITLAB_TOKEN' => 'g'] + $issuers, null)->gitlab());
    }

    /** @return iterable<string, array{array<string, string>, ?string}> */
    public static function gitlabIssuers(): iterable
    {
        yield 'self-managed GitLab CI' => [['CI_SERVER_URL' => 'https://gitlab.example.com:8443'], null];
        yield 'a glab host' => [['GITLAB_HOST' => 'gitlab.example.com'], null];
        yield 'a single-label glab host' => [['GITLAB_HOST' => 'gitlab'], null];
        yield 'gitlab.com CI' => [['CI_SERVER_URL' => 'https://gitlab.com'], 'g'];
        yield 'gitlab.com CI with another glab host' => [['CI_SERVER_URL' => 'https://gitlab.com', 'GITLAB_HOST' => 'git.example.com'], null];
        yield 'a glab host of gitlab.com' => [['GITLAB_HOST' => 'gitlab.com'], 'g'];
        yield 'empty issuers' => [['CI_SERVER_URL' => '', 'GITLAB_HOST' => ''], 'g'];
        yield 'a GL_HOST of a self-managed instance' => [['GL_HOST' => 'https://gitlab.example.com'], null];
        yield 'a GITLAB_URI of a self-managed instance' => [['GITLAB_URI' => 'https://gitlab.example.com'], null];
        yield 'a GL_HOST of gitlab.com' => [['GL_HOST' => 'gitlab.com'], 'g'];
        yield 'a GITLAB_URI of gitlab.com' => [['GITLAB_URI' => 'gitlab.com'], 'g'];
        yield 'a GITLAB_HOST of gitlab.com with another GITLAB_URI' => [['GITLAB_HOST' => 'gitlab.com', 'GITLAB_URI' => 'https://gitlab.example.com'], null];
    }

    public function testAWithheldGitlabTokenNamesItsIssuer(): void
    {
        $tokens = Tokens::fromEnvironment(['GITLAB_TOKEN' => 'g', 'CI_SERVER_URL' => 'https://GitLab.Example.com'], null);

        self::assertSame(['GITLAB_TOKEN' => 'gitlab.example.com'], $tokens->withheld());
    }

    public function testAnUnparseableIssuerIsWithheldUnderItsRawValue(): void
    {
        self::assertSame(['GITHUB_TOKEN' => 'https://'], Tokens::fromEnvironment(['GITHUB_TOKEN' => 'b', 'GITHUB_SERVER_URL' => 'https://'], null)->withheld());
    }

    public function testLockrotsOwnGitlabTokenHasNoIssuerCheck(): void
    {
        $tokens = Tokens::fromEnvironment(['LOCKROT_GITLAB_TOKEN' => 'a', 'GITLAB_TOKEN' => 'g', 'CI_SERVER_URL' => 'https://gitlab.example.com'], null);

        self::assertSame('a', $tokens->gitlab());
        self::assertSame([], $tokens->withheld());
    }

    public function testATokenThatIsNotSetIsNotWithheld(): void
    {
        self::assertSame([], Tokens::fromEnvironment(['GITHUB_SERVER_URL' => 'https://ghes.example.com', 'CI_SERVER_URL' => 'https://gitlab.example.com'], null)->withheld());
        self::assertSame([], (new Tokens('a', 'b'))->withheld());
    }
}
