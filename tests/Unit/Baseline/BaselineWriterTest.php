<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Baseline;

use Lockrot\Allowlist\AllowlistEntry;
use Lockrot\Analyzer\Report;
use Lockrot\Baseline\Baseline;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Baseline\BaselineEntry;
use Lockrot\Baseline\RecordedAdvisory;
use Lockrot\Score\CountedAdvisory;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\ScoreSweep;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\FindingDetails;
use Lockrot\Verdict\FlagSet;
use PHPUnit\Framework\TestCase;

/** SPEC §6.4, "Writing": what `--generate-baseline` stores and keeps. */
final class BaselineWriterTest extends TestCase
{
    public function testTwoRegenerationsOfAnUnchangedLockDifferOnlyInTheirTimestamp(): void
    {
        $first = Baseline::fromReport(self::report('2026-01-01', [self::finding(['old-promise', 'stale'], [['A1', 'high', 'update']])]));
        $second = Baseline::fromReport(self::report('2027-01-01', [self::finding(['old-promise', 'stale'], [['A1', 'high', 'update']])]), $first);

        self::assertSame(
            array_diff_key(JsonPath::decoded($first->toArray()), ['generated_at' => true]),
            array_diff_key(JsonPath::decoded($second->toArray()), ['generated_at' => true])
        );
    }

    public function testARegenerationKeepsTheDatesAndRefreshesTheSeverity(): void
    {
        $before = Baseline::fromReport(self::report('2026-06-01', [self::finding(['stale'], [['A1', 'low', 'update']])]));
        $now = self::report('2026-10-01', [self::finding(['stale'], [['A1', 'critical', 'update']])]);
        $after = Baseline::fromReport($now, $before);
        $entry = JsonPath::arrayAt(JsonPath::decoded($after->toArray()), ['findings', 'acme/pkg']);

        self::assertSame('2026-06-01', JsonPath::stringAt($entry, ['first_seen']));
        self::assertSame(['known_since' => '2026-06-01', 'severity' => 'critical', 'fix_kind' => 'update'], JsonPath::arrayAt($entry, ['advisories', 'A1']));
        self::assertSame('worsened', BaselineComparison::compare($before, $now, 'b.json', ['acme/pkg'])->statusOf('acme/pkg'), 'the run before regenerating reads the re-rating');
        self::assertSame('known', BaselineComparison::compare($after, $now, 'b.json', ['acme/pkg'])->statusOf('acme/pkg'), 'the regenerated file accepts it');
    }

    public function testOnlyCountedFlagsAreStoredAndNoHeadlineAgeOrDepth(): void
    {
        $flags = FlagSet::fromSignals(array_merge(ScoreSweep::signalsOf('left-behind'), ScoreSweep::signalsOf('old-promise')), new AllowlistEntry('acme/pkg', null, 'accepted', null, AllowlistEntry::BY_PROJECT, ['left-behind']), []);
        $finding = (new FindingBuilder())->withPackage('acme/pkg')->withChain(['acme/pkg'])->withFlags($flags)->build();
        $entry = JsonPath::arrayAt(JsonPath::decoded(Baseline::fromReport(self::report('2026-01-01', [$finding]))->toArray()), ['findings', 'acme/pkg']);

        self::assertSame(['old-promise'], array_keys(JsonPath::arrayAt($entry, ['flags'])), 'an accepted flag is never stored');
        self::assertSame(['version', 'first_seen', 'flags'], array_keys($entry), 'no empty advisories map, no headline, age or depth');
    }

