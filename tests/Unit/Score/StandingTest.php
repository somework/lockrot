<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Score;

use Lockrot\Baseline\BaselineEntry;
use Lockrot\Baseline\RecordedAdvisory;
use Lockrot\Score\CountedAdvisory;
use Lockrot\Score\Standing;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\TestCase;

/** SPEC-0.14.md §6.4: a finding's facts against its baseline entry, as `finding.baseline` writes them. */
final class StandingTest extends TestCase
{
    private const DAY = '2026-01-01';

    public function testEachFactIsComparedAndTheStandingIsWrittenAsTheFieldReadsIt(): void
    {
        $entry = new BaselineEntry('acme/pkg', '1.0.0', self::DAY, ['left-behind' => self::DAY, 'old-promise' => self::DAY], [
            new RecordedAdvisory('A', self::DAY, 'high', 'update'),
            new RecordedAdvisory('B', self::DAY, 'medium', 'update'),
            new RecordedAdvisory('C', self::DAY, 'low', 'update'),
        ]);
        $now = [new CountedAdvisory('C', 'low', 'none'), new CountedAdvisory('B', 'high', 'update'), new CountedAdvisory('A', 'high', 'update'), new CountedAdvisory('D', 'critical', 'update')];

        $standing = Standing::against($entry, '8.4', 1, ['silent', 'old-promise', 'stale'], $now, true);

        self::assertSame([
            'status' => 'worsened',
            'recorded' => ['lead' => 'left-behind', 'flags' => ['left-behind' => ['known_since' => self::DAY], 'old-promise' => ['known_since' => self::DAY]], 'advisory_count' => 3, 'first_seen' => self::DAY, 'target_php' => '8.4', 'fix_model' => 1],
            'advisories_now' => ['known' => 1, 'new' => 1, 're_rated' => 1, 'fix_lost' => 1],
            'worsened_by' => [
                ['kind' => 'flag', 'id' => 'silent', 'why' => 'new', 'recorded' => null, 'now' => null],
                ['kind' => 'advisory', 'id' => 'D', 'why' => 'new', 'recorded' => null, 'now' => ['severity' => 'critical', 'fix_kind' => 'update']],
                ['kind' => 'advisory', 'id' => 'B', 'why' => 're_rated', 'recorded' => ['severity' => 'medium', 'fix_kind' => 'update'], 'now' => ['severity' => 'high', 'fix_kind' => 'update']],
                ['kind' => 'advisory', 'id' => 'C', 'why' => 'fix_lost', 'recorded' => ['severity' => 'low', 'fix_kind' => 'update'], 'now' => ['severity' => 'low', 'fix_kind' => 'none']],
            ],
        ], JsonPath::decoded($standing));
        self::assertSame(['state' => 'covered', 'since' => self::DAY, 'covered_by' => 'left-behind'], JsonPath::decoded($standing->flagState('stale')));
        self::assertSame(['state' => 'known', 'since' => self::DAY, 'covered_by' => null], JsonPath::decoded($standing->flagState('old-promise')));
        self::assertSame(['state' => 'new', 'since' => null, 'covered_by' => null], JsonPath::decoded($standing->advisoryState('D')));
        self::assertSame(['silent'], $standing->uncoveredFlags());
        self::assertSame(['D', 'B', 'C'], array_map(static fn (CountedAdvisory $a): string => $a->id(), $standing->unknownAdvisories()));
    }

    /** A baseline written under another fix model or target compares no fix: a lost fix stays known. */
    public function testWithoutComparingFixesALostFixIsKnown(): void
    {
        $entry = new BaselineEntry('acme/pkg', '1.0.0', self::DAY, ['stale' => self::DAY], [new RecordedAdvisory('C', self::DAY, 'low', 'update')]);

        $standing = Standing::against($entry, '8.3', 1, ['stale'], [new CountedAdvisory('C', 'low', 'none')], false);

        self::assertSame('known', $standing->status());
        self::assertSame(['known' => 1, 'new' => 0, 're_rated' => 0, 'fix_lost' => 0], JsonPath::arrayAt(JsonPath::decoded($standing), ['advisories_now']));
    }

    public function testAnEntryWithAdvisoriesOnlyWritesNoFlags(): void
    {
        $entry = new BaselineEntry('acme/pkg', '1.0.0', self::DAY, [], [new RecordedAdvisory('A', self::DAY, 'high', 'update')]);

        $standing = Standing::against($entry, '8.4', 1, [], [new CountedAdvisory('A', 'high', 'update')], true);

        $recorded = JsonPath::arrayAt(JsonPath::decoded($standing), ['recorded']);
        self::assertArrayHasKey('flags', $recorded);
        self::assertNull($recorded['flags']);
    }

    public function testAPackageNewToTheFileHasNoRecordAndEveryFactIsNew(): void
    {
        $standing = Standing::newPackage(['stale'], [new CountedAdvisory('A', 'high', 'update')]);
        $written = JsonPath::decoded($standing);

        self::assertSame(['new', null], [$written['status'], $written['recorded']]);
        self::assertSame(['known' => 0, 'new' => 1, 're_rated' => 0, 'fix_lost' => 0], $written['advisories_now']);
        self::assertSame([['kind' => 'flag', 'id' => 'stale'], ['kind' => 'advisory', 'id' => 'A']], array_map(static fn ($fact): array => \array_slice(JsonPath::decoded($fact), 0, 2, true), $standing->worsenedBy()));
        self::assertSame(['state' => 'new', 'since' => null, 'covered_by' => null], JsonPath::decoded($standing->flagState('stale')));
        self::assertSame(['stale'], $standing->uncoveredFlags());
    }

    /** The advisories come in the score's order: points, then the severity order of the buckets, then the id. */
    public function testAdvisoriesOfEqualPointsFollowTheSeverityOrderOfTheirBuckets(): void
    {
        $standing = Standing::newPackage([], [new CountedAdvisory('A', 'unrated', 'update'), new CountedAdvisory('B', 'moderate', 'update')]);

        self::assertSame([['id' => 'B', 'severity' => 'medium'], ['id' => 'A', 'severity' => 'unrated']], array_map(static fn ($fact): array => ['id' => JsonPath::stringAt(JsonPath::decoded($fact), ['id']), 'severity' => JsonPath::stringAt(JsonPath::decoded($fact), ['now', 'severity'])], $standing->worsenedBy()));
    }
}
