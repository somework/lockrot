<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Composer\Composer;
use Composer\Factory;
use Composer\Util\Filesystem;
use Composer\IO\NullIO;
use Composer\Util\HttpDownloader;
use Lockrot\Clock;
use Lockrot\Composer\ComposerHttpClient;
use Lockrot\Tests\Support\HermeticComposerConfig;
use Lockrot\Tests\Support\PhpBuiltinServer;
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
        ]);
        PHP;

    private string $dir;
    private PhpBuiltinServer $server;

    protected function setUp(): void
    {
        if (!HttpDownloader::isCurlEnabled()) {
            self::markTestSkipped('Composer drops the header through curl only');
        }
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

    /** @return array<mixed> */
    private function headersSeenAfter(string $path): array
    {
        $io = new NullIO();
        $config = HermeticComposerConfig::create($this->dir.'/home', $io);
        $config->merge(['config' => ['secure-http' => false]]);
        $client = new ComposerHttpClient(Factory::createHttpDownloader($io, $config), $io, $config, Clock::fixed('2026-09-14T00:00:00+00:00'));
        $url = $this->server->url().$path;

        $result = $client->fetchAll([$url], self::HEADERS)[$url];

        self::assertSame(200, $result->status(), (string) $result->error());
        $seen = json_decode((string) $result->body(), true);
        self::assertIsArray($seen);

        return $seen;
    }

    public function testARedirectToAnotherHostArrivesWithoutLockrotsCredentials(): void
    {
        if (version_compare(Composer::getVersion(), '2.10.0-dev', '<')) {
            self::markTestSkipped('Composer 2.10 or later: an older Composer replays the headers (docs/internals.md#which-credentials)');
        }
        self::assertSame(
            ['authorization' => null, 'private_token' => null, 'user_agent' => 'lockrot'],
            $this->headersSeenAfter('/other-host')
        );
    }

    public function testARedirectOnTheSameHostKeepsLockrotsCredentials(): void
    {
        self::assertSame(
            ['authorization' => 'token secret', 'private_token' => 'secret', 'user_agent' => 'lockrot'],
            $this->headersSeenAfter('/same-host')
        );
    }
}
