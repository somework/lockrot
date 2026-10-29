<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Util\Filesystem;
use Composer\Util\HttpDownloader;
use Lockrot\Clock;
use Lockrot\Composer\ComposerHttpClient;
use Lockrot\Data\Http\HttpResult;
use Lockrot\Deadline;
use Lockrot\Tests\Support\HermeticComposerConfig;
use Lockrot\Tests\Support\PhpBuiltinServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A redirect that Composer's downloader follows replays the request's custom headers, so lockrot's
 * token reaches the redirect's host unless lockrot drops it there. The server listens on 127.0.0.1,
 * and `localhost` names the same server as another host.
 */
final class ComposerHttpClientRedirectTest extends TestCase
{
    private const HEADERS = ['User-Agent: lockrot', 'Authorization: token secret', 'PRIVATE-TOKEN: secret'];

    private const ROUTER = <<<'PHP'
        <?php
        header('Connection: close');
        $path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $port = $_SERVER['SERVER_PORT'];
        file_put_contents(__DIR__.'/requests.log', $_SERVER['HTTP_HOST'].' '.($_SERVER['HTTP_AUTHORIZATION'] ?? '-')."\n", FILE_APPEND);
        if (preg_match('{^/chain/(\d+)$}', $path, $hops) && $hops[1] > 0) {
            header('Location: /chain/'.($hops[1] - 1), true, 302);
            exit;
        }
        if ($path === '/nested/absolute') {
            header('Location: /headers', true, 302);
            exit;
        }
        if ($path === '/nested/relative') {
            header('Location: headers', true, 302);
            exit;
        }
        if ($path === '/multiple-choices') {
            header('Location: /headers', true, 300);
            exit;
        }
        if ($path === '/ok-with-location') {
            header('Location: /headers', true, 200);
        }
        if ($path === '/limited') {
            header('X-RateLimit-Remaining: 0', true, 403);
            exit;
        }
        if ($path === '/other-host') {
            header('Location: http://localhost:'.$port.'/headers', true, 302);
            exit;
        }
        if ($path === '/same-host') {
            header('Location: /headers', true, 302);
            exit;
        }
        header('Content-Type: application/json');
        echo json_encode([
            'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
            'private_token' => $_SERVER['HTTP_PRIVATE_TOKEN'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'path' => $path,
        ]);
        PHP;

    private string $dir;
    private PhpBuiltinServer $server;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/lockrot-redirect-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/root', 0777, true);
        file_put_contents($this->dir.'/root/router.php', self::ROUTER);
        $this->server = new PhpBuiltinServer($this->dir.'/root', $this->dir.'/root/router.php', 'redirect server');
        $this->server->start('/headers');
    }

    protected function tearDown(): void
    {
        if (!isset($this->server)) {
            return;
        }
        $this->server->stop();
        (new Filesystem())->removeDirectory($this->dir);
    }

    private function client(?bool $refusesRedirects): ComposerHttpClient
    {
        $io = new NullIO();
        $config = HermeticComposerConfig::create($this->dir.'/home', $io);
        $config->merge(['config' => ['secure-http' => false]]);

        return new ComposerHttpClient(Factory::createHttpDownloader($io, $config), $io, $config, Clock::fixed('2026-09-14T00:00:00+00:00'), null, $refusesRedirects);
    }

    private function fetch(string $path, ?bool $refusesRedirects = null): HttpResult
    {
        $url = $this->server->url().$path;

        return $this->client($refusesRedirects)->fetchAll([$url], self::HEADERS)[$url];
    }

    /** @return array<mixed> */
    private function headersSeenAfter(string $path, ?bool $refusesRedirects = null): array
    {
        $result = $this->fetch($path, $refusesRedirects);

        self::assertSame(200, $result->status(), (string) $result->error());
        $seen = json_decode((string) $result->body(), true);
        self::assertIsArray($seen);

        return $seen;
    }

    public function testARedirectToAnotherHostArrivesWithoutLockrotsCredentials(): void
    {
        if (!HttpDownloader::isCurlEnabled()) {
            self::markTestSkipped('Composer takes prevent_url_access_callable through curl only');
        }
        self::assertSame(
            ['authorization' => null, 'private_token' => null, 'user_agent' => 'lockrot', 'path' => '/headers'],
            $this->headersSeenAfter('/other-host')
        );
    }

