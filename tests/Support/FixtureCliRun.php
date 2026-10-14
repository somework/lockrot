<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Lockrot\Data\Repository\MonorepoParents;
use Symfony\Component\Process\Process;

/**
 * Runs a lockrot command line (the PHAR of any release, or `bin/lockrot`) on a project against
 * recorded answers only. A FixtureRepositoryServer serves the p2 and advisory answers through a
 * Composer home whose only repository it is. The recorded GitHub answers sit in lockrot's activity
 * cache, which the pinned clock reads as fresh. Every other request goes to a closed proxy port
 * and fails, so the run never reaches the network.
 */
final class FixtureCliRun
{
    private const PROXY_ENV = ['HTTP_PROXY', 'HTTPS_PROXY', 'http_proxy', 'https_proxy'];
    private const TOKEN_ENV = ['GITHUB_TOKEN', 'GH_TOKEN', 'GITLAB_TOKEN', 'LOCKROT_GITLAB_TOKEN', 'COMPOSER_AUTH'];

    private FixtureRepositoryServer $server;
    /** @var list<string> */
    private array $githubDirs;

    /** @param list<string> $githubDirs */
    private function __construct(FixtureRepositoryServer $server, array $githubDirs)
    {
        $this->server = $server;
        $this->githubDirs = $githubDirs;
    }

    /**
     * Starts a fixture server for the names of $lockFiles.
     *
     * @param list<string> $lockFiles
     * @param list<string> $p2Dirs         recorded p2 answers: a name takes the first directory that answered it with 200
     * @param list<string> $githubDirs     recorded GitHub answers: a later directory wins for the same request
     * @param string       $advisoryAnswer the recorded advisory answer, `<dir>/<name>.json`
     * @param bool         $parents        also serve the monorepo parents that `bin/record-fixtures` records
     */
    public static function start(array $lockFiles, array $p2Dirs, array $githubDirs, string $advisoryAnswer, bool $parents): self
    {
        $more = array_map(static fn (string $dir): array => ['https://repo.packagist.org/p2/', $dir], \array_slice($p2Dirs, 1));
        $server = FixtureRepositoryServer::fromLockFiles($lockFiles, $p2Dirs[0], $parents ? MonorepoParents::load()->candidates() : [], $more);
        $server->withRecordedAdvisoryApi(basename($advisoryAnswer, '.json'), \dirname($advisoryAnswer));
        $server->start();

        return new self($server, $githubDirs);
    }

    /**
     * {@see start()} for one project and a directory in the layout of `bin/record-fixtures --into`.
     */
    public static function forRecording(string $projectDir, string $httpDir): self
    {
        return self::start([$projectDir.'/composer.lock'], [$httpDir.'/p2'], [$httpDir.'/github'], $httpDir.'/advisories/'.basename($projectDir).'.json', true);
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
            foreach ($this->githubDirs as $dir) {
                foreach (glob($dir.'/*.json') ?: [] as $answer) {
                    copy($answer, $home.'/cache/lockrot/'.basename($answer));
                }
            }
            file_put_contents($home.'/config.json', (string) json_encode([
                'config' => ['secure-http' => false, 'cache-dir' => $home.'/cache'],
                'repositories' => ['packagist.org' => false, 'fixture' => ['type' => 'composer', 'url' => $this->server->url()]],
            ], \JSON_UNESCAPED_SLASHES));
            copy($projectDir.'/composer.json', $project.'/composer.json');
            copy($projectDir.'/composer.lock', $project.'/composer.lock');
            foreach ($files as $name => $source) {
                copy($source, $project.'/'.$name);
            }
            $proxy = 'http://127.0.0.1:'.PhpBuiltinServer::closedPort();
            $env = ['COMPOSER_HOME' => $home, 'COMPOSER_CACHE_DIR' => $home.'/cache', 'LOCKROT_TODAY' => $today, 'LOCKROT_GITHUB_TOKEN' => 'recorded', 'NO_PROXY' => '127.0.0.1', 'no_proxy' => '127.0.0.1']
                + array_fill_keys(self::PROXY_ENV, $proxy)
                + array_fill_keys(self::TOKEN_ENV, false);
            $process = new Process(array_merge([\PHP_BINARY, $entry, '-d', $project], $arguments), null, $env);
            $process->setTimeout(600)->run();

            return $process;
        } finally {
            self::removeTree($home);
            self::removeTree($project);
        }
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
