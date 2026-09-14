<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Composer\Factory;
use Composer\IO\NullIO;
use Lockrot\Clock;
use Lockrot\Composer\ComposerHttpClient;
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

    public function testFetchesRealAndMissingUrlsOverHttp(): void
    {
        $io = new NullIO();
        $downloader = Factory::createHttpDownloader($io, Factory::createConfig($io));
        $client = new ComposerHttpClient($downloader, new Clock());

        $results = $client->fetchAll([self::OK_URL, self::MISSING_URL]);

        $ok = $results[self::OK_URL];
        self::assertSame(200, $ok->status());
        self::assertIsArray($ok->json());

        $missing = $results[self::MISSING_URL];
        self::assertSame(404, $missing->status());
    }
}
