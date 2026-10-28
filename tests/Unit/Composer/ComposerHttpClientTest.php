<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Composer;

use Composer\Composer;
use Composer\Config;
use Composer\Downloader\TransportException;
use Composer\IO\BufferIO;
use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use Lockrot\Clock;
use Lockrot\Composer\ComposerHttpClient;
use Lockrot\Data\Http\HttpResult;
use Lockrot\Deadline;
use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;

final class ComposerHttpClientTest extends TestCase
{
    private const GITHUB = 'https://api.github.com/repos/vendor/name';
    private const GITLAB = 'https://gitlab.com/api/v4/projects/vendor%2Fname/repository/commits?all=true&per_page=1';
    private const BITBUCKET = 'https://api.bitbucket.org/2.0/repositories/vendor/name/commits?pagelen=1';
    private const HEADERS = ['Accept: application/vnd.github+json', 'User-Agent: lockrot', 'Authorization: token env-token'];
    private const WITHOUT_AUTHORIZATION = ['Accept: application/vnd.github+json', 'User-Agent: lockrot'];
    private const GITLAB_HEADERS = ['Accept: application/json', 'User-Agent: lockrot', 'PRIVATE-TOKEN: env-token'];
    private const GITLAB_WITHOUT_TOKEN = ['Accept: application/json', 'User-Agent: lockrot'];
    private const NOW = '2026-09-14T00:00:00+00:00';

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

    /** Composer's AuthHelper sends http-basic credentials on github.com as an Authorization header, so lockrot's must go. */
    public function testHttpBasicCredentialsForGithubCountToo(): void
    {
        $io = new BufferIO();
        $io->setAuthentication('github.com', 'user', 'secret');

        self::assertSame(self::WITHOUT_AUTHORIZATION, self::strip($io, self::GITHUB, self::HEADERS));
    }

    /**
     * HttpDownloader folds every *.github.com host into the github.com origin before AuthHelper
     * looks credentials up. Composer never sends credentials stored under api.github.com, so
     * lockrot's own header must stay.
     */
    public function testCredentialsStoredUnderTheApiHostDoNotCount(): void
    {
        $io = new BufferIO();
        $io->setAuthentication('api.github.com', 'composer-token', 'x-oauth-basic');

        self::assertSame(self::HEADERS, self::strip($io, self::GITHUB, self::HEADERS));
    }

    /**
     * The header name is matched case-insensitively. The host is not, because Composer's own
     * origin lookup is exact and adds nothing for `API.GITHUB.COM`, so lockrot's header stays.
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

    /**
     * Url::getOrigin() resolves an empty URL to an empty origin, which an auth.json entry with an
     * empty host matches, so Composer can add credentials to a request it cannot send. There is
     * nothing to authenticate, so the headers come back before the origin lookup.
     */
    public function testAnEmptyUrlKeepsItsHeadersEvenWhenSomethingIsStoredUnderTheEmptyOrigin(): void
    {
        $io = new BufferIO();
        $io->setAuthentication('', 'user', 'secret');

        self::assertSame(self::HEADERS, self::strip($io, '', self::HEADERS));
    }

    /**
     * A GitHub Enterprise host is a `github-domains` entry with its own `github-oauth` token, which
     * Composer stores exactly as it stores a github.com one — username the token, password
     * `x-oauth-basic`. AuthHelper sends it as Basic on every request to that host, api path or not,
     * so lockrot's own Authorization header must go. The `api.github.com`-only exception belongs
     * to github.com alone.
     */
    public function testAGithubEnterpriseTokenReplacesLockrotsHeaderOnEveryPath(): void
    {
        $io = new BufferIO();
        $io->setAuthentication('github.example.com', 'ghe-token', 'x-oauth-basic');

        self::assertSame(self::WITHOUT_AUTHORIZATION, self::strip($io, 'https://github.example.com/api/v3/repos/vendor/name', self::HEADERS));
        self::assertSame(self::WITHOUT_AUTHORIZATION, self::strip($io, 'https://github.example.com/vendor/name/releases/download/v1/x.phar', self::HEADERS));
    }

