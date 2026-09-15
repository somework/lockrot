<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Symfony\Component\Process\Process;

/**
 * Serves a directory of prepared files over a real `php -S` HTTP server.
 *
 * Separate from {@see FixtureRepositoryServer} because that one is a p2 metadata mirror: it builds
 * its own docroot out of recorded Composer envelopes and its router exists to send the
 * `Last-Modified` header ComposerRepository revalidates against. What a self-update test needs is
 * the opposite — a docroot the test itself writes, and bytes handed back untouched — so the two
 * share a shape rather than an implementation.
 */
final class StaticFileServer
{
    private const START_TIMEOUT_SECONDS = 5.0;
    private const POLL_INTERVAL_MICROSECONDS = 20000;
    private const ROUTER_FILENAME = 'lockrot-router.php';
    private const PROBE_FILENAME = 'lockrot-ready.txt';

    /**
     * Every response is written by the router rather than by php -S's built-in static handler,
     * because that handler runs a requested `.php` or `.phar` file as a script instead of sending
     * it — and a downloadable `lockrot.phar` is exactly what this server exists to hand out.
     */
    private const ROUTER_SCRIPT = <<<'PHP'
        <?php
        header('Connection: close');
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $file = $path === null ? null : realpath(__DIR__.$path);
        if ($file === null || $file === false || strpos($file, __DIR__.'/') !== 0 || !is_file($file)) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: '.(substr($file, -5) === '.json' ? 'application/json' : 'application/octet-stream'));
        header('Content-Length: '.(string) filesize($file));
        readfile($file);
        PHP;

    private string $docroot;
    private int $port;
    private ?Process $process = null;
    private ?string $logFile = null;

    private function __construct(string $docroot, int $port)
    {
        $this->docroot = $docroot;
        $this->port = $port;
    }

    /** @param string $docroot an existing directory whose files are served verbatim */
    public static function serving(string $docroot): self
    {
        if (!is_dir($docroot)) {
            throw new \RuntimeException('not a directory: '.$docroot);
        }
        file_put_contents($docroot.'/'.self::ROUTER_FILENAME, self::ROUTER_SCRIPT);
        file_put_contents($docroot.'/'.self::PROBE_FILENAME, "ready\n");

        return new self($docroot, self::freePort());
    }

    public function start(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'lockrot-static-log-');
        if ($logFile === false) {
            throw new \RuntimeException('cannot create a log file for the static file server');
        }
        $this->logFile = $logFile;

        // The redirect and the leading `exec` are the two things FixtureRepositoryServer learned the
        // hard way: without the redirect php -S eventually blocks writing its access log into a full
        // pipe, and without `exec` the tracked PID is the shell's, so stop() leaves php -S orphaned
        // and holding the port.
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

                throw new \RuntimeException('static file server exited before it started answering: '.$error);
            }
            if (@file_get_contents($this->url().'/'.self::PROBE_FILENAME, false, $context) !== false) {
                return;
            }
            usleep(self::POLL_INTERVAL_MICROSECONDS);
        }

        $error = $this->readLog();
        $this->stop();

        throw new \RuntimeException('static file server did not answer within '.self::START_TIMEOUT_SECONDS.'s: '.$error);
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

    private function readLog(): string
    {
        if ($this->logFile === null || !is_file($this->logFile)) {
            return '';
        }
        $contents = file_get_contents($this->logFile);

        return $contents === false ? '' : $contents;
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