    public function testARedirectOnTheSameHostKeepsLockrotsCredentials(): void
    {
        self::assertSame(
            ['authorization' => 'token secret', 'private_token' => 'secret', 'user_agent' => 'lockrot', 'path' => '/headers'],
            $this->headersSeenAfter('/same-host')
        );
    }

    /** A downloader that cannot stop a redirect, as Composer's downloader is without curl. */
    public function testARedirectToAnotherHostArrivesWithoutLockrotsCredentialsWithoutCurl(): void
    {
        self::assertSame(
            ['authorization' => null, 'private_token' => null, 'user_agent' => 'lockrot', 'path' => '/headers'],
            $this->headersSeenAfter('/other-host', false)
        );
    }

    /** Such a downloader sends the requests with lockrot's credentials one at a time, so each one checks the install-time budget. */
    public function testARequestWithLockrotsCredentialsAfterTheBudgetRanOutIsNotSent(): void
    {
        $readings = [0.0, 10.0];
        $now = static function () use (&$readings): float {
            return \count($readings) > 1 ? (float) array_shift($readings) : $readings[0];
        };
        $io = new NullIO();
        $config = HermeticComposerConfig::create($this->dir.'/home', $io);
        $config->merge(['config' => ['secure-http' => false]]);
        $client = new ComposerHttpClient(Factory::createHttpDownloader($io, $config), $io, $config, Clock::fixed('2026-09-14T00:00:00+00:00'), Deadline::inSeconds(5.0, $now), false);
        $url = $this->server->url().'/headers';

        $result = $client->fetchAll([$url], self::HEADERS)[$url];

        self::assertSame([0, null], [$result->status(), $result->body()]);
    }

    public function testARedirectOnTheSameHostKeepsLockrotsCredentialsWithoutCurl(): void
    {
        self::assertSame(
            ['authorization' => 'token secret', 'private_token' => 'secret', 'user_agent' => 'lockrot', 'path' => '/headers'],
            $this->headersSeenAfter('/same-host', false)
        );
    }

    /** A downloader that cannot stop a redirect names the other host to lockrot, and lockrot sends that host only a request without its credentials. */
    public function testNoRequestToAnotherHostCarriesLockrotsCredentialsWithoutCurl(): void
    {
        $this->headersSeenAfter('/other-host', false);

        $toOtherHost = array_values(preg_grep('{^localhost:}', file($this->dir.'/root/requests.log', \FILE_IGNORE_NEW_LINES) ?: []) ?: []);

        self::assertSame(['localhost:'.parse_url($this->server->url(), \PHP_URL_PORT).' -'], $toOtherHost);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function locations(): iterable
    {
        yield 'a path from the root' => ['/nested/absolute', '/headers'];
        yield 'a path beside the request' => ['/nested/relative', '/nested/headers'];
        yield 'a 300 answer' => ['/multiple-choices', '/headers'];
        yield 'a Location on a 200 answer, which is not a redirect' => ['/ok-with-location', '/ok-with-location'];
    }

    /**
     * @dataProvider locations
     */
    #[DataProvider('locations')]
    public function testARedirectWithLockrotsCredentialsGoesWhereComposerSendsItWithoutCurl(string $path, string $answeredPath): void
    {
        self::assertSame($answeredPath, $this->headersSeenAfter($path, false)['path']);
    }

    /** Composer's own downloaders follow at most 20 redirects. */
    public function testARequestWithLockrotsCredentialsFollowsAtMostTwentyRedirectsWithoutCurl(): void
    {
        self::assertSame('/chain/0', $this->headersSeenAfter('/chain/20', false)['path']);

        $tooFar = $this->fetch('/chain/21', false);

        self::assertSame([0, null], [$tooFar->status(), $tooFar->body()]);
    }

    public function testAFailedRequestWithLockrotsCredentialsKeepsTheAnswersHeadersWithoutCurl(): void
    {
        $result = $this->fetch('/limited', false);

        self::assertSame([403, '0'], [$result->status(), $result->header('X-RateLimit-Remaining')]);
    }

    public function testEachRequestWithLockrotsCredentialsGetsItsAnswerWithoutCurl(): void
    {
        $urls = [$this->server->url().'/headers', $this->server->url().'/same-host'];

        $results = $this->client(false)->fetchAll($urls, self::HEADERS);

        self::assertSame([200, 200], [$results[$urls[0]]->status(), $results[$urls[1]]->status()]);
    }
}