    public function testNumericAdvisoryIdsStayKeysThroughTheFile(): void
    {
        $baseline = Baseline::fromReport(self::report('2026-01-01', [self::finding([], [['123', 'high', 'update'], ['0', 'low', 'none']])]));
        $json = (string) json_encode(['$schema' => 'https://lockrot.dev/schema/baseline-2.json'] + $baseline->toArray());

        self::assertStringContainsString('"advisories":{"0":', $json);
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);
        /** @var array<string, mixed> $decoded */
        $read = Baseline::fromArray($decoded);
        $entry = $read->entryFor('acme/pkg');
        self::assertNotNull($entry);
        self::assertSame(['0', '123'], array_map(static fn (\Lockrot\Baseline\RecordedAdvisory $a): string => $a->id(), $entry->advisories()));
    }

    /** An advisory that an incomplete lookup did not see stays, with its date. A complete lookup drops it. */
    public function testOnlyAnIncompleteLookupCarriesARecordedAdvisoryForward(): void
    {
        $before = Baseline::fromReport(self::report('2026-06-01', [self::finding(['stale'], [['A1', 'high', 'update']])]));

        $carried = Baseline::fromReport(self::report('2026-10-01', [self::incomplete(self::finding(['stale'], []))]), $before);
        $dropped = Baseline::fromReport(self::report('2026-10-01', [self::finding(['stale'], [])]), $before);
        $counted = Baseline::fromReport(self::report('2026-10-01', [self::incomplete(self::finding(['stale'], [['A1', 'critical', 'none']]))]), $before);

        self::assertSame([['A1', '2026-06-01']], self::advisories($carried->entryFor('acme/pkg')));
        self::assertSame(['acme/pkg'], $carried->carriedForward());
        self::assertSame(['advisories' => ['acme/pkg']], $carried->carriedHolding(), 'the run counts the flags');
        self::assertSame([], self::advisories($dropped->entryFor('acme/pkg')));
        self::assertSame([], $dropped->carriedForward());
        self::assertSame([['A1', '2026-06-01']], self::advisories($counted->entryFor('acme/pkg')));
        self::assertSame([], $counted->carriedForward(), 'an advisory counted again is no carry');
        self::assertSame(['known_since' => '2026-06-01', 'severity' => 'critical', 'fix_kind' => 'none'], JsonPath::arrayAt(JsonPath::decoded($counted->toArray()), ['findings', 'acme/pkg', 'advisories', 'A1']), 'the run refreshes what it counts');
    }

    /** A package that no longer grades keeps its entry only while its lookup is incomplete. */
    public function testAnUngradedPackageKeepsItsEntryOnlyOnAnIncompleteLookup(): void
    {
        $before = Baseline::fromReport(self::report('2026-06-01', [self::finding(['stale'], [['A1', 'high', 'update']])]));

        $kept = Baseline::fromReport(self::report('2026-10-01', [self::incomplete(self::finding([], []))]), $before);
        $gone = Baseline::fromReport(self::report('2026-10-01', [self::finding([], [])]), $before);

        self::assertSame([['A1', '2026-06-01']], self::advisories($kept->entryFor('acme/pkg')));
        self::assertSame(['acme/pkg'], $kept->carriedForward());
        self::assertSame(['flags and advisories' => ['acme/pkg']], $kept->carriedHolding());
        self::assertNull($gone->entryFor('acme/pkg'));
        self::assertSame([], $gone->carriedForward());
    }

    /** Ruling R5-42: an entry that holds only a flag is carried as flags, not as advisories. */
    public function testACarriedEntryIsNamedByWhatItHolds(): void
    {
        $before = Baseline::fromReport(self::report('2026-06-01', [self::finding(['abandoned'], [])]));

        $kept = Baseline::fromReport(self::report('2026-10-01', [self::incomplete(self::finding([], []))]), $before);

        self::assertSame(['flags' => ['acme/pkg']], $kept->carriedHolding());
    }

    public function testCarriedEntriesThatHoldDifferentFactsAreNamedApart(): void
    {
        $before = Baseline::fromReport(self::report('2026-06-01', [self::finding(['abandoned'], []), self::finding([], [['A1', 'high', 'update']], 'acme/other')]));

        $kept = Baseline::fromReport(self::report('2026-10-01', [self::incomplete(self::finding([], [])), self::incomplete(self::finding([], [], 'acme/other'))]), $before);

        self::assertSame(['advisories' => ['acme/other'], 'flags' => ['acme/pkg']], $kept->carriedHolding());
    }

    public function testAFirstEntryOnAnIncompleteLookupHoldsWhatItCountsAsAList(): void
    {
        $baseline = Baseline::fromReport(self::report('2026-10-01', [self::incomplete(self::finding(['stale'], [['B2', 'high', 'update'], ['A1', 'low', 'update']]))]));
        $entry = $baseline->entryFor('acme/pkg');

        self::assertNotNull($entry);
        self::assertSame([0, 1], array_keys($entry->advisories()));
        self::assertSame([], $baseline->carriedForward());
    }

    /** @return list<array{string, string}> each advisory id with its date */
    private static function advisories(?BaselineEntry $entry): array
    {
        self::assertNotNull($entry);

        return array_map(static fn (RecordedAdvisory $a): array => [$a->id(), $a->knownSince()], $entry->advisories());
    }

    /** The finding with an advisory lookup that ran offline. */
    private static function incomplete(Finding $finding): Finding
    {
        $details = FindingBuilder::detailsOf($finding, true);

        return $finding->withDetails(new FindingDetails('read', null, null, $details->skipped(), ['requires' => null, 'target_runs' => null, 'project_allows' => null], null, 'not_run', 'offline', [], null, FindingBuilder::activityOf($finding), FindingBuilder::unmeasuredAge(), null, null));
    }

    /**
     * @param list<string>                        $flags
     * @param list<array{string, string, string}> $advisories
     */
    private static function finding(array $flags, array $advisories, string $package = 'acme/pkg'): Finding
    {
        $signals = [];
        foreach ($flags as $flag) {
            $signals = array_merge($signals, ScoreSweep::signalsOf($flag));
        }
        $counted = array_map(static fn (array $a): CountedAdvisory => new CountedAdvisory($a[0], $a[1], $a[2]), $advisories);

        return (new FindingBuilder())->withPackage($package)->withChain([$package])->withFlags(FlagSet::fromSignals($signals, null, $counted))->build();
    }

    /** @param list<Finding> $findings */
    private static function report(string $day, array $findings): Report
    {
        return new Report($findings, [], new \DateTimeImmutable($day.'T00:00:00+00:00'), \count($findings), 0);
    }
}
