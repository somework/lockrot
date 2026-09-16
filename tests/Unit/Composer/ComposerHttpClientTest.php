<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Composer;

use Composer\Config;
use Composer\IO\BufferIO;
use Lockrot\Composer\ComposerHttpClient;
use PHPUnit\Framework\TestCase;

final class ComposerHttpClientTest extends TestCase
{
    private const GITHUB = 'https://api.github.com/repos/vendor/name';
    private const GITLAB = 'https://gitlab.com/api/v4/projects/vendor%2Fname/repository/commits?all=true&per_page=1';
    private const BITBUCKET = 'https://api.bitbucket.org/2.0/repositories/vendor/name/commits?pagelen=1';
    private const HEADERS = ['Accept: application/vnd.github+json', 'User-Agent: lockrot', 'Authorization: token env-token'];
    private const WITHOUT_AUTHORIZATION = ['Accept: application/vnd.github+json', 'User-Agent: lockrot'];
    private const GITLAB_HEADERS = ['Accept: application/json', 'User-Agent: lockrot', 'PRIVATE-TOKEN: env-token'];
    private const GITLAB_WITHOUT_TOKEN = ['Accept: application/json', 'User-Agent: lockrot'];

    /** @param list<string> $gitlabDomains */
    private static function config(array $gitlabDomains = ['gitlab.com']): Config
    {
        $config = new Config(false);
        $config->merge(['config' => ['gitlab-domains' => $gitlabDomains]]);

        return $config;
    }

    /**
     * @param list<string> $headers
     *
     * @return list<string>
     */
    private static function strip(BufferIO $io, string $url, array $headers, ?Config $config = null): array
    {
        return ComposerHttpClient::withoutRedundantAuthorization($io, $config ?? self::config(), $url, $headers);
    }

    public function testHeadersPassThroughWhenComposerHasNoGithubCredentials(): void
    {
        self::assertSame(self::HEADERS, self::strip(new BufferIO(), self::GITHUB, self::HEADERS));
    }

    public function testAuthorizationIsDroppedWhenComposerAuthenticatesGithub(): void
    {
        $io = new BufferIO();
        $io->setAuthentication('github.com', 'composer-token', 'x-oauth-basic');

        self::assertSame(self::WITHOUT_AUTHORIZATION, self::strip($io, self::GITHUB, self::HEADERS));
    }

    /**
     * Composer's AuthHelper sends Basic for http-basic credentials on github.com; that is still an
     * Authorization header, so lockrot's must still go.
     */
    public function testHttpBasicCredentialsForGithubCountToo(): void
    {
        $io = new BufferIO();
        $io->setAuthentication('github.com', 'user', 'secret');

        self::assertSame(self::WITHOUT_AUTHORIZATION, self::strip($io, self::GITHUB, self::HEADERS));
    }

    /**
     * HttpDownloader folds every *.github.com host into the github.com origin before AuthHelper
     * looks credentials up, so credentials stored under api.github.com are never sent by Composer
     * — and lockrot's own header has to stay.
     */
    public function testCredentialsStoredUnderTheApiHostDoNotCount(): void
    {
        $io = new BufferIO();
        $io->setAuthentication('api.github.com', 'composer-token', 'x-oauth-basic');

        self::assertSame(self::HEADERS, self::strip($io, self::GITHUB, self::HEADERS));
    }

    /**
     * The header name is matched case-insensitively; the host is not, because Composer's own
     * origin lookup is exact and adds nothing for `API.GITHUB.COM` — so lockrot's header stays.
     */
    public function testTheHeaderNameIsMatchedCaseInsensitivelyAndTheHostExactly(): void
    {
        $io = new BufferIO();
        $io->setAuthentication('github.com', 'composer-token', 'x-oauth-basic');

        self::assertSame(['User-Agent: lockrot'], self::strip($io, self::GITHUB, ['User-Agent: lockrot', 'authorization: token x']));
        self::assertSame(['User-Agent: lockrot', 'AUTHORIZATION: token x'], self::strip($io, 'https://API.GITHUB.COM/repos/x/y', ['User-Agent: lockrot', 'AUTHORIZATION: token x']));
    }

    public function testOtherHostsKeepTheirHeadersWhateverComposerKnows(): void
    {
        $io = new BufferIO();
        $io->setAuthentication('github.com', 'composer-token', 'x-oauth-basic');

        foreach (['https://github.com/somework/lockrot/releases/download/v0.2.1/lockrot.phar', 'https://repo.packagist.org/p2/psr/log.json', 'http://127.0.0.1:8080/repos/x/y', 'not a url', ''] as $url) {
            self::assertSame(self::HEADERS, self::strip($io, $url, self::HEADERS), $url);
        }
    }

