<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Http;

use Lockrot\Data\Http\HttpResult;
use PHPUnit\Framework\TestCase;

final class HttpResultTest extends TestCase
{
    public function testStatusClassification(): void
    {
        $at = new \DateTimeImmutable('2026-09-14T00:00:00+00:00');
        self::assertTrue((new HttpResult('u', 200, '{}', $at))->isOk());
        self::assertTrue((new HttpResult('u', 404, '', $at))->isNotFound());
        self::assertFalse((new HttpResult('u', 404, '', $at))->isFailure());
        self::assertTrue((new HttpResult('u', 403, '', $at))->isFailure());
        self::assertTrue((new HttpResult('u', 503, '', $at))->isFailure());
        self::assertTrue(HttpResult::failure('u', 'timeout', $at)->isFailure());
        self::assertSame('timeout', HttpResult::failure('u', 'timeout', $at)->error());
    }

    public function testJsonAndEnvelopeRoundTrip(): void
    {
        $at = new \DateTimeImmutable('2026-09-14T12:34:56+00:00');
        $result = new HttpResult('https://x/y.json', 200, '{"a":1}', $at);
        self::assertSame(['a' => 1], $result->json());
        $copy = HttpResult::fromEnvelope('https://x/y.json', $result->toEnvelope());
        self::assertSame(200, $copy->status());
        self::assertSame('{"a":1}', $copy->body());
        self::assertSame('2026-09-14T12:34:56+00:00', $copy->fetchedAt()->format(\DATE_ATOM));
        self::assertNull((new HttpResult('u', 200, 'not json', $at))->json());
    }

    public function testFromEnvelopeToleratesMalformedFetchedAt(): void
    {
        $result = HttpResult::fromEnvelope('u', ['status' => 200, 'fetched_at' => 'garbage', 'body' => null, 'error' => null]);
        self::assertSame(0, $result->fetchedAt()->getTimestamp());
    }

    public function testFromEnvelopeJson(): void
    {
        self::assertNull(HttpResult::fromEnvelopeJson('u', null));
        self::assertNull(HttpResult::fromEnvelopeJson('u', '{broken'));
        self::assertNull(HttpResult::fromEnvelopeJson('u', '{}'));

        $at = new \DateTimeImmutable('2026-09-14T00:00:00+00:00');
        $envelope = (new HttpResult('u', 200, '{"a":1}', $at))->toEnvelope();
        $result = HttpResult::fromEnvelopeJson('u', (string) json_encode($envelope));
        self::assertNotNull($result);
        self::assertSame(200, $result->status());
        self::assertSame('{"a":1}', $result->body());
    }

    /** The cache flag lives on a copy and never in the envelope, so a cached answer re-stored reads as fresh again. */
    public function testAsCachedMarksACopyAndLeavesTheEnvelopeAlone(): void
    {
        $result = new HttpResult('https://a', 200, '{}', new \DateTimeImmutable('2026-09-14T00:00:00+00:00'));
        $cached = $result->asCached();

        self::assertFalse($result->fromCache());
        self::assertTrue($cached->fromCache());
        self::assertNotSame($result, $cached);
        self::assertSame($result->toEnvelope(), $cached->toEnvelope());
        self::assertFalse(HttpResult::fromEnvelope('https://a', $cached->toEnvelope())->fromCache());
        self::assertSame('2026-09-14T00:00:00+00:00', $cached->fetchedAt()->format(\DATE_ATOM));
    }
}
