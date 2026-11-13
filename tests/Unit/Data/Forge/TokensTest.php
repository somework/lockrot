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

    /** Under a Turkish locale, PHP 7.4 to 8.1 lower `I` in `GITHUB.COM` to a byte that is not `i`. */
    public function testAnUpperCaseIssuerHostNamesGithubComUnderTheTurkishLocale(): void
    {
        $previous = setlocale(\LC_CTYPE, '0');
        if (setlocale(\LC_CTYPE, 'tr_TR.ISO8859-9', 'tr_TR.ISO-8859-9', 'tr_TR', 'tr_TR.UTF-8') === false) {
            self::markTestSkipped('No Turkish locale is installed.');
        }

        try {
            $tokens = Tokens::fromEnvironment(['GITHUB_TOKEN' => 'b', 'GH_HOST' => 'GITHUB.COM', 'GITHUB_SERVER_URL' => 'https://GITHUB.COM'], null);
        } finally {
            setlocale(\LC_CTYPE, (string) $previous);
        }

        self::assertSame(['b', []], [$tokens->github(), $tokens->withheld()]);
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

    public function testAnUnparseableIssuerWithCredentialsIsWithheldWithoutThem(): void
    {
        $withheld = Tokens::fromEnvironment(['GITHUB_TOKEN' => 'b', 'GITHUB_SERVER_URL' => 'https://ci:SECRETVALUE@ghe.example:99999'], null)->withheld();

        self::assertArrayHasKey('GITHUB_TOKEN', $withheld);
        self::assertStringNotContainsString('SECRETVALUE', $withheld['GITHUB_TOKEN']);

        $bare = Tokens::fromEnvironment(['GITHUB_TOKEN' => 'b', 'GH_HOST' => 'ci:SECRETVALUE@ghe.example:99999'], null)->withheld();
        self::assertStringNotContainsString('SECRETVALUE', $bare['GITHUB_TOKEN']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function issuersWithASlashInThePassword(): iterable
    {
        yield 'with a scheme' => ['GITHUB_SERVER_URL', 'https://ci:SE/CRET@ghe.example:99999'];
        yield 'without a scheme' => ['GH_HOST', 'ci:SE/CRET@ghe.example:99999'];
        yield 'a scheme separator in the password' => ['GH_HOST', 'ci:CRET://x@ghe.example:99999'];
        yield 'a password that parses as a port' => ['GITHUB_SERVER_URL', 'https://ci:12/CRET@ghe.example:99999'];
    }

    public function testAnUnparseableIssuerKeepsNoQueryOrPath(): void
    {
        $withheld = Tokens::fromEnvironment(['GITHUB_TOKEN' => 'b', 'GITHUB_SERVER_URL' => 'https://ghe.example:99999/x?private_token=SECRET#f'], null)->withheld();

        self::assertSame(['GITHUB_TOKEN' => 'https://ghe.example:99999'], $withheld);
    }

    /** @return iterable<string, array{string, string}> */
    public static function unparseableIssuersWithUserinfo(): iterable
    {
        yield 'a user and a password' => ['https://ci:SECRETVALUE@ghe.example:99999', 'https://ghe.example:99999'];
        yield 'an empty userinfo' => ['https://@ghe.example:99999', 'https://ghe.example:99999'];
        yield 'a scheme in capitals' => ['HTTPS://ci:SECRETVALUE@ghe.example:99999', 'HTTPS://ghe.example:99999'];
    }

    /**
     * @dataProvider unparseableIssuersWithUserinfo
     */
    #[DataProvider('unparseableIssuersWithUserinfo')]
    public function testAnUnparseableIssuerIsWithheldAsItsSchemeAndAuthority(string $issuer, string $expected): void
    {
        self::assertSame(['GITHUB_TOKEN' => $expected], Tokens::fromEnvironment(['GITHUB_TOKEN' => 'b', 'GITHUB_SERVER_URL' => $issuer], null)->withheld());
    }

    /** @return iterable<string, array{string, string}> */
    public static function issuersWithWhitespaceOrNoScheme(): iterable
    {
        yield 'a leading space before the public host' => [' https://github.com', Tokens::READ_TWO_WAYS];
        yield 'a trailing newline after a bare public host' => ["github.com\n", Tokens::READ_TWO_WAYS];
        yield 'a trailing space after another host' => ["https://ghe.corp \t", 'ghe.corp'];
        yield 'a scheme-relative host' => ['//ghe.corp', '//ghe.corp'];
        yield 'a scheme-relative host with a path' => ['//ghe.corp/api', '//ghe.corp'];
        yield 'a scheme-relative public host' => ['//github.com', '//github.com'];
        yield 'only whitespace' => ["  \r\n", Tokens::READ_TWO_WAYS];
        yield 'a tab inside the public host' => ["https://git\thub.com", Tokens::READ_TWO_WAYS];
        yield 'a control character before the public host' => ["\x01https://github.com", Tokens::READ_TWO_WAYS];
        yield 'a control character after another host' => ["https://ghe.corp\x1f", 'ghe.corp'];
    }

    /**
     * @dataProvider issuersWithWhitespaceOrNoScheme
     */
    #[DataProvider('issuersWithWhitespaceOrNoScheme')]
    public function testAnIssuerWithWhitespaceOrNoSchemeIsWithheldUnderANameThatReadsTrue(string $issuer, string $shown): void
    {
        $tokens = Tokens::fromEnvironment(['GITHUB_TOKEN' => 'b', 'GITHUB_SERVER_URL' => $issuer], null);

        self::assertNull($tokens->github());
        self::assertSame(['GITHUB_TOKEN' => $shown], $tokens->withheld());
    }

    /** @return iterable<string, array{string, string}> */
    public static function issuersWithThePublicHostAfterAnAt(): iterable
    {
        yield 'in the path' => ['GITHUB_SERVER_URL', 'https://ghe.corp/@github.com'];
        yield 'in the query' => ['GITHUB_SERVER_URL', 'https://ghe.corp?x=@github.com'];
        yield 'in the fragment of a bare host' => ['GH_HOST', 'ghe.corp#@github.com'];
        yield 'after a backslash' => ['GITHUB_SERVER_URL', 'https://ghe.corp\\@github.com'];
        yield 'after a backslash in a bare host' => ['GH_HOST', 'ghe.corp\\@github.com'];
    }

    /**
     * @dataProvider issuersWithThePublicHostAfterAnAt
     */
    #[DataProvider('issuersWithThePublicHostAfterAnAt')]
    public function testAnIssuerIsWithheldWhenItsHostIsNotPublicInEitherReadingOfItsAt(string $variable, string $issuer): void
    {
        $tokens = Tokens::fromEnvironment(['GITHUB_TOKEN' => 'SECRET', $variable => $issuer], null);

        self::assertNull($tokens->github());
        self::assertSame(['GITHUB_TOKEN' => Tokens::READ_TWO_WAYS], $tokens->withheld());
    }

    public function testAnIssuerWithABackslashShowsNoUserinfoWhenWithheld(): void
    {
        $withheld = Tokens::fromEnvironment(['GITHUB_TOKEN' => 'b', 'GITHUB_SERVER_URL' => 'https://ci:SECRETVALUE\\@github.com'], null)->withheld();

        self::assertArrayHasKey('GITHUB_TOKEN', $withheld);
        self::assertStringNotContainsString('SECRET', $withheld['GITHUB_TOKEN']);
    }

    public function testAnIssuerWithUserinfoIsJudgedByItsHost(): void
    {
        $tokens = Tokens::fromEnvironment(['GITHUB_TOKEN' => 'b', 'GITHUB_SERVER_URL' => 'https://ci:12/CRET@ghe.example.com'], null);

        self::assertNull($tokens->github());
        self::assertSame(['GITHUB_TOKEN' => 'ghe.example.com'], $tokens->withheld());
    }

    /**
     * @dataProvider issuersWithASlashInThePassword
     */
    #[DataProvider('issuersWithASlashInThePassword')]
    public function testAnUnparseableIssuerKeepsNoPasswordThatHoldsASlash(string $variable, string $issuer): void
    {
        $withheld = Tokens::fromEnvironment(['GITHUB_TOKEN' => 'b', $variable => $issuer], null)->withheld();

        self::assertStringNotContainsString('CRET', $withheld['GITHUB_TOKEN']);
        self::assertStringContainsString('ghe.example:99999', $withheld['GITHUB_TOKEN']);
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

    /**
     * @dataProvider issuerEnvironments
     *
     * @param array<string, string> $env
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('issuerEnvironments')]
    public function testItSaysWhetherAGithubIssuerNamesAnotherHostWhateverTokenIsSet(array $env, bool $expected): void
    {
        self::assertSame($expected, Tokens::fromEnvironment($env, null)->githubIssuerNamesAnotherHost());
    }

    /** @return iterable<string, array{array<string, string>, bool}> */
    public static function issuerEnvironments(): iterable
    {
        yield 'no issuer variable' => [[], false];
        yield 'the public host' => [['GITHUB_SERVER_URL' => 'https://github.com'], false];
        yield 'another host in GITHUB_SERVER_URL, no token' => [['GITHUB_SERVER_URL' => 'https://ghe.example'], true];
        yield 'another host in GH_HOST, with a token' => [['GH_HOST' => 'ghe.example', 'GITHUB_TOKEN' => 't'], true];
        yield 'a GitLab issuer says nothing' => [['CI_SERVER_URL' => 'https://gl.example'], false];
    }
}
