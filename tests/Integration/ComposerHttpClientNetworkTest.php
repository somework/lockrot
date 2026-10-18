<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Composer\Factory;
use Composer\IO\NullIO;
use Lockrot\Clock;
use Lockrot\Composer\ComposerHttpClient;
use Lockrot\Data\Forge\GitHubApi;
use Lockrot\Tests\Support\HermeticComposerConfig;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** @group network */
#[Group('network')]
final class ComposerHttpClientNetworkTest extends TestCase
{
    private const OK_URL = 'https://repo.packagist.org/p2/psr/log.json';
    private const MISSING_URL = 'https://repo.packagist.org/p2/lockrot-test/does-not-exist.json';
    private const GITHUB_URL = 'https://api.github.com/repos/somework/lockrot';

    private string $home = '';

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir().'/lockrot-network-home-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->home)) {
            return;
        }
        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->home, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->home);
    }

    public function testFetchesRealAndMissingUrlsOverHttp(): void
    {
        $io = new NullIO();
        $config = HermeticComposerConfig::create($this->home, $io);
        $client = new ComposerHttpClient(Factory::createHttpDownloader($io, $config), $io, $config, Clock::fixed('2026-09-14T00:00:00+00:00'));

        $results = $client->fetchAll([self::OK_URL, self::MISSING_URL]);

        $ok = $results[self::OK_URL];
        self::assertSame(200, $ok->status());
        self::assertIsArray($ok->json());

        $missing = $results[self::MISSING_URL];
        self::assertSame(404, $missing->status());
    }

    /**
     * Composer credentials for github.com plus lockrot's own token header must not reach GitHub as
     * two Authorization headers: GitHub rejects them with 401 whatever the tokens are. The test
     * needs a real token, so it runs where the e2e job runs.
     */
    public function testComposerGithubCredentialsDoNotDoubleTheAuthorizationHeader(): void
    {
        $token = getenv('GITHUB_TOKEN');
        if (!\is_string($token) || $token === '') {
            self::markTestSkipped('GITHUB_TOKEN is not set');
        }
        $io = new NullIO();
        $io->setAuthentication('github.com', $token, 'x-oauth-basic');
        $config = HermeticComposerConfig::create($this->home, $io);
        $client = new ComposerHttpClient(Factory::createHttpDownloader($io, $config), $io, $config, Clock::fixed('2026-09-14T00:00:00+00:00'));

        $result = $client->fetchAll([self::GITHUB_URL], GitHubApi::headersFor($token))[self::GITHUB_URL];

        self::assertSame(200, $result->status(), (string) $result->error());
    }
}
