<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

/**
 * Serves a directory of prepared files over a real `php -S` HTTP server.
 *
 * Separate from {@see FixtureRepositoryServer} because that one is a p2 metadata mirror: it builds
 * its own docroot out of recorded Composer envelopes and its router exists to send the
 * `Last-Modified` header ComposerRepository revalidates against. What a self-update test needs is
 * the opposite — a docroot the test itself writes, and bytes handed back untouched — so the two
 * share only the process underneath, {@see PhpBuiltinServer}.
 */
final class StaticFileServer
{
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

    private PhpBuiltinServer $server;

    private function __construct(string $docroot)
    {
        $this->server = new PhpBuiltinServer($docroot, $docroot.'/'.self::ROUTER_FILENAME, 'static file server');
    }

    /** @param string $docroot an existing directory whose files are served verbatim */
    public static function serving(string $docroot): self
    {
        if (!is_dir($docroot)) {
            throw new \RuntimeException('not a directory: '.$docroot);
        }
        file_put_contents($docroot.'/'.self::ROUTER_FILENAME, self::ROUTER_SCRIPT);
        file_put_contents($docroot.'/'.self::PROBE_FILENAME, "ready\n");

        return new self($docroot);
    }

    public function start(): void
    {
        $this->server->start('/'.self::PROBE_FILENAME);
    }

    /** Stops php -S, returning once its port is free. The docroot stays: it belongs to the caller. */
    public function stop(): void
    {
        $this->server->stop();
    }

    /** @return non-empty-string */
    public function url(): string
    {
        return $this->server->url();
    }
}
