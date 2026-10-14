<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Lockrot\Data\Http\RecordedHttpClient;
use Lockrot\Data\Repository\MonorepoParents;
use Symfony\Component\Process\Process;

/**
 * Runs a lockrot command line (the PHAR of any release, or `bin/lockrot`) on a project against
 * recorded answers only. A FixtureRepositoryServer serves the p2 and advisory answers through a
 * Composer home whose only repository it is. The recorded GitHub answers sit in lockrot's activity
 * cache, dated the day of the run, so lockrot reads them as fresh. Every other request goes to a
 * closed proxy port and fails, so the run never reaches the network. The run is verbose:
 * {@see outsideRequests()} and {@see recordingsRead()} read its error output.
 */
final class FixtureCliRun
{
    private const PROXY_ENV = ['HTTP_PROXY', 'HTTPS_PROXY', 'http_proxy', 'https_proxy'];
    private const TOKEN_ENV = ['GITHUB_TOKEN', 'GH_TOKEN', 'GITLAB_TOKEN', 'LOCKROT_GITLAB_TOKEN', 'COMPOSER_AUTH'];

    private FixtureRepositoryServer $server;
    /** @var list<string> */
    private array $githubDirs;
    /** @var array<string, string> the local URL that serves each mirrored repository, by its own URL */
    private array $mirrors;

    /**
     * @param list<string>          $githubDirs
     * @param array<string, string> $mirrors
     */
    private function __construct(FixtureRepositoryServer $server, array $githubDirs, array $mirrors)
    {
        $this->server = $server;
        $this->githubDirs = $githubDirs;
        $this->mirrors = $mirrors;
    }

    /**
     * Starts a fixture server for the names of $lockFiles.
     *
     * @param list<string> $lockFiles
     * @param list<string> $p2Dirs         recorded p2 answers: a name takes the first directory that answered it with 200
     * @param list<string> $githubDirs     recorded GitHub answers: a later directory wins for the same request
     * @param string       $advisoryAnswer the recorded advisory answer, `<dir>/<name>.json`
     * @param bool         $parents        also serve the monorepo parents that `bin/record-fixtures` records
     * @param array<string, string> $mirrors the recording directory of each further `composer` repository, by its URL
     */
    public static function start(array $lockFiles, array $p2Dirs, array $githubDirs, string $advisoryAnswer, bool $parents, array $mirrors = []): self
    {
        $more = array_map(static fn (string $dir): array => ['https://repo.packagist.org/p2/', $dir], \array_slice($p2Dirs, 1));
        $server = FixtureRepositoryServer::fromLockFiles($lockFiles, $p2Dirs[0], $parents ? MonorepoParents::load()->candidates() : [], $more);
        $server->withRecordedAdvisoryApi(basename($advisoryAnswer, '.json'), \dirname($advisoryAnswer));
        $local = [];
        foreach ($mirrors as $url => $dir) {
            $local[$url] = $server->withMirror($url, $dir);
        }
        $server->start();

        return new self($server, $githubDirs, $local);
    }

    /**
     * {@see start()} for one project and a directory in the layout of `bin/record-fixtures --into`.
     * With $mirrors, each `composer` repository of the project's composer.json that `repos/` holds
     * a recording of is served too. Without, the run cannot reach it.
     */
    public static function forRecording(string $projectDir, string $httpDir, bool $mirrors = true): self
    {
        $manifest = JsonPath::decodeFile($projectDir.'/composer.json');
        $recorded = [];
        foreach ($mirrors && JsonPath::has($manifest, ['repositories']) ? JsonPath::arrayAt($manifest, ['repositories']) : [] as $repository) {
            $url = \is_array($repository) && ($repository['type'] ?? null) === 'composer' && \is_string($repository['url'] ?? null) ? rtrim($repository['url'], '/') : null;
            if ($url !== null && is_file(RecordedHttpClient::pathFor($httpDir.'/repos', $url.'/packages.json'))) {
                $recorded[$url] = $httpDir.'/repos';
            }
        }

        return self::start([$projectDir.'/composer.lock'], [$httpDir.'/p2'], [$httpDir.'/github'], $httpDir.'/advisories/'.basename($projectDir).'.json', true, $recorded);
    }

    public function stop(): void
    {
        $this->server->stop();
    }