    public function testCredentialsForAnotherHostDoNotTouchGithubRequests(): void
    {
        $io = new BufferIO();
        $io->setAuthentication('repo.packagist.com', 'token', 'secret');

        self::assertSame(self::HEADERS, self::strip($io, self::GITHUB, self::HEADERS));
    }

    /**
     * Two credential shapes make AuthHelper add no credential header — an SSL client certificate,
     * and custom headers without one — so lockrot's own token has to stay, or the request would go
     * out anonymous while the cap is lifted.
     */
    public function testCredentialsThatAddNoCredentialHeaderLeaveLockrotsTokenAlone(): void
    {
        $io = new BufferIO();
        $io->setAuthentication('github.com', 'client-certificate', '{"local_cert":"/tmp/c.pem"}');
        self::assertSame(self::HEADERS, self::strip($io, self::GITHUB, self::HEADERS));

        $io = new BufferIO();
        $io->setAuthentication('github.com', '["X-Api-Key: k"]', 'custom-headers');
        self::assertSame(self::HEADERS, self::strip($io, self::GITHUB, self::HEADERS));

        $io = new BufferIO();
        $io->setAuthentication('github.com', 'not json', 'custom-headers');
        self::assertSame(self::HEADERS, self::strip($io, self::GITHUB, self::HEADERS));

        $io = new BufferIO();
        $io->setAuthentication('github.com', '["X-Api-Key: k", "Authorization: Bearer theirs"]', 'custom-headers');
        self::assertSame(self::WITHOUT_AUTHORIZATION, self::strip($io, self::GITHUB, self::HEADERS), 'custom headers that do carry a credential replace lockrot\'s');
    }

    /** Composer's `gitlab-token` for gitlab.com becomes a PRIVATE-TOKEN header of its own; lockrot's goes. */
    public function testThePrivateTokenIsDroppedWhenComposerAuthenticatesGitlab(): void
    {
        $io = new BufferIO();
        $io->setAuthentication('gitlab.com', 'composer-token', 'private-token');

        self::assertSame(self::GITLAB_WITHOUT_TOKEN, self::strip($io, self::GITLAB, self::GITLAB_HEADERS));
        self::assertSame(self::GITLAB_HEADERS, self::strip(new BufferIO(), self::GITLAB, self::GITLAB_HEADERS));
    }

    /** A self-hosted instance counts only once it is a `gitlab-domains` entry Composer resolves the request to. */
    public function testASelfHostedGitlabIsResolvedThroughTheConfiguredDomains(): void
    {
        $io = new BufferIO();
        $io->setAuthentication('git.example.com', 'composer-token', 'private-token');
        $url = 'https://git.example.com/api/v4/projects/group%2Fproject/repository/commits?all=true&per_page=1';

        self::assertSame(self::GITLAB_WITHOUT_TOKEN, self::strip($io, $url, self::GITLAB_HEADERS, self::config(['gitlab.com', 'git.example.com'])));
        // Credentials stored under a plain host are found the same way with or without the domain
        // list — this is what AuthHelper's hasAuthentication($origin) does for any host.
        self::assertSame(self::GITLAB_WITHOUT_TOKEN, self::strip($io, $url, self::GITLAB_HEADERS));
    }

    /** A domain in a non-root context (`gitlab.example.com/gitlab`) is what Composer stores the credentials under. */
    public function testAGitlabDomainWithAPathPrefixIsTheOriginComposerResolves(): void
    {
        $io = new BufferIO();
        $io->setAuthentication('gitlab.example.com/gitlab', 'composer-token', 'private-token');
        $url = 'https://gitlab.example.com/gitlab/api/v4/projects/group%2Fproject/repository/commits?all=true&per_page=1';

        self::assertSame(self::GITLAB_WITHOUT_TOKEN, self::strip($io, $url, self::GITLAB_HEADERS, self::config(['gitlab.example.com/gitlab'])));
        self::assertSame(self::GITLAB_HEADERS, self::strip($io, $url, self::GITLAB_HEADERS, self::config(['gitlab.com'])));
    }

    /** Bitbucket credentials live under bitbucket.org while the API is api.bitbucket.org; AuthHelper bridges the two. */
    public function testBitbucketCredentialsUnderTheSiteHostCoverTheApiHost(): void
    {
        $io = new BufferIO();
        $io->setAuthentication('bitbucket.org', 'x-token-auth', 'bearer-token');

        self::assertSame(['User-Agent: lockrot'], self::strip($io, self::BITBUCKET, ['User-Agent: lockrot', 'Authorization: Bearer mine']));
        self::assertSame(['User-Agent: lockrot', 'Authorization: Bearer mine'], self::strip(new BufferIO(), self::BITBUCKET, ['User-Agent: lockrot', 'Authorization: Bearer mine']));
    }
}
