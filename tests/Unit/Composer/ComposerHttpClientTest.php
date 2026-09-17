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

    /**
     * Url::getOrigin() resolves an empty URL to an empty origin, which an auth.json entry with an
     * empty host would match — and Composer would then add credentials to a request it cannot even
     * send. There is nothing to authenticate, so the headers are handed back before the origin is
     * ever looked up.
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
     * so lockrot's own Authorization header has to go: the `api.github.com`-only exception belongs
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
     * own, so lockrot's token must stay — read under any other origin, the entry is not found and
     * the token would be dropped from a request nothing then authenticates.
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

    /**
     * A client over a downloader that answers from $script, plus that downloader so a test can read
     * the requests it saw.
     *
     * @param array<string, array{0: int, 1: string}|\Throwable> $script url => [status, body], or an exception to reject with; a URL the script does not mention is never answered at all
     *
     * @return array{0: ComposerHttpClient, 1: ScriptedDownloader}
     */
    private static function clientWith(array $script, ?Deadline $deadline = null): array
    {
        $io = new NullIO();
        $config = new Config(false, sys_get_temp_dir());
        $downloader = new ScriptedDownloader($io, $config, $script);

        return [new ComposerHttpClient($downloader, $io, $config, Clock::fixed(self::NOW), $deadline), $downloader];
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

    /** Every answer is turned into an HttpResult keyed by its own URL, carrying the clock's fetch time. */
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
     * a plugin listening on PRE_FILE_DOWNLOAD throwing, say. Anything other than 0 there would read
     * as a status the server never sent.
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

    /**
     * A URL the downloader never answers still gets a result: the caller reads the map by URL, and a
     * missing key would be indistinguishable from a URL it never asked for.
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

    /** The results only exist once the downloader has been waited on; returning before that would report every URL as unanswered. */
    public function testTheRequestsAreWaitedOnBeforeTheResultsAreRead(): void
    {
        [$client, $downloader] = self::clientWith(['https://a.example.com/1' => [200, 'body']]);

        $results = $client->fetchAll(['https://a.example.com/1']);

        self::assertSame(1, $downloader->waits, 'fetchAll() must wait for the promises it added');
        self::assertSame('body', $results['https://a.example.com/1']->body());
    }

    /** The same URL asked for twice is one request, and one entry in the answer. */
    public function testARepeatedUrlIsRequestedOnce(): void
    {
        [$client, $downloader] = self::clientWith(['https://a.example.com/1' => [200, 'body']]);

        $results = $client->fetchAll(['https://a.example.com/1', 'https://a.example.com/1']);

        self::assertSame(['https://a.example.com/1'], array_column($downloader->requests, 'url'));
        self::assertCount(1, $results);
    }

    /**
     * Each request carries the headers this URL is allowed to send, the timeout the deadline leaves
     * it, and `retry-auth-failure` off — a retry would let Composer's AuthHelper exchange a
     * bitbucket-oauth consumer on a 401 and rewrite composer.json/auth.json on the way, which a
     * report must never do ({@see \Lockrot\Composer\ServiceFactory::bitbucketAuthorizer()} does the
     * exchange itself instead).
     */
    public function testEachRequestCarriesItsHeadersTimeoutAndNoAuthRetry(): void
    {
        [$client, $downloader] = self::clientWith(['https://api.github.com/repos/vendor/name' => [200, '{}']]);

        $client->fetchAll(['https://api.github.com/repos/vendor/name'], self::HEADERS);

        self::assertSame([[
            'url' => 'https://api.github.com/repos/vendor/name',
            'options' => [
                'http' => ['timeout' => ComposerHttpClient::DEFAULT_TIMEOUT, 'header' => self::HEADERS],
                'retry-auth-failure' => false,
            ],
        ]], $downloader->requests);
    }

    /** The timeout is what the install-time budget has left at the moment the requests go out, never the default. */
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
}

/** See {@see ComposerHttpClientTest::clientWith()}. */
final class ScriptedDownloader extends HttpDownloader
{
    /** @var list<array{url: string, options: array<string, mixed>}> the requests add() saw, in order */
    public array $requests = [];
    /** @var int how often wait() was called */
    public int $waits = 0;

    /** @var array<string, array{0: int, 1: string}|\Throwable> */
    private array $script;
    /** @var list<array{0: non-empty-string, 1: Deferred<Response>}> */
    private array $pending = [];

    /** @param array<string, array{0: int, 1: string}|\Throwable> $script */
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
        $this->requests[] = ['url' => $url, 'options' => \is_array($options) ? $options : []];
        /** @var Deferred<Response> $deferred */
        $deferred = new Deferred();
        $this->pending[] = [$url, $deferred];

        return $deferred->promise();
    }

    /**
     * Settles every promise added since the last call, from the script. A URL the script does not
     * mention is left pending for good — the real downloader's "no answer ever arrived" case.
     *
     * @param ?int $index
     *
     * @return void
     */
    public function wait($index = null)
    {
        ++$this->waits;
        $pending = $this->pending;
        $this->pending = [];
        foreach ($pending as [$url, $deferred]) {
            $answer = $this->script[$url] ?? null;
            if ($answer instanceof \Throwable) {
                $deferred->reject($answer);
            } elseif (\is_array($answer)) {
                $deferred->resolve(new Response(['url' => $url], $answer[0], [], $answer[1]));
            }
        }
    }
}
