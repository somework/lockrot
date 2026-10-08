<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Security;

use Lockrot\Security\Severity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SeverityTest extends TestCase
{
    /**
     * Each Composer value, its bucket, points, gate rank and SARIF numbers.
     *
     * @dataProvider buckets
     */
    #[DataProvider('buckets')]
    public function testEachComposerValueFallsInItsBucket(?string $composer, string $bucket, int $points, int $gateRank, ?string $securitySeverity, int $sarifRank): void
    {
        $severity = Severity::fromComposer($composer);

        self::assertSame($bucket, $severity->bucket());
        self::assertSame($points, $severity->points());
        self::assertSame($gateRank, $severity->gateRank());
        self::assertSame($securitySeverity, $severity->sarifSecuritySeverity());
        self::assertSame($sarifRank, $severity->sarifRank());
        self::assertSame($composer, $severity->published());
    }

    /** @return iterable<string, array{?string, string, int, int, ?string, int}> */
    public static function buckets(): iterable
    {
        yield 'critical' => ['critical', Severity::CRITICAL, 32, 4, '9.5', 95];
        yield 'high' => ['high', Severity::HIGH, 16, 3, '8.0', 80];
        yield 'medium' => ['medium', Severity::MEDIUM, 8, 2, '5.5', 55];
        yield 'moderate is medium' => ['moderate', Severity::MEDIUM, 8, 2, '5.5', 55];
        yield 'low' => ['low', Severity::LOW, 2, 1, '2.0', 20];
        yield 'no severity' => [null, Severity::UNRATED, 8, 2, null, 55];
        yield 'an empty severity' => ['', Severity::UNRATED, 8, 2, null, 55];
        yield 'a word Composer does not define' => ['important', Severity::UNRATED, 8, 2, null, 55];
        yield 'any case' => ['HIGH', Severity::HIGH, 16, 3, '8.0', 80];
        yield 'surrounding spaces' => [' Critical ', Severity::CRITICAL, 32, 4, '9.5', 95];
    }

    public function testTheDisplayOrderPutsUnratedBetweenMediumAndLow(): void
    {
        self::assertSame([Severity::CRITICAL, Severity::HIGH, Severity::MEDIUM, Severity::UNRATED, Severity::LOW], Severity::DISPLAY_ORDER);

        $positions = [];
        foreach (Severity::DISPLAY_ORDER as $bucket) {
            $positions[] = Severity::fromComposer($bucket === Severity::UNRATED ? null : $bucket)->displayPosition();
        }
        self::assertSame([0, 1, 2, 3, 4], $positions);
    }

    /** The display order breaks ties. The gate rank compares thresholds, and there unrated equals medium. */
    public function testUnratedAndMediumShareAGateRankButNotADisplayPosition(): void
    {
        $medium = Severity::fromComposer('medium');
        $unrated = Severity::fromComposer(null);

        self::assertSame($medium->gateRank(), $unrated->gateRank());
        self::assertSame($medium->points(), $unrated->points());
        self::assertLessThan($unrated->displayPosition(), $medium->displayPosition());
    }

    public function testWorstOfIsTheFirstBucketInTheDisplayOrder(): void
    {
        self::assertSame(Severity::UNRATED, Severity::worstOf([Severity::LOW, Severity::UNRATED, Severity::LOW]));
        self::assertSame(Severity::CRITICAL, Severity::worstOf([Severity::MEDIUM, Severity::CRITICAL, Severity::HIGH]));
        self::assertNull(Severity::worstOf([]));
    }
}
