<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Tests\Support\StaticFileServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The `php -S` servers the suite starts must never outlive the test run. stop() has to leave the
 * port closed by the time it returns, and a run that dies without cleaning up — Infection killing a
 * timed-out mutant, a `timeout` wrapper, Ctrl-C, a fatal error — must not leave php -S behind,
 * orphaned to init and holding its port: no destructor or tearDownAfterClass() runs in any of those.
 */
final class FixtureServerLifetimeTest extends TestCase
{
    private const SIGKILL = 9;
    private const OWNER_START_TIMEOUT_SECONDS = 10.0;
    private const ORPHAN_GRACE_SECONDS = 5.0;
    private const POLL_INTERVAL_MICROSECONDS = 50000;

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            foreach (glob($dir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
        $this->tempDirs = [];
    }

    public function testStoppingAFixtureRepositoryServerClosesItsPort(): void
    {
        $server = FixtureRepositoryServer::fromLockFiles([]);
        $server->start();
        $port = self::portOf($server->url());
        self::assertTrue(self::isListening($port), 'the server answers once started');

        $server->stop();

        self::assertFalse(self::isListening($port), 'stop() returns only once php -S has let go of the port');
    }

    public function testStoppingAStaticFileServerClosesItsPort(): void
    {
        $server = StaticFileServer::serving($this->tempDir());
        $server->start();
        $port = self::portOf($server->url());
        self::assertTrue(self::isListening($port), 'the server answers once started');

        $server->stop();

        self::assertFalse(self::isListening($port), 'stop() returns only once php -S has let go of the port');
    }

    /** @return iterable<string, array{string}> */
    public static function servers(): iterable
    {
        yield 'fixture repository server' => ['\\'.FixtureRepositoryServer::class.'::fromLockFiles([])'];
        yield 'static file server' => ['\\'.StaticFileServer::class.'::serving($argv[1])'];
    }

    /** @dataProvider servers */
    #[DataProvider('servers')]
    public function testAServerDiesWithTheProcessThatStartedIt(string $factory): void
    {
        $code = \sprintf(
            'require %s; $server = %s; $server->start(); echo $server->url(), "\n"; sleep(60);',
            var_export(\dirname(__DIR__, 2).'/vendor/autoload.php', true),
            $factory
        );
        $owner = new Process([\PHP_BINARY, '-r', $code, $this->tempDir()]);
        $owner->setTimeout(null);
        $owner->start();

        $port = self::portOf(self::firstLine($owner));
        try {
            self::assertTrue(self::isListening($port), 'the server answers while its owner runs');

            // What a killed run looks like from the server's side: its owner is gone and nothing ran
            // stop(), a destructor or a shutdown function.
            $owner->signal(self::SIGKILL);
            $owner->wait();

            self::assertTrue(self::closesWithin($port, self::ORPHAN_GRACE_SECONDS), 'php -S outlived the process that started it');
        } finally {
            if ($owner->isRunning()) {
                $owner->stop(0);
            }
            self::killServerOn($port);
        }
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir().'/lockrot-server-lifetime-'.uniqid('', true);
        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create temp dir: '.$dir);
        }
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private static function firstLine(Process $owner): string
    {
        $deadline = microtime(true) + self::OWNER_START_TIMEOUT_SECONDS;
        while (microtime(true) < $deadline && $owner->isRunning()) {
            $lineEnd = strpos($owner->getOutput(), "\n");
            if ($lineEnd !== false) {
                return substr($owner->getOutput(), 0, $lineEnd);
            }
            usleep(self::POLL_INTERVAL_MICROSECONDS);
        }
        $owner->stop(0);

        throw new \RuntimeException('the owning process never reported its server: '.$owner->getOutput().$owner->getErrorOutput());
    }

    private static function portOf(string $url): int
    {
        $port = parse_url($url, \PHP_URL_PORT);
        if (!\is_int($port)) {
            throw new \RuntimeException('no port in '.$url);
        }

        return $port;
    }

    private static function isListening(int $port): bool
    {
        $socket = @stream_socket_client('tcp://127.0.0.1:'.$port, $errno, $errstr, 1.0);
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }

    private static function closesWithin(int $port, float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        do {
            if (!self::isListening($port)) {
                return true;
            }
            usleep(self::POLL_INTERVAL_MICROSECONDS);
        } while (microtime(true) < $deadline);

        return false;
    }

    /** The test's own cleanup when the server did outlive its owner: this test must not leak what it catches. */
    private static function killServerOn(int $port): void
    {
        if (self::isListening($port)) {
            (new Process(['pkill', '-KILL', '-f', 'php.* -S 127\.0\.0\.1:'.$port.' ']))->run();
        }
    }
}
