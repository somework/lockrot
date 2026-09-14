<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Composer\Config;
use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Repository\RepositoryFactory;
use Composer\Repository\RepositoryInterface;
use Lockrot\Data\Http\HttpResult;
use Lockrot\Data\Http\RecordedHttpClient;
use Lockrot\Lock\LockFile;
use Symfony\Component\Process\Process;

/**
 * Serves recorded p2 envelopes over a real `php -S` HTTP server so tests can exercise
 * Composer\Repository\ComposerRepository against a `type: composer` repository without
 * touching the network. The docroot is a plain filesystem mirror of the p2 metadata-url
 * layout (`packages.json` + `p2/<vendor>/<name>[~dev].json`); recorded envelopes with a
 * non-200 status (or missing entirely) are simply not written, so the server answers 404
 * for them exactly like the real Packagist p2 endpoint would.
 */
final class FixtureRepositoryServer
{
    private const SUFFIXES = ['', '~dev'];
    private const START_TIMEOUT_SECONDS = 5.0;
    private const POLL_INTERVAL_MICROSECONDS = 20000;
    private const ROUTER_FILENAME = 'router.php';

    /**
     * php -S's static file mode reuses keep-alive connections in a way that measurably stalls
     * (not merely slows down) once a Composer repository issues enough sequential requests over
     * the same connection pool — a 200-package load chunked at 10 reliably wedged after ~16
     * chunks in local testing. A tiny router script that forces `Connection: close` on every
     * response (falling through to the built-in static handler via `return false`) makes curl
     * open a fresh connection per request instead of reusing a stale one, which eliminates the
     * stall entirely; static-only serving (`-t docroot` with no router) is not used here for that
     * reason.
     */
    private const ROUTER_SCRIPT = <<<'PHP'
        <?php
        header('Connection: close');
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $file = $path === null ? null : __DIR__.$path;
        if ($file !== null && is_file($file)) {
            return false;
        }
        http_response_code(404);
        PHP;

    private string $docroot;
    private string $cacheDir;
    private int $port;
    private ?Process $process = null;

    private function __construct(string $docroot, string $cacheDir, int $port)
    {
        $this->docroot = $docroot;
        $this->cacheDir = $cacheDir;
        $this->port = $port;
    }

    /** @param list<string> $lockFiles absolute paths of composer.lock files whose package names to serve */
    public static function fromLockFiles(array $lockFiles, string $envelopeDir = __DIR__.'/../fixtures/http/p2'): self
    {
        $docroot = self::freshTempDir('lockrot-fixture-repo-');
        $cacheDir = self::freshTempDir('lockrot-fixture-cache-');
        self::writePackagesJson($docroot);
        file_put_contents($docroot.'/'.self::ROUTER_FILENAME, self::ROUTER_SCRIPT);

        foreach (self::namesFromLockFiles($lockFiles) as $name) {
            foreach (self::SUFFIXES as $suffix) {
                self::writeEnvelopeIfOk($docroot, $envelopeDir, $name, $suffix);
            }
        }

        return new self($docroot, $cacheDir, self::freePort());
    }

    public function start(): void
    {
        // Deliberately not setting PHP_CLI_SERVER_WORKERS: the pcntl-forked worker pool it enables
        // was measured to make concurrent Composer downloads *less* reliable here (dropped
        // connections under a 200+ package load), while php -S's plain single-process event loop
        // serves the same load in well under a second once ROUTER_SCRIPT forces connections closed.
        $this->process = new Process([
            \PHP_BINARY, '-S', '127.0.0.1:'.$this->port, '-t', $this->docroot, $this->docroot.'/'.self::ROUTER_FILENAME,
        ]);
        $this->process->start();

        $context = stream_context_create(['http' => ['timeout' => 0.2, 'ignore_errors' => true]]);
        $deadline = microtime(true) + self::START_TIMEOUT_SECONDS;
        while (microtime(true) < $deadline) {
            if (!$this->process->isRunning()) {
                $error = $this->process->getErrorOutput();
                $this->process = null;
                throw new \RuntimeException('fixture repository server exited before it started answering: '.$error);
            }
            $body = @file_get_contents($this->url().'/packages.json', false, $context);
            if ($body !== false) {
                return;
            }
            usleep(self::POLL_INTERVAL_MICROSECONDS);
        }

        $this->stop();
        throw new \RuntimeException('fixture repository server did not answer packages.json within '.self::START_TIMEOUT_SECONDS.'s');
    }

    public function stop(): void
    {
        if ($this->process !== null) {
            $this->process->stop();
            $this->process = null;
        }
        self::removeDir($this->docroot);
        self::removeDir($this->cacheDir);
    }

    public function __destruct()
    {
        $this->stop();
    }

    public function url(): string
    {
        return 'http://127.0.0.1:'.$this->port;
    }

    public function config(): Config
    {
        $config = Factory::createConfig(new NullIO());
        $config->merge([
            'config' => ['cache-dir' => $this->cacheDir, 'secure-http' => false],
            'repositories' => ['packagist.org' => false, 'fixture' => ['type' => 'composer', 'url' => $this->url()]],
        ]);

        return $config;
    }

    /** @return list<RepositoryInterface> */
    public function repositories(): array
    {
        $io = new NullIO();
        $config = $this->config();
        $manager = RepositoryFactory::manager($io, $config, Factory::createHttpDownloader($io, $config));

        return array_values(RepositoryFactory::defaultRepos($io, $config, $manager));
    }

    /**
     * @param list<string> $lockFiles
     *
     * @return list<string>
     */
    private static function namesFromLockFiles(array $lockFiles): array
    {
        /** @var array<string, true> $names */
        $names = [];
        foreach ($lockFiles as $lockFile) {
            $lock = LockFile::fromFile($lockFile);
            foreach ($lock->packages(true) as $package) {
                $names[$package->name()] = true;
            }
        }

        return array_keys($names);
    }

    private static function writePackagesJson(string $docroot): void
    {
        $body = json_encode(['metadata-url' => '/p2/%package%.json']);
        if ($body === false) {
            throw new \RuntimeException('cannot encode packages.json for the fixture repository server');
        }
        file_put_contents($docroot.'/packages.json', $body);
    }

    private static function writeEnvelopeIfOk(string $docroot, string $envelopeDir, string $name, string $suffix): void
    {
        $url = 'https://repo.packagist.org/p2/'.$name.$suffix.'.json';
        $path = RecordedHttpClient::pathFor($envelopeDir, $url);
        if (!is_file($path)) {
            return;
        }
        $raw = file_get_contents($path);
        $result = $raw === false ? null : HttpResult::fromEnvelopeJson($url, $raw);
        if ($result === null || $result->status() !== 200 || $result->body() === null) {
            return;
        }

        $target = $docroot.'/p2/'.$name.$suffix.'.json';
        $dir = \dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create docroot directory: '.$dir);
        }
        file_put_contents($target, $result->body());
    }

    private static function freshTempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir().'/'.$prefix.uniqid('', true);
        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create temp dir: '.$dir);
        }

        return $dir;
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path) && !is_link($path)) {
                self::removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private static function freePort(): int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new \RuntimeException(\sprintf('cannot bind a free port: %s (%d)', $errstr, $errno));
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        $parts = explode(':', (string) $name);
        $port = (int) end($parts);
        if ($port <= 0) {
            throw new \RuntimeException('cannot determine a free port from address: '.$name);
        }

        return $port;
    }
}