    /**
     * Runs `php <entry> -d <copy of $projectDir> <$arguments>` at $today, in a new Composer home.
     * An `--output` path in $arguments is relative to the copy, which the run deletes: give it an
     * absolute path.
     *
     * @param string                $entry     the PHAR or bin/lockrot
     * @param list<string>          $arguments
     * @param array<string, string> $files     more files to copy into the project copy, source by name
     */
    public function run(string $entry, string $projectDir, string $today, array $arguments, array $files = []): Process
    {
        $home = self::freshDir('lockrot-cli-home-');
        $project = self::freshDir('lockrot-cli-project-');
        try {
            mkdir($home.'/cache/lockrot', 0777, true);
            $fetchedAt = (new \DateTimeImmutable($today))->format(\DATE_ATOM);
            foreach ($this->githubDirs as $dir) {
                foreach (glob($dir.'/*.json') ?: [] as $answer) {
                    $envelope = JsonPath::decodeFile($answer);
                    file_put_contents($home.'/cache/lockrot/'.basename($answer), (string) json_encode(['fetched_at' => $fetchedAt] + $envelope, \JSON_UNESCAPED_SLASHES));
                }
            }
            file_put_contents($home.'/config.json', (string) json_encode([
                'config' => ['secure-http' => false, 'cache-dir' => $home.'/cache'],
                'repositories' => ['packagist.org' => false, 'fixture' => ['type' => 'composer', 'url' => $this->server->url()]],
            ], \JSON_UNESCAPED_SLASHES));
            file_put_contents($project.'/composer.json', $this->manifest($projectDir.'/composer.json'));
            copy($projectDir.'/composer.lock', $project.'/composer.lock');
            foreach ($files as $name => $source) {
                if (!is_dir(\dirname($project.'/'.$name))) {
                    mkdir(\dirname($project.'/'.$name), 0777, true);
                }
                copy($source, $project.'/'.$name);
            }
            $proxy = 'http://127.0.0.1:'.PhpBuiltinServer::closedPort();
            $env = ['COMPOSER_HOME' => $home, 'COMPOSER_CACHE_DIR' => $home.'/cache', 'LOCKROT_TODAY' => $today, 'LOCKROT_GITHUB_TOKEN' => 'recorded', 'NO_PROXY' => '127.0.0.1', 'no_proxy' => '127.0.0.1']
                + array_fill_keys(self::PROXY_ENV, $proxy)
                + array_fill_keys(self::TOKEN_ENV, false);
            $process = new Process(array_merge([\PHP_BINARY, $entry, '-vvv', '-d', $project], $arguments), null, $env);
            $process->setTimeout(600)->run();

            return $process;
        } finally {
            self::removeTree($home);
            self::removeTree($project);
        }
    }

    /** The project's composer.json, each mirrored repository URL replaced by the URL that serves it. */
    private function manifest(string $path): string
    {
        $contents = (string) file_get_contents($path);
        if ($this->mirrors === []) {
            return $contents;
        }
        $manifest = JsonPath::decodeFile($path);
        $repositories = JsonPath::arrayAt($manifest, ['repositories']);
        foreach ($repositories as $key => $repository) {
            $url = \is_array($repository) && \is_string($repository['url'] ?? null) ? rtrim($repository['url'], '/') : null;
            if ($url !== null && isset($this->mirrors[$url])) {
                $repository['url'] = $this->mirrors[$url];
                $repositories[$key] = $repository;
            }
        }
        $manifest['repositories'] = $repositories;

        return (string) json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * Every URL that the run asked outside the fixture server.
     *
     * @return list<string>
     */
    public static function outsideRequests(Process $process): array
    {
        preg_match_all('{Downloading (https?://\S+)}', $process->getErrorOutput(), $matches);

        return array_values(array_unique(array_filter($matches[1], static fn (string $url): bool => strpos($url, 'http://127.0.0.1:') !== 0)));
    }

    /**
     * What the run read of the recordings: the original URL of each answer that it asked the
     * fixture server for, and `github/<file>` for each activity-cache file that it read.
     *
     * @return list<string>
     */
    public function recordingsRead(Process $process): array
    {
        preg_match_all('{Downloading http://127\.0\.0\.1:\d+(/\S*)|Reading \S+/lockrot/([0-9a-f]{40}\.json) from cache}', $process->getErrorOutput(), $matches, \PREG_SET_ORDER);
        $read = [];
        foreach ($matches as $match) {
            $read[] = ($match[2] ?? '') !== '' ? 'github/'.$match[2] : $this->originalOf($match[1] ?? '');
        }

        return array_values(array_unique(array_filter($read)));
    }

    /** The URL that a fixture server path stands for, or null for the server's own packages.json. */
    private function originalOf(string $path): ?string
    {
        foreach ($this->mirrors as $url => $local) {
            $prefix = (string) parse_url($local, \PHP_URL_PATH);
            if (strpos($path, $prefix.'/') === 0) {
                $rest = substr($path, \strlen($prefix));

                return $rest === '/packages.json' ? $url.$rest : (string) parse_url($url, \PHP_URL_SCHEME).'://'.(string) parse_url($url, \PHP_URL_HOST).$rest;
            }
        }
        if ($path === '/api/security-advisories/') {
            return 'https://packagist.org/api/security-advisories/';
        }

        return strpos($path, '/p2/') === 0 ? 'https://repo.packagist.org'.$path : null;
    }

    private static function freshDir(string $prefix): string
    {
        $dir = sys_get_temp_dir().'/'.$prefix.bin2hex(random_bytes(8));
        mkdir($dir, 0777, true);

        return $dir;
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
