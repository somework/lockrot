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

/**
 * Serves recorded p2 envelopes over a real `php -S` HTTP server, so tests can exercise
 * Composer\Repository\ComposerRepository against a `type: composer` repository without the
 * network. The docroot mirrors the p2 metadata-url layout (`packages.json` +
 * `p2/<vendor>/<name>[~dev].json`). A recorded envelope with a non-200 status, or none, is not
 * written, so the server answers 404 for it as the real Packagist p2 endpoint does.
 */
final class FixtureRepositoryServer
{
    private const SUFFIXES = ['', '~dev'];
    private const ROUTER_FILENAME = 'router.php';

    /**
     * Serves every request itself rather than falling through to php -S's built-in static handler
     * with `return false`: that handler resets the response headers, so a `Last-Modified` header set
     * before `return false` never reaches the client. Composer's ComposerRepository caches that header
     * and revalidates against it. Offline, a cached `last-modified` lets it fake a 304 rather than a
     * synthetic 404. `Connection: close` on every response makes curl open a fresh connection per
     * request.
     */
    private const ROUTER_SCRIPT = <<<'PHP'
        <?php
        header('Connection: close');
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/api/security-advisories/') {
            $names = isset($_POST['packages']) && is_array($_POST['packages']) ? array_values($_POST['packages']) : [];
            file_put_contents(__DIR__.'/advisory-requests.log', json_encode($names)."\n", FILE_APPEND);
            $known = json_decode((string) @file_get_contents(__DIR__.'/advisories.json'), true);
            $answer = [];
            foreach ($names as $name) {
                if (is_array($known) && array_key_exists($name, $known)) {
                    $answer[$name] = $known[$name];
                }
            }
            header('Content-Type: application/json');
            echo json_encode(['advisories' => $answer]);
            exit;
        }
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
    private PhpBuiltinServer $server;
    /** @var list<string> every package name that the docroot can serve, whatever the recorded envelopes hold */
    private array $names;

    /** @param list<string> $names */
    private function __construct(string $docroot, string $cacheDir, array $names)
    {
        $this->docroot = $docroot;
        $this->cacheDir = $cacheDir;
        $this->server = new PhpBuiltinServer($docroot, $docroot.'/'.self::ROUTER_FILENAME, 'fixture repository server');
        $this->names = $names;
    }

    /**
     * @param list<string> $lockFiles  absolute paths of composer.lock files whose package names to serve
     * @param list<string> $extraNames package names to serve on top of the locks' — the monorepo parents the
     *                                 analyzer loads to date a split package, which no lock lists
     */
    public static function fromLockFiles(array $lockFiles, string $envelopeDir = __DIR__.'/../fixtures/http/p2', array $extraNames = []): self
    {
        $docroot = self::freshTempDir('lockrot-fixture-repo-');
        $cacheDir = self::freshTempDir('lockrot-fixture-cache-');
        self::writePackagesJson($docroot);
        file_put_contents($docroot.'/'.self::ROUTER_FILENAME, self::ROUTER_SCRIPT);

        $names = array_values(array_unique(array_merge(self::namesFromLockFiles($lockFiles), $extraNames)));
        foreach ($names as $name) {
            foreach (self::SUFFIXES as $suffix) {
                self::writeEnvelopeIfOk($docroot, $envelopeDir, $name, $suffix);
            }
        }

        return new self($docroot, $cacheDir, $names);
    }

    /**
     * Turns the fixture into a repository that publishes security advisories the way a Composer
     * repository without an API does: `security-advisories.metadata: true` in `packages.json`, and
     * each advisory list under the `security-advisories` key of the package's own p2 file — which
     * must already be served here. Composer insists on an `available-packages` list for such a
     * repository, so the served names are declared too. Call before {@see start()}.
     *
     * @param array<string, list<array<string, mixed>>> $advisoriesByName p2 advisory records per package name
     */
    public function withSecurityAdvisories(array $advisoriesByName): void
    {
        $body = json_encode(['metadata-url' => '/p2/%package%.json', 'available-packages' => $this->names, 'security-advisories' => ['metadata' => true]]);
        if ($body === false) {
            throw new \RuntimeException('cannot encode packages.json for the fixture repository server');
        }
        file_put_contents($this->docroot.'/packages.json', $body);

        foreach ($advisoriesByName as $name => $advisories) {
            $file = $this->docroot.'/p2/'.$name.'.json';
            $raw = is_file($file) ? file_get_contents($file) : false;
            if ($raw === false) {
                throw new \RuntimeException('no p2 file served for '.$name.'; advisories need one to live in');
            }
            $decoded = json_decode($raw, true);
            if (!\is_array($decoded)) {
                throw new \RuntimeException('unreadable p2 file for '.$name);
            }
            $decoded['security-advisories'] = $advisories;
            file_put_contents($file, (string) json_encode($decoded));
        }
    }

    /**
     * Turns the fixture into a repository that publishes security advisories through an API, as
     * packagist.org does: `security-advisories.api-url` in `packages.json`, and a POST route that
     * answers each requested name found in $advisoriesByName and leaves out every other name. With
     * no requested name found, the answer is `{"advisories": []}`, a list, as packagist.org sends
     * it. Each POST is logged for {@see advisoryRequests()}. Call before {@see start()}.
     *
     * @param array<string, list<array<string, mixed>>> $advisoriesByName advisory records per package name, `[]` for a name the repository knows with no record
     * @param list<string>                              $availablePatterns `available-package-patterns`: Composer asks only for the names they match
     */
    public function withAdvisoryApi(array $advisoriesByName, array $availablePatterns = []): void
    {
        $root = ['metadata-url' => '/p2/%package%.json', 'security-advisories' => ['api-url' => '/api/security-advisories/']];
        if ($availablePatterns !== []) {
            $root['available-package-patterns'] = $availablePatterns;
        }
        self::writeJson($this->docroot.'/packages.json', $root);
        self::writeJson($this->docroot.'/advisories.json', (object) $advisoriesByName);
    }

    /**
     * {@see withAdvisoryApi()} with the answer that `bin/record-fixtures` recorded for a fixture
     * directory: every advisory that packagist.org held for each of its locked names.
     */
    public function withRecordedAdvisoryApi(string $fixtureDir, string $recordingDir = __DIR__.'/../fixtures/http/advisories'): void
    {
        $path = $recordingDir.'/'.basename($fixtureDir).'.json';
        $raw = is_file($path) ? file_get_contents($path) : false;
        $result = $raw === false ? null : HttpResult::fromEnvelopeJson('https://packagist.org/api/security-advisories/', $raw);
        $answer = $result === null || $result->body() === null ? null : json_decode($result->body(), true);
        if (!\is_array($answer) || !\is_array($answer['advisories'] ?? null)) {
            throw new \RuntimeException('no recorded advisory answer for '.$fixtureDir.'; run bin/record-fixtures '.$fixtureDir);
        }
        /** @var array<string, list<array<string, mixed>>> $advisories */
        $advisories = $answer['advisories'];
        $this->withAdvisoryApi($advisories);
    }

    /**
     * The package names of each advisory POST answered so far, in order.
     *
     * @return list<list<string>>
     */
    public function advisoryRequests(): array
    {
        $log = $this->docroot.'/advisory-requests.log';
        $raw = is_file($log) ? file_get_contents($log) : false;
        if ($raw === false) {
            return [];
        }
        $requests = [];
        foreach (explode("\n", trim($raw)) as $line) {
            $names = json_decode($line, true);
            if (\is_array($names)) {
                $requests[] = array_values(array_filter($names, 'is_string'));
            }
        }

        return $requests;
    }

    /** Serve a `packages.json` that is not JSON — what a misconfigured private repository answers with. Call before {@see start()}. */
    public function withCorruptPackagesJson(): void
    {
        file_put_contents($this->docroot.'/packages.json', '{not json');
    }

    public function start(): void
    {
        $this->server->start('/packages.json');
    }

    /** Stops php -S — returning once its port is free — and removes the docroot and the cache. */
    public function stop(): void
    {
        $this->server->stop();
        self::removeDir($this->docroot);
        self::removeDir($this->cacheDir);
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
        $log = $this->server->readLog();
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

    public function resetRequestCount(): void
    {
        $this->server->clearLog();
    }

    public function __destruct()
    {
        $this->stop();
    }

    /** @return non-empty-string */
    public function url(): string
    {
        return $this->server->url();
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
        self::writeJson($docroot.'/packages.json', ['metadata-url' => '/p2/%package%.json']);
    }

    /** @param mixed $value */
    private static function writeJson(string $path, $value): void
    {
        $body = json_encode($value);
        if ($body === false) {
            throw new \RuntimeException('cannot encode '.basename($path).' for the fixture repository server');
        }
        file_put_contents($path, $body);
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
        $dir = sys_get_temp_dir().'/'.$prefix.bin2hex(random_bytes(8));
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
}
