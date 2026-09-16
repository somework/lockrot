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
        self::assertTrue((new HttpResult('u', 299, '{}', $at))->isOk());
        self::assertFalse((new HttpResult('u', 300, '', $at))->isOk());
        self::assertTrue((new HttpResult('u', 500, '', $at))->isFailure());
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
        self::assertSame(['status' => 200, 'fetched_at' => '2026-09-14T12:34:56+00:00', 'body' => '{"a":1}', 'error' => null], $result->toEnvelope());
        self::assertSame('timeout', HttpResult::failure('u', 'timeout', $at)->toEnvelope()['error']);
        $copy = HttpResult::fromEnvelope('https://x/y.json', $result->toEnvelope());
        self::assertNotNull($copy);
        self::assertSame(200, $copy->status());
        self::assertSame('{"a":1}', $copy->body());
        self::assertSame('2026-09-14T12:34:56+00:00', $copy->fetchedAt()->format(\DATE_ATOM));
        self::assertNull((new HttpResult('u', 200, 'not json', $at))->json());
    }

    /** An envelope without a readable fetch time has no age, so it is not an answer. */
    public function testAnEnvelopeWithoutAReadableFetchedAtIsNotAResult(): void
    {
        self::assertNull(HttpResult::fromEnvelope('u', ['status' => 200, 'fetched_at' => 'garbage', 'body' => null, 'error' => null]));
        self::assertNull(HttpResult::fromEnvelope('u', ['status' => 200, 'body' => '{}']));
        self::assertNull(HttpResult::fromEnvelope('u', ['status' => 200, 'fetched_at' => 0, 'body' => '{}']));
        self::assertNull(HttpResult::fromEnvelope('u', ['status' => 200, 'fetched_at' => '', 'body' => '{}']), 'an empty string is not "now"');
        self::assertNull(HttpResult::fromEnvelope('u', ['status' => 200, 'fetched_at' => '2026-09-14', 'body' => '{}']), 'only the format toEnvelope() writes');
        self::assertNull(HttpResult::fromEnvelope('u', ['status' => 200, 'fetched_at' => '2026-02-30T00:00:00+00:00', 'body' => '{}']), 'a date that parses but does not exist');
        self::assertNull(HttpResult::fromEnvelopeJson('u', '{"fetched_at":"2026-09-14T12:00:00+00:00","body":"{}"}'), 'no status: not an envelope');
        self::assertNull(HttpResult::fromEnvelopeJson('u', '{"status":200,"fetched_at":"garbage","body":"{}"}'));
    }

    /** Everything but the fetch time is tolerated: a missing or non-integer status reads as 0, the transport-failure status. */
    public function testFromEnvelopeDefaultsWhatIsMissingOrMistyped(): void
    {
        $result = HttpResult::fromEnvelope('u', ['fetched_at' => '2026-09-14T12:00:00+00:00']);
        self::assertNotNull($result);
        self::assertSame(0, $result->status());
        self::assertNull($result->body());
        self::assertNull($result->error());
        $result = HttpResult::fromEnvelope('u', ['status' => '200', 'fetched_at' => '2026-09-14T12:00:00+00:00', 'body' => 1, 'error' => []]);
        self::assertNotNull($result);
        self::assertSame(0, $result->status());
        self::assertNull($result->body());
        self::assertNull($result->error());
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
        $reread = HttpResult::fromEnvelope('https://a', $cached->toEnvelope());
        self::assertNotNull($reread);
        self::assertFalse($reread->fromCache());
        self::assertSame('2026-09-14T00:00:00+00:00', $cached->fetchedAt()->format(\DATE_ATOM));
    }
}
