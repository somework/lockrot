<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Composer\Factory;
use Composer\IO\NullIO;
use Lockrot\Clock;
use Lockrot\Composer\ComposerHttpClient;
use Lockrot\Data\GitHub\GitHubClient;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Exercises ComposerHttpClient against the real Packagist p2 endpoint. Requires
 * network access, so it is tagged `network` and excluded from the default suite
 * (see phpunit.xml.dist / phpunit9.xml.dist <groups><exclude>).
 *
 * @group network
 */
#[Group('network')]
final class ComposerHttpClientNetworkTest extends TestCase
{
    private const OK_URL = 'https://repo.packagist.org/p2/psr/log.json';
    private const MISSING_URL = 'https://repo.packagist.org/p2/lockrot-test/does-not-exist.json';
    private const GITHUB_URL = 'https://api.github.com/repos/somework/lockrot';

    public function testFetchesRealAndMissingUrlsOverHttp(): void
    {
        $io = new NullIO();
        $downloader = Factory::createHttpDownloader($io, Factory::createConfig($io));
        $client = new ComposerHttpClient($downloader, $io, new Clock());

        $results = $client->fetchAll([self::OK_URL, self::MISSING_URL]);

        $ok = $results[self::OK_URL];
        self::assertSame(200, $ok->status());
        self::assertIsArray($ok->json());

        $missing = $results[self::MISSING_URL];
        self::assertSame(404, $missing->status());
    }

    /**
     * Composer credentials for github.com plus lockrot's own token header used to reach GitHub as
     * two Authorization headers, which GitHub rejects with 401 whatever the tokens are. Needs a
     * real token, so it runs where the e2e job runs.
     */
    public function testComposerGithubCredentialsDoNotDoubleTheAuthorizationHeader(): void
    {
        $token = getenv('GITHUB_TOKEN');
        if (!\is_string($token) || $token === '') {
            self::markTestSkipped('GITHUB_TOKEN is not set');
        }
        $io = new NullIO();
        $io->setAuthentication('github.com', $token, 'x-oauth-basic');
        $client = new ComposerHttpClient(Factory::createHttpDownloader($io, Factory::createConfig($io)), $io, new Clock());

        $result = $client->fetchAll([self::GITHUB_URL], GitHubClient::headersFor($token))[self::GITHUB_URL];

        self::assertSame(200, $result->status(), (string) $result->error());
    }
}
