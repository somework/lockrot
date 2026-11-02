<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Composer;

use Composer\Config;
use Composer\Downloader\TransportException;
use Composer\IO\BufferIO;
use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use Composer\Util\Platform;
use Lockrot\Clock;
use Lockrot\Composer\ComposerHttpClient;
use Lockrot\Data\Http\HttpResult;
use Lockrot\Deadline;
use PHPUnit\Framework\Attributes\DataProvider;
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
    private static function clientWith(array $script, ?Deadline $deadline = null, ?int $maxParallel = null): array
    {
        $io = new NullIO();
        $config = new Config(false, sys_get_temp_dir());
        $downloader = new ScriptedDownloader($io, $config, $script);

        // The scripted downloader stops a redirect on any Composer, so a request with a token goes through it.
        return [new ComposerHttpClient($downloader, $io, $config, Clock::fixed(self::NOW), $deadline, true, $maxParallel), $downloader];
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
    /** GitHub names a secondary rate limit only in the body of its 403 ({@see \Lockrot\Data\Forge\GitHubApi::isRateLimited()}). */
    public function testAFailedRequestKeepsItsStatusBodyAndMessage(): void
    {
        $rateLimited = new TransportException('HTTP 403 rate limit exceeded');
        $rateLimited->setStatusCode(403);
        $rateLimited->setResponse('{"message":"You have exceeded a secondary rate limit."}');
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
            'https://a.example.com/rate-limited' => [403, '{"message":"You have exceeded a secondary rate limit."}', 'HTTP 403 rate limit exceeded'],
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
     * Without curl, add() runs each request to its end before it returns. So a request without
     * lockrot's credentials must check the budget and read its timeout when it starts.
     */
    public function testWithoutCurlEachRequestStartsOnlyInsideTheBudgetWithTheTimeLeft(): void
    {
        $urls = ['https://gitlab.com/api/v4/projects/a', 'https://gitlab.com/api/v4/projects/b', 'https://gitlab.com/api/v4/projects/c', 'https://gitlab.com/api/v4/projects/d'];
        $held = new \stdClass();
        $now = static function () use ($held): float {
            return ($held->downloader ?? null) instanceof ScriptedDownloader ? 3.0 * \count($held->downloader->requests) : 0.0;
        };
        $io = new NullIO();
        $config = new Config(false, sys_get_temp_dir());
        $downloader = new ScriptedDownloader($io, $config, [$urls[0] => [200, 'a'], $urls[1] => [200, 'b'], $urls[2] => [200, 'c']]);
        $held->downloader = $downloader;
        $client = new ComposerHttpClient($downloader, $io, $config, Clock::fixed(self::NOW), Deadline::inSeconds(5.0, $now), false);

        $results = $client->fetchAll($urls, self::WITHOUT_AUTHORIZATION);

        self::assertSame([$urls[0], $urls[1]], array_column($downloader->requests, 'url'), 'each request takes 3s of a 5s budget');
        self::assertSame([['timeout' => 5], ['timeout' => 2]], array_map(static fn (array $request): array => array_intersect_key((array) $request['options']['http'], ['timeout' => true]), $downloader->requests));
        self::assertSame([0, null, 'install-time budget spent'], self::summarize($results)[$urls[2]]);
        self::assertSame([0, null, 'install-time budget spent'], self::summarize($results)[$urls[3]], 'every request after the budget');
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
        [$client, $downloader] = self::clientWith([$url => $target, $target => [200, 'moved']]);

        $results = $client->fetchAll([$url], self::HEADERS);

        self::assertSame([$url => [200, 'moved', null]], self::summarize($results));
        self::assertSame([], $downloader->followed, 'no request followed the redirect with the token');
        self::assertSame([$url, $target], array_column($downloader->requests, 'url'));
        unset($downloader->requests[0]['options']['prevent_url_access_callable'], $downloader->requests[1]['options']['prevent_url_access_callable']);
        self::assertSame(['http' => ['timeout' => ComposerHttpClient::DEFAULT_TIMEOUT, 'header' => self::WITHOUT_AUTHORIZATION], 'retry-auth-failure' => false], $downloader->requests[1]['options']);
    }

    /**
     * The request to a redirect's target goes out after the first wait(). When the install-time
     * budget ends in between, the downloader could start it after the budget.
     */
    public function testARedirectTargetIsNotRequestedAfterTheBudgetEnds(): void
    {
        $url = 'https://api.github.com/repos/vendor/name';
        $target = 'https://objects.example.com/name';
        $held = new \stdClass();
        $now = static function () use ($held): float {
            return ($held->downloader ?? null) instanceof ScriptedDownloader && $held->downloader->settled >= 1 ? 6.0 : 0.0;
        };
        [$client, $downloader] = self::clientWith([$url => $target, $target => [200, 'moved']], Deadline::inSeconds(5.0, $now));
        $held->downloader = $downloader;

        $results = $client->fetchAll([$url], self::HEADERS);

        self::assertSame([$url => [0, null, 'install-time budget spent']], self::summarize($results));
        self::assertSame([$url], array_column($downloader->requests, 'url'), 'no request to the target after the budget');
    }

    /** The downloader queues requests above its limit, so a request can start after the budget although add() came before it. */
    public function testAQueuedRequestDoesNotStartAfterTheBudgetEnds(): void
    {
        $url = 'https://api.github.com/repos/vendor/name';
        $held = new \stdClass();
        $now = static function () use ($held): float {
            return ($held->downloader ?? null) instanceof ScriptedDownloader && $held->downloader->waits >= 1 ? 6.0 : 0.0;
        };
        [$client, $downloader] = self::clientWith([$url => [200, 'body']], Deadline::inSeconds(5.0, $now));
        $held->downloader = $downloader;

        $results = $client->fetchAll([$url], self::HEADERS);

        self::assertSame([$url => [0, null, 'install-time budget spent']], self::summarize($results));
        self::assertSame([], $downloader->started, 'no request starts after the budget');
    }

    /**
     * Composer's downloader starts a queued request with the options of add(), so lockrot adds no
     * request above the parallel limit and reads each timeout when a slot frees.
     */
    public function testARequestAboveTheParallelLimitStartsWithTheTimeLeftWhenASlotFrees(): void
    {
        $urls = ['https://a.example.com/1', 'https://a.example.com/2', 'https://a.example.com/3'];
        $held = new \stdClass();
        $now = static function () use ($held): float {
            return ($held->downloader ?? null) instanceof ScriptedDownloader ? 3.0 * \count($held->downloader->started) : 0.0;
        };
        $io = new NullIO();
        $config = new Config(false, sys_get_temp_dir());
        $downloader = new ScriptedDownloader($io, $config, array_fill_keys($urls, [200, 'body']));
        $held->downloader = $downloader;
        $client = new ComposerHttpClient($downloader, $io, $config, Clock::fixed(self::NOW), Deadline::inSeconds(10.0, $now), true, 2);

        $results = $client->fetchAll($urls);

        $timeouts = [];
        foreach ($downloader->requests as $request) {
            $http = $request['options']['http'];
            self::assertIsArray($http);
            $timeouts[$request['url']] = $http['timeout'];
        }
        self::assertSame(['https://a.example.com/1' => 10, 'https://a.example.com/2' => 10, 'https://a.example.com/3' => 7], $timeouts, '3s gone when the first answer frees a slot');
        self::assertSame(2, $downloader->mostInFlight);
        self::assertSame(['body', 'body', 'body'], array_values(array_map(static fn (HttpResult $result): ?string => $result->body(), $results)));
    }

    public function testWithOneSlotAFailedRequestFreesItForTheNext(): void
    {
        [$client] = self::clientWith([
            'https://a.example.com/1' => new TransportException('connection refused'),
            'https://a.example.com/2' => [200, 'b'],
        ], null, 1);

        $results = $client->fetchAll(['https://a.example.com/1', 'https://a.example.com/2']);

        self::assertSame([200, 'b', null], self::summarize($results)['https://a.example.com/2']);
    }

    public function testWithOneSlotEachRequestAfterTheBudgetReadsBudgetSpent(): void
    {
        $urls = ['https://a.example.com/1', 'https://a.example.com/2'];
        [$client, $downloader] = self::clientWith(array_fill_keys($urls, [200, 'body']), Deadline::inSeconds(0.0, static fn (): float => 0.0), 1);

        $results = $client->fetchAll($urls);

        self::assertSame(array_fill_keys($urls, [0, null, 'install-time budget spent']), self::summarize($results));
        self::assertSame([], $downloader->requests);
    }

    public function testWithOneSlotEachRedirectToAUrlWithoutAnHttpHostFails(): void
    {
        $first = 'https://api.github.com/repos/vendor/one';
        $second = 'https://api.github.com/repos/vendor/two';
        [$client] = self::clientWith([$first => 'file:///etc/passwd', $second => 'ftp://objects.example.com/two'], null, 1);

        $results = $client->fetchAll([$first, $second], self::HEADERS);

        $failure = [0, null, 'redirect to a URL that is not http or https with a host'];
        self::assertSame([$first => $failure, $second => $failure], self::summarize($results));
    }

    /** A request that carries credentials blocks in add() without curl's redirect guard, so it must free its slot itself. */
    public function testWithOneSlotARequestThatHoldsItsCredentialsFreesTheSlot(): void
    {
        $urls = ['https://api.github.com/repos/vendor/one', 'https://api.github.com/repos/vendor/two'];
        $held = new \stdClass();
        $now = static function () use ($held): float {
            return ($held->downloader ?? null) instanceof ScriptedDownloader && $held->downloader->holding >= 1 ? 6.0 : 0.0;
        };
        $io = new NullIO();
        $config = new Config(false, sys_get_temp_dir());
        $downloader = new ScriptedDownloader($io, $config, []);
        $held->downloader = $downloader;
        $client = new ComposerHttpClient($downloader, $io, $config, Clock::fixed(self::NOW), Deadline::inSeconds(5.0, $now), false, 1);

        $results = $client->fetchAll($urls, self::HEADERS);

        self::assertSame(1, $downloader->holding, 'the budget ends inside the first request');
        self::assertSame(array_fill_keys($urls, [0, null, 'install-time budget spent']), self::summarize($results));
    }

    public function testAFetchAfterOneThatThrewStartsItsRequests(): void
    {
        [$client] = self::clientWith(['https://a.example.com/1' => [200, 'a']]);
        try {
            $client->fetchAll(['']);
            self::fail('the scripted downloader refuses an empty URL');
        } catch (\InvalidArgumentException $e) {
        }

        $results = $client->fetchAll(['https://a.example.com/1']);

        self::assertSame(['https://a.example.com/1' => [200, 'a', null]], self::summarize($results));
    }

    /** Without curl, or with Composer's network off, a request ends inside add(). Its end must not start the next request one call deeper. */
    public function testARequestThatEndsInsideAddDoesNotStartTheNextOneDeeper(): void
    {
        $urls = array_map(static fn (int $i): string => 'https://a.example.com/'.$i, range(1, 30));
        [$client, $downloader] = self::clientWith(array_fill_keys($urls, [200, 'body']), null, 2);
        $downloader->answersInAdd = true;

        $results = $client->fetchAll($urls);

        self::assertCount(30, $downloader->depths);
        self::assertCount(1, array_unique($downloader->depths));
        self::assertSame(array_fill_keys($urls, 'body'), array_map(static fn (HttpResult $result): ?string => $result->body(), $results));
    }

    /** @return iterable<string, array{?string, int}> */
    public static function parallelLimits(): iterable
    {
        yield 'Composer\'s default' => [null, 12];
        yield 'COMPOSER_MAX_PARALLEL_HTTP' => ['3', 3];
        yield 'below one' => ['0', 1];
        yield 'above Composer\'s cap' => ['99', 50];
        yield 'not a number' => ['many', 12];
    }

    /**
     * @dataProvider parallelLimits
     */
    #[DataProvider('parallelLimits')]
    public function testTheParallelLimitIsTheOneOfComposersDownloader(?string $variable, int $limit): void
    {
        // Composer reads $_SERVER and $_ENV before getenv(), so a value that CI exports must not win.
        $before = Platform::getEnv('COMPOSER_MAX_PARALLEL_HTTP');
        $variable === null ? Platform::clearEnv('COMPOSER_MAX_PARALLEL_HTTP') : Platform::putEnv('COMPOSER_MAX_PARALLEL_HTTP', $variable);
        try {
            $urls = array_map(static fn (int $i): string => 'https://a.example.com/'.$i, range(1, 60));
            [$client, $downloader] = self::clientWith(array_fill_keys($urls, [200, 'body']));

            $client->fetchAll($urls);
        } finally {
            $before === false ? Platform::clearEnv('COMPOSER_MAX_PARALLEL_HTTP') : Platform::putEnv('COMPOSER_MAX_PARALLEL_HTTP', $before);
        }

        self::assertSame($limit, $downloader->mostInFlight);
        self::assertCount(60, $downloader->started);
    }

    public function testAQueuedRedirectTargetDoesNotStartAfterTheBudgetEnds(): void
    {
        $url = 'https://api.github.com/repos/vendor/name';
        $target = 'https://objects.example.com/name';
        $held = new \stdClass();
        $now = static function () use ($held): float {
            return ($held->downloader ?? null) instanceof ScriptedDownloader && $held->downloader->waits >= 2 ? 6.0 : 0.0;
        };
        [$client, $downloader] = self::clientWith([$url => $target, $target => [200, 'moved']], Deadline::inSeconds(5.0, $now));
        $held->downloader = $downloader;

        $results = $client->fetchAll([$url], self::HEADERS);

        self::assertSame([$url => [0, null, 'install-time budget spent']], self::summarize($results));
        self::assertSame([$url], $downloader->started, 'the target does not start after the budget');
    }

    /** The deadline is past for the first redirect, so it is past for each one after it. With one slot, each stop frees it. */
    public function testEachRedirectTargetAfterTheBudgetReadsBudgetSpent(): void
    {
        $first = 'https://api.github.com/repos/vendor/one';
        $second = 'https://api.github.com/repos/vendor/two';
        $held = new \stdClass();
        $now = static function () use ($held): float {
            return ($held->downloader ?? null) instanceof ScriptedDownloader && $held->downloader->settled >= 1 ? 6.0 : 0.0;
        };
        [$client, $downloader] = self::clientWith([
            $first => 'https://objects.example.com/one',
            $second => 'https://objects.example.com/two',
        ], Deadline::inSeconds(5.0, $now), 1);
        $held->downloader = $downloader;

        $results = $client->fetchAll([$first, $second], self::HEADERS);

        self::assertSame([$first => [0, null, 'install-time budget spent'], $second => [0, null, 'install-time budget spent']], self::summarize($results));
    }

    /** Composer asks before each hop too, so a chain that reaches the budget stops at its next hop. */
    public function testASameOriginHopAfterTheBudgetEndsIsNotFollowed(): void
    {
        $url = 'https://api.github.com/repos/vendor/name';
        $hop = 'https://api.github.com/repositories/1';
        $held = new \stdClass();
        $now = static function () use ($held): float {
            return ($held->downloader ?? null) instanceof ScriptedDownloader && $held->downloader->asked >= 2 ? 6.0 : 0.0;
        };
        [$client, $downloader] = self::clientWith([$url => $hop, $hop => [200, 'moved']], Deadline::inSeconds(5.0, $now));
        $held->downloader = $downloader;

        $results = $client->fetchAll([$url], self::HEADERS);

        self::assertSame([$url => [0, null, 'install-time budget spent']], self::summarize($results));
        self::assertSame([], $downloader->followed, 'the hop after the budget is not followed');
    }

    /** The first wait() spends part of the budget, so the request to a redirect's target gets only what is left. */
    public function testARedirectTargetTakesTheTimeoutThatIsLeftAfterTheFirstWait(): void
    {
        $url = 'https://api.github.com/repos/vendor/name';
        $target = 'https://objects.example.com/name';
        $held = new \stdClass();
        $now = static function () use ($held): float {
            return ($held->downloader ?? null) instanceof ScriptedDownloader && $held->downloader->settled >= 1 ? 4.0 : 0.0;
        };
        [$client, $downloader] = self::clientWith([$url => $target, $target => [200, 'moved']], Deadline::inSeconds(5.0, $now));
        $held->downloader = $downloader;

        $client->fetchAll([$url], self::HEADERS);

        self::assertSame([$url, $target], array_column($downloader->requests, 'url'));
        $options = $downloader->requests[1]['options'];
        self::assertIsArray($options['http']);
        self::assertSame(1, $options['http']['timeout'], '5s budget, 4s gone at the redirect -> 1s left');
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
}

/**
 * A downloader that answers each URL from a script, leaves an unscripted URL pending, and records
 * every request and wait(). It asks `prevent_url_access_callable` when a request starts, as Composer's
 * CurlDownloader does. A string answer is a redirect, which it follows with the request's headers,
 * as Composer does, unless `prevent_url_access_callable` refuses the target.
 */
final class ScriptedDownloader extends HttpDownloader
{
    /** @var list<array{url: string, options: array<string, mixed>}> the requests add() saw, in order */
    public array $requests = [];
    /** @var list<array{url: string, header: mixed}> the redirect targets that a request reached, with the headers it replayed */
    public array $followed = [];
    /** @var list<string> the requests that started in wait(), in order */
    public array $started = [];
    public int $waits = 0;
    /** the most requests that were added and not yet answered at one time */
    public int $mostInFlight = 0;
    /** the wait() calls that returned */
    public int $settled = 0;
    /** the times that it asked `prevent_url_access_callable` */
    public int $asked = 0;
    /** the times that a request read its options to block until it ends */
    public int $holding = 0;
    /** answer a request inside add(), as Composer does without curl */
    public bool $answersInAdd = false;
    /** @var list<int> the call stack depth of each add() */
    public array $depths = [];

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
        $this->depths[] = \count(debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        /** @var Deferred<Response> $deferred */
        $deferred = new Deferred();
        $promise = $deferred->promise();
        if ($this->answersInAdd && $this->answers($url, $deferred, $options)) {
            return $promise;
        }
        $this->pending[] = [$url, $deferred, $options];
        $this->mostInFlight = max($this->mostInFlight, \count($this->pending));

        return $promise;
    }

    /** @return array<mixed> */
    public function getOptions(): array
    {
        ++$this->holding;

        return parent::getOptions();
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
        $unanswered = [];
        // As the real downloader, it also runs the requests that an answer's callback adds.
        while ($this->pending !== []) {
            $request = array_shift($this->pending);
            if (!$this->answers($request[0], $request[1], $request[2])) {
                $unanswered[] = $request;
            }
        }
        $this->pending = $unanswered;
        ++$this->settled;
    }

    /**
     * @param non-empty-string     $url
     * @param Deferred<Response>   $deferred
     * @param array<string, mixed> $options
     *
     * @return bool false when the script leaves the request without an answer
     */
    private function answers(string $url, Deferred $deferred, array $options): bool
    {
        $refuse = $options['prevent_url_access_callable'] ?? null;
        if ($this->refuses($refuse, $url)) {
            $deferred->reject(new TransportException('Access to "'.$url.'" is blocked.'));

            return true;
        }
        $this->started[] = $url;
        $answer = $this->script[$url] ?? null;
        if (\is_string($answer)) {
            if ($this->refuses($refuse, $answer)) {
                $deferred->reject(new TransportException('Access to "'.$answer.'" is blocked.'));

                return true;
            }
            $http = $options['http'] ?? [];
            $this->followed[] = ['url' => $answer, 'header' => \is_array($http) ? ($http['header'] ?? null) : null];
            $answer = $this->script[$answer] ?? null;
        }
        if ($answer instanceof \Throwable) {
            $deferred->reject($answer);
        } elseif (\is_array($answer)) {
            $deferred->resolve(new Response(['url' => $url], $answer[0], [], $answer[1]));
        } else {
            return false;
        }

        return true;
    }

    /** @param mixed $refuse the request's `prevent_url_access_callable`, if any */
    private function refuses($refuse, string $url): bool
    {
        if (!\is_callable($refuse)) {
            return false;
        }
        ++$this->asked;

        return $refuse($url) === true;
    }
}
