<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Http;

use Lockrot\Data\Http\HttpResult;
use Lockrot\Data\Http\RecordedHttpClient;
use PHPUnit\Framework\TestCase;

final class RecordedHttpClientTest extends TestCase
{
    public function testReadsEnvelopeAndReportsMissing(): void
    {
        $dir = sys_get_temp_dir().'/lockrot-rec-'.uniqid();
        mkdir($dir);
        $result = new HttpResult('https://x/a.json', 200, '{"ok":true}', new \DateTimeImmutable('2026-09-14T00:00:00+00:00'));
        file_put_contents(RecordedHttpClient::pathFor($dir, 'https://x/a.json'), json_encode($result->toEnvelope()));
        $client = new RecordedHttpClient($dir);
        $results = $client->fetchAll(['https://x/a.json', 'https://x/missing.json']);
        self::assertSame(['ok' => true], $results['https://x/a.json']->json());
        self::assertTrue($results['https://x/missing.json']->isFailure());
        self::assertStringContainsString('not recorded', (string) $results['https://x/missing.json']->error());
        array_map('unlink', glob($dir.'/*') ?: []);
        rmdir($dir);
    }

    public function testEmptyObjectEnvelopeIsTreatedAsNotRecorded(): void
    {
        $dir = sys_get_temp_dir().'/lockrot-rec-'.uniqid();
        mkdir($dir);
        file_put_contents(RecordedHttpClient::pathFor($dir, 'https://x/empty.json'), '{}');
        $client = new RecordedHttpClient($dir);
        $results = $client->fetchAll(['https://x/empty.json']);
        self::assertTrue($results['https://x/empty.json']->isFailure());
        self::assertStringContainsString('not recorded', (string) $results['https://x/empty.json']->error());
        array_map('unlink', glob($dir.'/*') ?: []);
        rmdir($dir);
    }

    public function testAcceptsAndIgnoresHeaders(): void
    {
        $dir = sys_get_temp_dir().'/lockrot-rec-'.uniqid();
        mkdir($dir);
        $result = new HttpResult('https://x/a.json', 200, '{"ok":true}', new \DateTimeImmutable('2026-09-14T00:00:00+00:00'));
        file_put_contents(RecordedHttpClient::pathFor($dir, 'https://x/a.json'), json_encode($result->toEnvelope()));
        $client = new RecordedHttpClient($dir);
        $results = $client->fetchAll(['https://x/a.json'], ['Authorization: token abc']);
        self::assertSame(['ok' => true], $results['https://x/a.json']->json());
        array_map('unlink', glob($dir.'/*') ?: []);
        rmdir($dir);
    }
}
