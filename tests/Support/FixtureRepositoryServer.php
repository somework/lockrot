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
     * Serves every request itself rather than falling through to php -S's built-in static handler
     * (`return false`): that built-in handler resets the response headers it generates, discarding
     * anything the router already sent via `header()`, so a `Last-Modified` header set before
     * `return false` never reaches the client. Composer's ComposerRepository caches that header on a
     * first fetch and revalidates against it on later ones (offline, a cached `last-modified` is
     * what lets it fake a 304 rather than a synthetic 404), so without a router that sends it itself,
     * an offline re-fetch of an already-cached file could never be told apart from one that was
     * never cached at all. `Connection: close` on every response makes curl open a fresh connection
     * per request instead of reusing one across the whole load.
     */
    private const ROUTER_SCRIPT = <<<'PHP'
        <?php
        header('Connection: close');
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $file = $path === null ? null : __DIR__.$path;
        if ($file === null || !is_file($file)) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: application/json');
        header('Last-Modified: '.gmdate('D, d M Y H:i:s', filemtime($file)).' GMT');
        readfile($file);
        PHP;

    private string $docroot;
    private string $cacheDir;
    private int $port;
    private ?Process $process = null;
    private ?string $logFile = null;

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
        $logFile = tempnam(sys_get_temp_dir(), 'lockrot-fixture-log-');
        if ($logFile === false) {
            throw new \RuntimeException('cannot create a log file for the fixture repository server');
        }
        $this->logFile = $logFile;

        // php -S writes one access-log line per request to stderr. Symfony\Process normally pipes
        // a child process's stdout/stderr through the OS's pipe buffer (~64 KiB on macOS/Linux, a
        // few hundred requests' worth of log lines) and only drains it when something calls back
        // into the Process object (isRunning(), wait(), ...); once the startup poll loop below
        // returns, nothing does that again until stop() runs at the end of the test. A real load
        // (a 200-package analysis is ~1000 requests) fills that pipe and php -S then blocks in
        // write(2) — a genuine, silent hang, not a slowdown, and nothing to do with keep-alive or
        // connection reuse. Running through a shell with `> logfile 2>&1` redirects both streams
        // straight to a file at the OS level, so there is no pipe for php -S to ever fill.
        //
        // The leading `exec` matters: without it, `/bin/sh -c '... > logfile 2>&1'` on this system
        // forks php -S as a *child* of the shell rather than replacing the shell process, so the PID
        // Symfony\Process tracks is the shell's — stop() then kills the shell and php -S is silently
        // orphaned (reparented to init) and keeps running and holding the port forever. `exec`
        // forces the shell to replace itself with php -S, so the tracked PID is the real one.
        $command = \sprintf(
            'exec %s -S 127.0.0.1:%d -t %s %s > %s 2>&1',
            escapeshellarg(\PHP_BINARY),
            $this->port,
            escapeshellarg($this->docroot),
            escapeshellarg($this->docroot.'/'.self::ROUTER_FILENAME),
            escapeshellarg($logFile)
        );
        $this->process = Process::fromShellCommandline($command);
        $this->process->setTimeout(null);
        $this->process->start();

        $context = stream_context_create(['http' => ['timeout' => 0.2, 'ignore_errors' => true]]);
        $deadline = microtime(true) + self::START_TIMEOUT_SECONDS;
        while (microtime(true) < $deadline) {
            if (!$this->process->isRunning()) {
                $error = $this->readLog();
                $this->process = null;
                throw new \RuntimeException('fixture repository server exited before it started answering: '.$error);
            }
            $body = @file_get_contents($this->url().'/packages.json', false, $context);
            if ($body !== false) {
                return;
            }
            usleep(self::POLL_INTERVAL_MICROSECONDS);
        }

        $error = $this->readLog();
        $this->stop();
        throw new \RuntimeException('fixture repository server did not answer packages.json within '.self::START_TIMEOUT_SECONDS.'s: '.$error);
    }

    public function stop(): void
    {
        if ($this->process !== null) {
            $this->process->stop();
            $this->process = null;
        }
        if ($this->logFile !== null) {
            if (is_file($this->logFile)) {
                unlink($this->logFile);
            }
            $this->logFile = null;
        }
        self::removeDir($this->docroot);
        self::removeDir($this->cacheDir);
    }

    private function readLog(): string
    {
        if ($this->logFile === null || !is_file($this->logFile)) {
            return '';
        }
        $contents = file_get_contents($this->logFile);

        return $contents === false ? '' : $contents;
    }

    /**
     * Counts requests answered so far. php -S logs one `Accepted`/`Closing` pair per connection to
     * its access log, and the router sends `Connection: close` on every response, so each
     * connection the log records corresponds to exactly one request — counting `Accepted` lines
     * therefore counts requests. Returns 0 before start() (no log file yet) or once stop() has
     * removed it.
     */
    public function requestCount(): int
    {
        $log = $this->readLog();
        if ($log === '') {
            return 0;
        }
        $count = 0;
        foreach (explode("\n", $log) as $line) {
            if (strpos($line, 'Accepted') !== false) {
                ++$count;
            }
        }

        return $count;
    }

    /** Truncates the access log so a subsequent requestCount() reflects only requests made after this call. */
    public function resetRequestCount(): void
    {
        if ($this->logFile !== null) {
            file_put_contents($this->logFile, '');
        }
    }

    public function __destruct()
    {
        $this->stop();
    }

    /** @return non-empty-string */
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