    /**
     * Bitbucket credentials are stored under the site host and read for the API host, so which
     * origin the four characters of `api.` are stripped down to decides whose credentials are
     * inspected. A `client-certificate` entry under bitbucket.org adds no credential header of its
     * own, so lockrot's token must stay. Read under any other origin, the entry is not found and
     * the token can be dropped from a request that nothing then authenticates.
     */
    public function testTheApiHostFallsBackToTheExactSiteHostWhoseCredentialsDecide(): void
    {
        $io = new BufferIO();
        $io->setAuthentication('bitbucket.org', 'client-certificate', '{"local_cert":"/tmp/c.pem"}');

        self::assertSame(
            ['User-Agent: lockrot', 'Authorization: Bearer mine'],
            self::strip($io, self::BITBUCKET, ['User-Agent: lockrot', 'Authorization: Bearer mine'])
        );
    }

    /** What is left is a list, whichever position the credential header sat in — Composer indexes the `header` option by position. */
    public function testTheRemainingHeadersComeBackAsAList(): void
    {
        $io = new BufferIO();
        $io->setAuthentication('github.com', 'composer-token', 'x-oauth-basic');

        self::assertSame(
            ['Accept: application/vnd.github+json', 'User-Agent: lockrot'],
            self::strip($io, self::GITHUB, ['Authorization: token env-token', 'Accept: application/vnd.github+json', 'User-Agent: lockrot'])
        );
    }

    public function testCredentialsForAnotherHostDoNotTouchGithubRequests(): void
    {
        $io = new BufferIO();
        $io->setAuthentication('repo.packagist.com', 'token', 'secret');

        self::assertSame(self::HEADERS, self::strip($io, self::GITHUB, self::HEADERS));
    }

    /**
     * Two credential shapes make AuthHelper add no credential header — an SSL client certificate,
     * and custom headers without one — so lockrot's own token must stay, or the request goes out
     * anonymous while the cap is lifted.
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

    /** Composer's `gitlab-token` for gitlab.com becomes a PRIVATE-TOKEN header of its own, so lockrot's goes. */
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

    /** Bitbucket credentials live under bitbucket.org while the API is api.bitbucket.org, and AuthHelper bridges the two. */
    public function testBitbucketCredentialsUnderTheSiteHostCoverTheApiHost(): void
    {
        $io = new BufferIO();
        $io->setAuthentication('bitbucket.org', 'x-token-auth', 'bearer-token');

        self::assertSame(['User-Agent: lockrot'], self::strip($io, self::BITBUCKET, ['User-Agent: lockrot', 'Authorization: Bearer mine']));
        self::assertSame(['User-Agent: lockrot', 'Authorization: Bearer mine'], self::strip(new BufferIO(), self::BITBUCKET, ['User-Agent: lockrot', 'Authorization: Bearer mine']));
    }

    /**
     * @param array<string, array{0: int, 1: string}|\Throwable|string> $script url => [status, body], an exception to reject with, or the URL that a redirect names. A URL the script does not mention is never answered at all
     *
     * @return array{0: ComposerHttpClient, 1: ScriptedDownloader}
     */
    private static function clientWith(array $script, ?Deadline $deadline = null): array
    {
        $io = new NullIO();
        $config = new Config(false, sys_get_temp_dir());
        $downloader = new ScriptedDownloader($io, $config, $script);

        // The scripted downloader stops a redirect on any Composer, so a request with a token goes through it.
        return [new ComposerHttpClient($downloader, $io, $config, Clock::fixed(self::NOW), $deadline, true), $downloader];
    }

    /**
     * @param array<string, HttpResult> $results
     *
     * @return array<string, array{0: int, 1: ?string, 2: ?string}> url => status, body, error
     */
    private static function summarize(array $results): array
    {
        $summary = [];
        foreach ($results as $url => $result) {
            $summary[$url] = [$result->status(), $result->body(), $result->error()];
        }

        return $summary;
    }

    public function testEveryAnsweredRequestBecomesItsOwnResult(): void
    {
        [$client] = self::clientWith([
            'https://a.example.com/1' => [200, '{"a":1}'],
            'https://a.example.com/2' => [404, ''],
        ]);

        $results = $client->fetchAll(['https://a.example.com/1', 'https://a.example.com/2']);

        self::assertSame([
            'https://a.example.com/1' => [200, '{"a":1}', null],
            'https://a.example.com/2' => [404, '', null],
        ], self::summarize($results));
        self::assertSame('2026-09-14', $results['https://a.example.com/1']->fetchedAt()->format('Y-m-d'));
    }

