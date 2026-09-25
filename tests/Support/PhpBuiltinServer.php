<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Symfony\Component\Process\Process;

/**
 * One `php -S` process on a free local port: the part {@see FixtureRepositoryServer} and
 * {@see StaticFileServer} share, so neither can leave a server behind.
 *
 * Two lessons are built into how php -S is launched. Its access log goes straight to a file:
 * php -S writes one line per request to stderr, and piped through Symfony\Process that stream fills
 * the OS pipe buffer (~64 KiB, a few hundred requests) the moment nothing polls the Process object,
 * after which php -S blocks in write(2) — a silent hang. And php -S is never left to outlive the
 * test process: stop() waits until php -S has exited, and a watchdog kills php -S within about a
 * second once the process that started it is gone, however it went. A PHP process killed by a
 * signal (Infection stopping a timed-out mutant, a `timeout` wrapper, Ctrl-C) or dying of a fatal
 * error runs no destructor, no tearDownAfterClass() and no stop(), and php -S, reparented to init,
 * used to keep running and holding its port indefinitely.
 */
final class PhpBuiltinServer
{
    private const START_TIMEOUT_SECONDS = 5.0;
    private const STOP_TIMEOUT_SECONDS = 10.0;
    private const POLL_INTERVAL_MICROSECONDS = 20000;

    /**
     * Runs under `sh -c`, whose PID is the one Symfony\Process tracks and signals. php -S runs in the
     * background and the shell waits on it; a SIGTERM from stop() interrupts that wait, and the trap
     * kills php -S and waits for it too, so the shell exits only once the port is free. The
     * watchdog polls the owning PID and kills php -S once the owner is gone; it is a separate
     * process because nothing else is left to act once the owner has been SIGKILLed.
     *
     * Arguments, in order: the PHP binary, the owner PID, the port, the docroot, the router script,
     * the log file.
     */
    private const LAUNCHER = <<<'SH'
        php_binary=$1 owner=$2 port=$3 docroot=$4 router=$5 log=$6
        "$php_binary" -S "127.0.0.1:$port" -t "$docroot" "$router" >"$log" 2>&1 </dev/null &
        server=$!
        ( while kill -0 "$owner" 2>/dev/null; do sleep 1; done; kill "$server" 2>/dev/null ) </dev/null >/dev/null 2>&1 &
        watchdog=$!
        trap 'kill "$server" "$watchdog" 2>/dev/null; wait "$server"; exit 0' TERM INT HUP
        wait "$server"
        status=$?
        kill "$watchdog" 2>/dev/null
        exit $status
        SH;

    private string $docroot;
    private string $router;
    private string $label;
    private int $port;
    private ?Process $process = null;
    private ?string $logFile = null;

    /**
     * @param string $router absolute path of the router script every request goes through
     * @param string $label  what the server is, for error messages
     */
    public function __construct(string $docroot, string $router, string $label)
    {
        $this->docroot = $docroot;
        $this->router = $router;
        $this->label = $label;
        $this->port = self::freePort();
    }

    /** @return non-empty-string */
    public function url(): string
    {
        return 'http://127.0.0.1:'.$this->port;
    }

    /** @param string $probePath a path, with its leading slash, that answers once php -S is up */
    public function start(string $probePath): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'lockrot-server-log-');
        if ($logFile === false) {
            throw new \RuntimeException('cannot create a log file for the '.$this->label);
        }
        $this->logFile = $logFile;
        $owner = getmypid();
        if ($owner === false) {
            throw new \RuntimeException('cannot tell the '.$this->label.' which process owns it');
        }

        $this->process = new Process(['/bin/sh', '-c', self::LAUNCHER, 'lockrot-php-server', \PHP_BINARY, (string) $owner, (string) $this->port, $this->docroot, $this->router, $logFile]);
        $this->process->setTimeout(null);
        $this->process->start();

        $context = stream_context_create(['http' => ['timeout' => 0.2, 'ignore_errors' => true]]);
        $deadline = microtime(true) + self::START_TIMEOUT_SECONDS;
        while (microtime(true) < $deadline) {
            if (!$this->process->isRunning()) {
                $error = $this->readLog();
                $this->process = null;

                throw new \RuntimeException($this->label.' exited before it started answering: '.$error);
            }
            if (@file_get_contents($this->url().$probePath, false, $context) !== false) {
                return;
            }
            usleep(self::POLL_INTERVAL_MICROSECONDS);
        }

        $error = $this->readLog();
        $this->stop();

        throw new \RuntimeException($this->label.' did not answer '.$probePath.' within '.self::START_TIMEOUT_SECONDS.'s: '.$error);
    }

    /** Stops php -S and returns once it has exited and let go of the port. Safe to call more than once. */
    public function stop(): void
    {
        if ($this->process !== null) {
            $this->process->stop(self::STOP_TIMEOUT_SECONDS);
            $this->process = null;
        }
        if ($this->logFile !== null) {
            if (is_file($this->logFile)) {
                unlink($this->logFile);
            }
            $this->logFile = null;
        }
    }

    /** The access log so far; empty before start() and after stop(). */
    public function readLog(): string
    {
        if ($this->logFile === null || !is_file($this->logFile)) {
            return '';
        }
        $contents = file_get_contents($this->logFile);

        return $contents === false ? '' : $contents;
    }

    public function clearLog(): void
    {
        if ($this->logFile !== null) {
            file_put_contents($this->logFile, '');
        }
    }

    public function __destruct()
    {
        $this->stop();
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
