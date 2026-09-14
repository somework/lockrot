<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Packagist;

use Lockrot\Data\Http\HttpClientInterface;
use Lockrot\Data\Http\HttpResult;
use Lockrot\Data\Packagist\PackagistClient;
use PHPUnit\Framework\TestCase;

final class PackagistClientTest extends TestCase
{
    private const RAW = __DIR__.'/../../../fixtures/p2-raw/';

    /**
     * @param array<string, HttpResult> $map
     * @param list<list<string>> $requested
     */
    private function http(array $map, array &$requested): HttpClientInterface
    {
        return new class ($map, $requested) implements HttpClientInterface {
            /** @var array<string, HttpResult> */
            private array $map;
            /** @var list<list<string>> */
            public array $requested;

            /**
             * @param array<string, HttpResult> $map
             * @param list<list<string>> $requested
             */
            public function __construct(array $map, array &$requested)
            {
                $this->map = $map;
                $this->requested = &$requested;
            }

            /** @return array<string, HttpResult> */
            public function fetchAll(array $urls, array $headers = []): array
            {
                $this->requested[] = $urls;
                $out = [];
                foreach ($urls as $url) {
                    $out[$url] = $this->map[$url] ?? new HttpResult($url, 404, '', new \DateTimeImmutable('2026-09-14T00:00:00+00:00'));
                }
                return $out;
            }
        };
    }

    private function ok(string $url, string $file): HttpResult
    {
        return new HttpResult($url, 200, (string) file_get_contents(self::RAW.$file), new \DateTimeImmutable('2026-09-14T00:00:00+00:00'));
    }

    public function testUrls(): void
    {
        $r = [];
        $client = new PackagistClient($this->http([], $r));
        self::assertSame('https://repo.packagist.org/p2/phpzip/phpzip.json', $client->url('phpzip/phpzip'));
        self::assertSame('https://repo.packagist.org/p2/lox/xhprof~dev.json', $client->url('lox/xhprof', true));
    }

    public function testFetchesStableThenDevOnlyForTaglessPackages(): void
    {
        $requested = [];
        $client = new PackagistClient($this->http([
            'https://repo.packagist.org/p2/phpzip/phpzip.json' => $this->ok('https://repo.packagist.org/p2/phpzip/phpzip.json', 'phpzip_phpzip.json'),
            'https://repo.packagist.org/p2/lox/xhprof.json' => $this->ok('https://repo.packagist.org/p2/lox/xhprof.json', 'lox_xhprof.json'),
            'https://repo.packagist.org/p2/lox/xhprof~dev.json' => $this->ok('https://repo.packagist.org/p2/lox/xhprof~dev.json', 'lox_xhprof~dev.json'),
        ], $requested));
        $batch = $client->fetch(['phpzip/phpzip', 'lox/xhprof', 'nope/nope']);

        self::assertCount(2, $requested);
        self::assertSame(['https://repo.packagist.org/p2/lox/xhprof~dev.json'], $requested[1]);
        $lastStableReleaseAt = $batch->metadata()['phpzip/phpzip']->lastStableReleaseAt();
        self::assertNotNull($lastStableReleaseAt);
        self::assertSame('2015-11-16', $lastStableReleaseAt->format('Y-m-d'));
        self::assertFalse($batch->metadata()['lox/xhprof']->hasStableRelease());
        self::assertSame(1, $batch->metadata()['lox/xhprof']->releaseCount());
        self::assertSame(['nope/nope'], $batch->notFound());
        self::assertSame([], $batch->failed());
    }

    public function testTransportFailureReported(): void
    {
        $requested = [];
        $url = 'https://repo.packagist.org/p2/a/b.json';
        $client = new PackagistClient($this->http([$url => HttpResult::failure($url, 'timeout', new \DateTimeImmutable())], $requested));
        $batch = $client->fetch(['a/b']);
        self::assertSame(['a/b' => 'timeout'], $batch->failed());
        self::assertSame([], $batch->metadata());
    }

    public function testEmptyInputMakesNoRequests(): void
    {
        $requested = [];
        (new PackagistClient($this->http([], $requested)))->fetch([]);
        self::assertSame([], $requested);
    }

    public function testDuplicateNamesAreDeduped(): void
    {
        $requested = [];
        $client = new PackagistClient($this->http([], $requested));
        $batch = $client->fetch(['nope/nope', 'nope/nope']);
        self::assertSame(['nope/nope'], $batch->notFound());
    }

    public function testDevRoundFailureIsRecordedButStableMetadataStillBuilt(): void
    {
        $requested = [];
        $stableUrl = 'https://repo.packagist.org/p2/lox/xhprof.json';
        $devUrl = 'https://repo.packagist.org/p2/lox/xhprof~dev.json';
        $client = new PackagistClient($this->http([
            $stableUrl => $this->ok($stableUrl, 'lox_xhprof.json'),
            $devUrl => new HttpResult($devUrl, 503, null, new \DateTimeImmutable('2026-09-14T00:00:00+00:00')),
        ], $requested));
        $batch = $client->fetch(['lox/xhprof']);

        self::assertArrayHasKey('lox/xhprof', $batch->metadata());
        self::assertFalse($batch->metadata()['lox/xhprof']->hasStableRelease());
        self::assertSame(0, $batch->metadata()['lox/xhprof']->releaseCount());
        self::assertSame(['lox/xhprof' => '~dev: HTTP 503'], $batch->failed());
    }

    public function testDevRoundNotFoundIsNotReportedAsFailure(): void
    {
        $requested = [];
        $stableUrl = 'https://repo.packagist.org/p2/lox/xhprof.json';
        $client = new PackagistClient($this->http([
            $stableUrl => $this->ok($stableUrl, 'lox_xhprof.json'),
        ], $requested));
        $batch = $client->fetch(['lox/xhprof']);

        self::assertArrayHasKey('lox/xhprof', $batch->metadata());
        self::assertSame(0, $batch->metadata()['lox/xhprof']->releaseCount());
        self::assertSame([], $batch->failed());
    }
}