    /**
     * A refused request keeps the transport's own status and message, so the caller can tell a 403
     * from "connection refused". Status 0 means "no HTTP answer to read a status from", which covers
     * both a transport error that never got one and a failure that is not a transport error at all —
     * a plugin that listens on PRE_FILE_DOWNLOAD and throws, say. Any other value there reads as a
     * status the server never sent.
     */
    public function testAFailedRequestKeepsItsStatusAndMessage(): void
    {
        $rateLimited = new TransportException('HTTP 403 rate limit exceeded');
        $rateLimited->setStatusCode(403);
        [$client] = self::clientWith([
            'https://a.example.com/rate-limited' => $rateLimited,
            'https://a.example.com/refused' => new TransportException('connection refused'),
            'https://a.example.com/not-a-transport-error' => new \RuntimeException('a download listener blew up'),
        ]);

        $results = $client->fetchAll([
            'https://a.example.com/rate-limited',
            'https://a.example.com/refused',
            'https://a.example.com/not-a-transport-error',
        ]);

        self::assertSame([
            'https://a.example.com/rate-limited' => [403, null, 'HTTP 403 rate limit exceeded'],
            'https://a.example.com/refused' => [0, null, 'connection refused'],
            'https://a.example.com/not-a-transport-error' => [0, null, 'a download listener blew up'],
        ], self::summarize($results));
    }

    /** The message is what a run note quotes, so Composer's words arrive without a login, a query or a machine path. */
    public function testAFailedRequestsMessageLosesWhatLocatesOrOpensAnything(): void
    {
        [$client] = self::clientWith([
            'https://gitlab.acme.test/api/v4/projects/1' => new TransportException('curl error 77 while downloading https://igor@gitlab.acme.test/api/v4/projects/1?private_token=t0k3n: error adding trust anchors from file: /Users/igor/certs/ca.pem'),
        ]);

        $results = $client->fetchAll(['https://gitlab.acme.test/api/v4/projects/1']);

        self::assertSame([
            'https://gitlab.acme.test/api/v4/projects/1' => [0, null, 'curl error 77 while downloading https://gitlab.acme.test/api/v4/projects/1: error adding trust anchors from file: .../ca.pem'],
        ], self::summarize($results));
    }

    /**
     * A URL the downloader never answers still gets a result: the caller reads the map by URL, and a
     * missing key is indistinguishable from a URL it never asked for.
     */
    public function testAUrlWithNoAnswerAtAllBecomesAFailureResult(): void
    {
        [$client] = self::clientWith(['https://a.example.com/answered' => [200, 'body']]);

        $results = $client->fetchAll(['https://a.example.com/answered', 'https://a.example.com/silent']);

        self::assertSame([
            'https://a.example.com/answered' => [200, 'body', null],
            'https://a.example.com/silent' => [0, null, 'no response'],
        ], self::summarize($results));
    }

    /** The results exist only once the downloader has been waited on. Returning before that reports every URL as unanswered. */
    public function testTheRequestsAreWaitedOnBeforeTheResultsAreRead(): void
    {
        [$client, $downloader] = self::clientWith(['https://a.example.com/1' => [200, 'body']]);

        $results = $client->fetchAll(['https://a.example.com/1']);

        self::assertSame(1, $downloader->waits, 'fetchAll() must wait for the promises it added');
        self::assertSame('body', $results['https://a.example.com/1']->body());
    }

    public function testARepeatedUrlIsRequestedOnce(): void
    {
        [$client, $downloader] = self::clientWith(['https://a.example.com/1' => [200, 'body']]);

        $results = $client->fetchAll(['https://a.example.com/1', 'https://a.example.com/1']);

        self::assertSame(['https://a.example.com/1'], array_column($downloader->requests, 'url'));
        self::assertCount(1, $results);
    }

    /**
     * `retry-auth-failure` is off because a retry lets Composer's AuthHelper exchange a
     * bitbucket-oauth consumer on a 401 and rewrite composer.json/auth.json on the way, which a
     * report must never do ({@see \Lockrot\Composer\ServiceFactory::bitbucketAuthorizer()} does the
     * exchange itself instead).
     */
    public function testEachRequestCarriesItsHeadersTimeoutAndNoAuthRetry(): void
    {
        [$client, $downloader] = self::clientWith(['https://api.github.com/repos/vendor/name' => [200, '{}']]);

        $client->fetchAll(['https://api.github.com/repos/vendor/name'], self::HEADERS);
        // The redirect guard is a closure, which ComposerHttpClientRedirectTest covers.
        unset($downloader->requests[0]['options']['prevent_url_access_callable']);

        self::assertSame([[
            'url' => 'https://api.github.com/repos/vendor/name',
            'options' => [
                'http' => ['timeout' => ComposerHttpClient::DEFAULT_TIMEOUT, 'header' => self::HEADERS],
                'retry-auth-failure' => false,
            ],
        ]], $downloader->requests);
    }

    /** The timeout is what the install-time budget has left when the requests go out, never the default. */
    public function testAPartlySpentDeadlineShortensEachRequestsTimeout(): void
    {
        $readings = [0.0, 2.6];
        $now = static function () use (&$readings): float {
            return \count($readings) > 1 ? (float) array_shift($readings) : $readings[0];
        };
        [$client, $downloader] = self::clientWith(['https://a.example.com/1' => [200, 'body']], Deadline::inSeconds(5.0, $now));

        $client->fetchAll(['https://a.example.com/1']);

        $options = $downloader->requests[0]['options'];
        self::assertIsArray($options['http']);
        self::assertSame(3, $options['http']['timeout'], '5s budget, 2.6s gone -> 2.4s left, rounded up');
    }
    /**
     * Composer replays a request's headers to the target of each redirect, so lockrot's token must
     * not reach a target on another origin. That request goes out again without the token.
     *
     * @dataProvider redirectsToAnotherOrigin
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('redirectsToAnotherOrigin')]
    public function testARedirectToAnotherOriginIsRequestedAgainWithoutTheToken(string $url, string $target): void
    {
        self::skipBelowComposer210();
        [$client, $downloader] = self::clientWith([$url => $target, $target => [200, 'moved']]);

        $results = $client->fetchAll([$url], self::HEADERS);

        self::assertSame([$url => [200, 'moved', null]], self::summarize($results));
        self::assertSame([], $downloader->followed, 'no request followed the redirect with the token');
        self::assertSame([$url, $target], array_column($downloader->requests, 'url'));
        unset($downloader->requests[0]['options']['prevent_url_access_callable']);
        self::assertSame(['http' => ['timeout' => ComposerHttpClient::DEFAULT_TIMEOUT, 'header' => self::WITHOUT_AUTHORIZATION], 'retry-auth-failure' => false], $downloader->requests[1]['options']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function redirectsToAnotherOrigin(): iterable
    {
        yield 'another host' => ['https://api.github.com/repos/vendor/name', 'https://objects.example.com/name'];
        yield 'another port' => ['https://a.example.com:8443/1', 'https://a.example.com:9443/1'];
        yield 'a port in place of no port' => ['https://a.example.com/1', 'https://a.example.com:8443/1'];
        yield 'a host that ends in the port digits' => ['https://a.example.com:8443/1', 'https://a.example.com8443/1'];
        yield 'another scheme' => ['https://a.example.com/1', 'http://a.example.com/1'];
    }

    /** @dataProvider redirectsOnTheSameOrigin */
    #[\PHPUnit\Framework\Attributes\DataProvider('redirectsOnTheSameOrigin')]
    public function testARedirectOnTheSameOriginIsFollowedWithTheToken(string $url, string $target): void
    {
        self::skipBelowComposer210();
        [$client, $downloader] = self::clientWith([$url => $target, $target => [200, 'moved']]);

        $results = $client->fetchAll([$url], self::HEADERS);

        self::assertSame([$url => [200, 'moved', null]], self::summarize($results));
        self::assertSame([['url' => $target, 'header' => self::HEADERS]], $downloader->followed);
        self::assertSame([$url], array_column($downloader->requests, 'url'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function redirectsOnTheSameOrigin(): iterable
    {
        yield 'another path' => ['https://a.example.com/1', 'https://a.example.com/2'];
        yield 'the same port' => ['https://a.example.com:8443/1', 'https://a.example.com:8443/2'];
        yield 'another letter case' => ['HTTPS://A.Example.com/1', 'https://a.example.com/2'];
    }

    /**
     * @dataProvider composersThatRefuseRedirects
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('composersThatRefuseRedirects')]
    public function testOnlyTheCurlDownloaderOfComposer210RefusesARedirect(string $version, bool $curl, bool $refuses): void
    {
        self::assertSame($refuses, ComposerHttpClient::refusesRedirects($version, $curl));
    }

    /** @return iterable<string, array{string, bool, bool}> */
    public static function composersThatRefuseRedirects(): iterable
    {
        yield '2.10 with curl' => ['2.10.0', true, true];
        yield 'a 2.10 snapshot with curl' => ['2.10.0-dev', true, true];
        yield '2.10 without curl' => ['2.10.3', false, false];
        yield '2.9 with curl' => ['2.9.5', true, false];
    }

    private static function skipBelowComposer210(): void
    {
        if (!ComposerHttpClient::refusesRedirects(Composer::getVersion(), true)) {
            self::markTestSkipped('Composer 2.10 or later with curl: lockrot follows each redirect of an older Composer itself (ComposerHttpClientRedirectTest, OnAnyComposer)');
        }
    }
}

/**
 * A downloader that answers each URL from a script, leaves an unscripted URL pending, and records
 * every request and wait(). A string answer is a redirect, which it follows with the request's
 * headers, as Composer does, unless `prevent_url_access_callable` refuses the target.
 */
final class ScriptedDownloader extends HttpDownloader
{
    /** @var list<array{url: string, options: array<string, mixed>}> the requests add() saw, in order */
    public array $requests = [];
    /** @var list<array{url: string, header: mixed}> the redirect targets that a request reached, with the headers it replayed */
    public array $followed = [];
    public int $waits = 0;

    /** @var array<string, array{0: int, 1: string}|\Throwable|string> */
    private array $script;
    /** @var list<array{0: non-empty-string, 1: Deferred<Response>, 2: array<string, mixed>}> */
    private array $pending = [];

    /** @param array<string, array{0: int, 1: string}|\Throwable|string> $script */
    public function __construct(IOInterface $io, Config $config, array $script)
    {
        parent::__construct($io, $config);
        $this->script = $script;
    }

    /**
     * @param string               $url
     * @param array<string, mixed> $options
     *
     * @return \React\Promise\PromiseInterface<Response>
     */
    public function add($url, $options = [])
    {
        $url = (string) $url;
        if ($url === '') {
            // What the real downloader does, so a test cannot pass one by accident.
            throw new \InvalidArgumentException('$url must not be an empty string');
        }
        $options = \is_array($options) ? $options : [];
        $this->requests[] = ['url' => $url, 'options' => $options];
        /** @var Deferred<Response> $deferred */
        $deferred = new Deferred();
        $this->pending[] = [$url, $deferred, $options];

        return $deferred->promise();
    }

    /**
     * A URL the script does not mention is left pending for good — the real downloader's "no answer
     * ever arrived" case.
     *
     * @param ?int $index
     */
    public function wait($index = null): void
    {
        ++$this->waits;
        $pending = $this->pending;
        $this->pending = [];
        foreach ($pending as [$url, $deferred, $options]) {
            $answer = $this->script[$url] ?? null;
            if (\is_string($answer)) {
                $refuse = $options['prevent_url_access_callable'] ?? null;
                if (\is_callable($refuse) && $refuse($answer) === true) {
                    $deferred->reject(new TransportException('Access to "'.$answer.'" is blocked.'));

                    continue;
                }
                $http = $options['http'] ?? [];
                $this->followed[] = ['url' => $answer, 'header' => \is_array($http) ? ($http['header'] ?? null) : null];
                $answer = $this->script[$answer] ?? null;
            }
            if ($answer instanceof \Throwable) {
                $deferred->reject($answer);
            } elseif (\is_array($answer)) {
                $deferred->resolve(new Response(['url' => $url], $answer[0], [], $answer[1]));
            }
        }
    }
}
