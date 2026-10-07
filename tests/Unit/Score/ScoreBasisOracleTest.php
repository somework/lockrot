<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Score;

use Lockrot\Allowlist\AllowlistEntry;
use Lockrot\Score\ScoreBasis;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\ScoreSweep;
use Lockrot\Verdict\FlagSet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Hand-written oracle rows of the structured basis, in integer half points: each weight × reach × dev,
 * each severity × fix kind, the band edges, and the parts of the basis that the sweep never varies
 * (the part words, the lower bounds).
 *
 * @phpstan-import-type Graded from ScoreBasis
 * @phpstan-import-type Zero from ScoreBasis
 */
final class ScoreBasisOracleTest extends TestCase
{
    /**
     * A flag alone: its points, halved for reach on maintenance, halved for dev on the whole.
     *
     * @dataProvider weights
     */
    #[DataProvider('weights')]
    public function testAFlagAloneScoresItsWeightHalvedByReachAndDev(string $flag, string $reach, bool $dev, int $exactHalves, ?string $grade): void
    {
        $score = self::score([$flag], [], $reach, $dev);

        self::assertSame($exactHalves, ScoreSweep::halves($score['exact']));
        self::assertSame(intdiv($exactHalves, 2), $score['total']);
        self::assertSame($grade, $score['band'] === null ? null : self::grade($score['total']));
        self::assertCount(1, $score['terms']);
        self::assertSame(['flag' => $flag, 'role' => 'lead', 'divisor' => 1, 'contribution' => $score['exact']], array_intersect_key($score['terms'][0], ['flag' => 0, 'role' => 0, 'divisor' => 0, 'contribution' => 0]));
        self::assertSame('maintenance', $score['decided_by']);
    }

    /** @return iterable<string, array{string, string, bool, int, string}> */
    public static function weights(): iterable
    {
        $table = [
            'abandoned' => [64, 32, 32, 32, 16, 16],
            'silent' => [64, 32, 32, 32, 16, 16],
            'pinned' => [32, 16, 16, 16, 8, 8],
            'left-behind' => [32, 16, 16, 16, 8, 8],
            'old-promise' => [32, 16, 16, 16, 8, 8],
            'stale' => [16, 8, 8, 8, 4, 4],
        ];
        $grades = [64 => 'critical', 32 => 'high', 16 => 'medium', 8 => 'low', 4 => 'low'];
        foreach ($table as $flag => $halves) {
            $i = 0;
            foreach ([false, true] as $dev) {
                foreach (['direct', 'transitive', 'unreached'] as $reach) {
                    yield $flag.' '.$reach.($dev ? ' dev' : '') => [$flag, $reach, $dev, $halves[$i], $grades[$halves[$i]]];
                    ++$i;
                }
            }
        }
    }

    /**
     * One advisory alone: its severity's points, × 2 when no reachable fix exists, never halved by reach.
     *
     * @dataProvider severities
     */
    #[DataProvider('severities')]
    public function testAnAdvisoryAloneScoresItsSeverityPoints(string $severity, string $fix, int $points, int $multiplier, string $grade): void
    {
        foreach (['direct', 'transitive', 'unreached'] as $reach) {
            $score = self::score([], [[$severity, $fix]], $reach, false);

            self::assertSame($points, $score['total'], $reach);
            self::assertSame($grade, self::grade($score['total']), $reach);
            self::assertSame(['part' => 'security', 'flag' => 'vulnerable', 'role' => 'security', 'advisory' => 'A0', 'severity' => $severity, 'fix_kind' => $fix, 'weight' => intdiv($points, $multiplier), 'multiplier' => $multiplier, 'points' => $points, 'contribution' => $points], $score['terms'][0]);
            self::assertSame('security', $score['decided_by']);
        }
    }

    /** @return iterable<string, array{string, string, int, int, string}> */
    public static function severities(): iterable
    {
        $points = ['critical' => 32, 'high' => 16, 'medium' => 8, 'unrated' => 8, 'low' => 2];
        $bands = [64 => 'critical', 32 => 'critical', 16 => 'high', 8 => 'medium', 4 => 'low', 2 => 'low'];
        foreach ($points as $severity => $base) {
            foreach (['update' => 1, 'upgrade' => 1, 'raise-php' => 1, 'unknown' => 1, 'blocked' => 2, 'none' => 2] as $fix => $multiplier) {
                yield $severity.' '.$fix => [$severity, $fix, $base * $multiplier, $multiplier, $bands[$base * $multiplier]];
            }
        }
    }

    /**
     * @dataProvider bandEdges
     *
     * @param list<string>                $flags
     * @param list<array{string, string}> $advisories
     * @param array{floor: int, next: ?string, to_next: ?int} $band
     */
    #[DataProvider('bandEdges')]
    public function testTheBandOfATotalOnEachSideOfAnEdge(array $flags, array $advisories, string $reach, bool $dev, int $total, bool $roundedDown, array $band): void
    {
        $score = self::score($flags, $advisories, $reach, $dev);

        self::assertSame($total, $score['total']);
        self::assertSame($roundedDown, $score['rounded_down']);
        self::assertSame($band, $score['band']);
    }

    /** @return iterable<string, array{list<string>, list<array{string, string}>, string, bool, int, bool, array{floor: int, next: ?string, to_next: ?int}}> */
    public static function bandEdges(): iterable
    {
        yield '1: the first point' => [[], [['low', 'update']], 'direct', true, 1, false, ['floor' => 1, 'next' => 'medium', 'to_next' => 7]];
        yield '4.5 floors to 4, low' => [['left-behind', 'stale'], [], 'transitive', true, 4, true, ['floor' => 1, 'next' => 'medium', 'to_next' => 4]];
        yield '8: medium' => [['stale'], [], 'direct', false, 8, false, ['floor' => 8, 'next' => 'high', 'to_next' => 8]];
        yield '9: medium' => [['abandoned', 'old-promise'], [], 'transitive', true, 9, false, ['floor' => 8, 'next' => 'high', 'to_next' => 7]];
        yield '16: high' => [['old-promise'], [], 'direct', false, 16, false, ['floor' => 16, 'next' => 'critical', 'to_next' => 16]];
        yield '22: high' => [['left-behind', 'old-promise'], [['low', 'update']], 'direct', false, 22, false, ['floor' => 16, 'next' => 'critical', 'to_next' => 10]];
        yield '32: critical' => [['left-behind'], [['high', 'update']], 'direct', false, 32, false, ['floor' => 32, 'next' => null, 'to_next' => null]];
    }

    /**
     * `decided_by` compares the total's band with each part's alone-band.
     *
     * @dataProvider decidedBy
     *
     * @param list<string>                $flags
     * @param list<array{string, string}> $advisories
     */
    #[DataProvider('decidedBy')]
    public function testDecidedByComparesTheBandsOfThePartsAlone(array $flags, array $advisories, string $reach, string $decidedBy): void
    {
        self::assertSame($decidedBy, self::score($flags, $advisories, $reach, false)['decided_by']);
    }

    /** @return iterable<string, array{list<string>, list<array{string, string}>, string, string}> */
    public static function decidedBy(): iterable
    {
        yield 'either: both parts reach critical' => [['abandoned'], [['critical', 'none']], 'direct', 'either'];
        yield 'either: two low parts' => [['stale'], [['low', 'update']], 'transitive', 'either'];
        yield 'combination: two high parts make critical' => [['left-behind'], [['high', 'update']], 'direct', 'combination'];
        yield 'maintenance' => [['left-behind', 'old-promise'], [['low', 'update']], 'direct', 'maintenance'];
        yield 'security' => [['stale'], [['critical', 'update']], 'direct', 'security'];
    }

    public function testThePartsCarryTheirContributionAndTheirBandAlone(): void
    {
        $score = self::score(['left-behind', 'stale'], [['medium', 'update'], ['unrated', 'update']], 'transitive', true);

        self::assertSame([
            'maintenance' => ['status' => 'counted', 'contribution' => 4.5, 'alone' => ['total' => 4, 'verdict' => 'low']],
            'security' => ['status' => 'counted', 'contribution' => 4, 'alone' => ['total' => 4, 'verdict' => 'low'], 'of' => 2, 'tied' => ['A1']],
        ], $score['parts']);
        self::assertSame([
            ['reason' => 'transitive', 'applies_to' => 'maintenance', 'divide_by' => 2, 'before' => 18, 'after' => 9],
            ['reason' => 'dev', 'applies_to' => 'total', 'divide_by' => 2, 'before' => 17, 'after' => 8.5],
        ], $score['modifiers']);
        self::assertSame(8.5, $score['exact']);
        self::assertSame(8, $score['total']);
        self::assertTrue($score['rounded_down']);
        self::assertSame([4, 0.5, 4], array_column($score['terms'], 'contribution'));
    }

    public function testEveryRowOfWithoutIsAnEngineRerun(): void
    {
        $score = self::score(['abandoned', 'old-promise'], [['high', 'update'], ['high', 'none']], 'direct', false, 'silent');

        self::assertSame([
            ['remove' => ['kind' => 'flag', 'id' => 'abandoned'], 'revealed' => [['flag' => 'silent', 'role' => 'lead']], 'total' => 68, 'verdict' => 'critical', 'lead' => 'silent', 'deciding_advisory' => 'A1', 'at_least' => false],
            ['remove' => ['kind' => 'flag', 'id' => 'old-promise'], 'revealed' => [], 'total' => 64, 'verdict' => 'critical', 'lead' => 'abandoned', 'deciding_advisory' => 'A1', 'at_least' => false],
            ['remove' => ['kind' => 'flag', 'id' => 'vulnerable'], 'revealed' => [], 'total' => 36, 'verdict' => 'critical', 'lead' => 'abandoned', 'deciding_advisory' => null, 'at_least' => false],
            ['remove' => ['kind' => 'advisory', 'id' => 'A1'], 'revealed' => [], 'total' => 52, 'verdict' => 'critical', 'lead' => 'abandoned', 'deciding_advisory' => 'A0', 'at_least' => false],
        ], $score['without']);
    }

    public function testABroughtBackWordThatTheEntryAcceptsIsListedAsAccepted(): void
    {
        $score = self::score(['abandoned', 'old-promise'], [], 'direct', false, 'silent', 'silent');

        self::assertSame([['flag' => 'silent', 'role' => 'accepted']], $score['without'][0]['revealed']);
        self::assertSame(16, $score['without'][0]['total']);
        self::assertSame('old-promise', $score['without'][0]['lead']);
    }

    public function testAnAcceptedFlagCarriesWhatItWouldAddIfCounted(): void
    {
        $score = self::score(['old-promise', 'stale'], [], 'transitive', false, null, 'stale');

        self::assertSame(8, $score['total']);
        self::assertSame([['flag' => 'stale', 'weight' => 8, 'if_counted' => [
            'total' => 9, 'verdict' => 'medium', 'role' => 'corroborating', 'at_least' => false,
            'modifiers' => [['reason' => 'transitive', 'applies_to' => 'maintenance', 'divide_by' => 2, 'before' => 18, 'after' => 9]],
        ]]], $score['accepted']);
    }

    /**
     * The score-0 shape holds no arithmetic, only what was accepted, and its rerun's own halvings.
     */
    public function testAScoreOfZeroHasTheZeroShape(): void
    {
        $score = self::scoreOf(['stale'], [], 'transitive', true, null, 'stale');

        self::assertSame(['model', 'total', 'exact', 'accepted', 'text'], array_keys($score));
        self::assertSame(0, $score['total']);
        self::assertSame(0, $score['exact']);
        self::assertSame(['total' => 2, 'verdict' => 'low', 'role' => 'lead', 'at_least' => false, 'modifiers' => [
            ['reason' => 'transitive', 'applies_to' => 'maintenance', 'divide_by' => 2, 'before' => 8, 'after' => 4],
            ['reason' => 'dev', 'applies_to' => 'total', 'divide_by' => 2, 'before' => 4, 'after' => 2],
        ]], $score['accepted'][0]['if_counted']);
        self::assertSame(['model', 'total', 'exact', 'accepted', 'text'], array_keys(self::scoreOf([], [], 'direct', false)));
    }

    /**
     * @dataProvider partStatuses
     *
     * @param list<array{string, string}> $signals
     * @param ?list<string>               $entryFlags
     * @param list<array{string, string}> $advisories
     * @param array<string, bool>         $context
     */
    #[DataProvider('partStatuses')]
    public function testEachPartHasOneStatusByPrecedence(array $signals, ?array $entryFlags, array $advisories, array $context, string $maintenance, string $security): void
    {
        $entry = $entryFlags === null ? null : new AllowlistEntry('vendor/pkg', null, 'kept on purpose', null, 'project', $entryFlags === [] ? null : $entryFlags);
        $flags = FlagSet::fromSignals(self::signalsOf($signals), $entry, ScoreSweep::advisories($advisories));
        $parts = ScoreSweep::graded(ScoreBasis::of($flags, 'direct', false, $context + ScoreSweep::CONTEXT)->toArray())['parts'];

        self::assertSame($maintenance, $parts['maintenance']['status']);
        self::assertSame($security, $parts['security']['status']);
    }

    /** @return iterable<string, array{list<array{string, string}>, ?list<string>, list<array{string, string}>, array<string, bool>, string, string}> */
    public static function partStatuses(): iterable
    {
        yield 'counted and clear' => [[['S5', 'high']], null, [], [], 'counted', 'clear'];
        yield 'counted and unchecked' => [[['S5', 'high']], null, [], ['advisories_complete' => false], 'counted', 'unchecked'];
        yield 'accepted beats not judged' => [[['S5', 'high']], [], [['low', 'update']], ['maintenance_judged' => false], 'accepted', 'counted'];
        yield 'not judged' => [[], null, [['low', 'update']], ['maintenance_judged' => false], 'not_judged', 'counted'];
        yield 'none' => [[], null, [['low', 'update']], [], 'none', 'counted'];
    }

    /**
     * Only these rows are lower bounds: an accepted stale whose liveness is incomplete, a
     * without[abandoned] row read without S4 beside S2 absent or at high, a without[pinned] row whose
     * branch table was not read.
     *
     * @dataProvider lowerBounds
     *
     * @param list<array{string, string}> $signals
     * @param ?list<string>               $entryFlags
     * @param array<string, bool>         $context
     */
    #[DataProvider('lowerBounds')]
    public function testOnlyTheThreeLowerBoundRowsCarryAtLeast(array $signals, ?array $entryFlags, array $context, string $row, bool $atLeast): void
    {
        $entry = $entryFlags === null ? null : new AllowlistEntry('vendor/pkg', null, 'kept on purpose', null, 'project', $entryFlags);
        $flags = FlagSet::fromSignals(self::signalsOf($signals), $entry, ScoreSweep::advisories([['medium', 'update']]));
        $score = ScoreSweep::graded(ScoreBasis::of($flags, 'direct', false, $context + ScoreSweep::CONTEXT)->toArray());

        $found = null;
        foreach ($score['without'] as $without) {
            if ($without['remove']['id'] === $row) {
                $found = $without['at_least'];
            }
        }
        foreach ($score['accepted'] as $accepted) {
            if ($accepted['flag'] === $row) {
                $found = $accepted['if_counted']['at_least'];
            }
        }
        self::assertSame($atLeast, $found);
    }

    /** @return iterable<string, array{list<array{string, string}>, ?list<string>, array<string, bool>, string, bool}> */
    public static function lowerBounds(): iterable
    {
        $staleOldPromise = [['S2', 'warn'], ['S5', 'high']];
        yield 'accepted stale, liveness incomplete' => [$staleOldPromise, ['stale'], ['liveness_complete' => false], 'stale', true];
        yield 'accepted stale, S3 unread' => [$staleOldPromise, ['stale'], ['s3_unread' => true], 'stale', true];
        yield 'accepted stale, complete' => [$staleOldPromise, ['stale'], [], 'stale', false];
        yield 'accepted old-promise, liveness incomplete' => [$staleOldPromise, ['old-promise'], ['liveness_complete' => false], 'old-promise', false];
        yield 'without abandoned, S2 at high, no S4' => [[['S1', 'high'], ['S2', 'high']], null, ['liveness_complete' => false], 'abandoned', true];
        yield 'without abandoned, no S2, no S4' => [[['S1', 'high']], null, ['liveness_complete' => false], 'abandoned', true];
        yield 'without abandoned, S2 at warn' => [[['S1', 'high'], ['S2', 'warn']], null, ['liveness_complete' => false], 'abandoned', false];
        yield 'without abandoned, liveness complete' => [[['S1', 'high'], ['S2', 'high']], null, [], 'abandoned', false];
        yield 'without pinned, S8 unread' => [[['S6', 'high']], null, ['s8_unread' => true], 'pinned', true];
        yield 'without pinned, S8 read' => [[['S6', 'high']], null, [], 'pinned', false];
        yield 'without old-promise, S8 unread' => [[['S5', 'high']], null, ['s8_unread' => true, 'liveness_complete' => false], 'old-promise', false];
    }

    /**
     * @param list<string>                $flags
     * @param list<array{string, string}> $advisories
     *
     * @return Graded
     */
    private static function score(array $flags, array $advisories, string $reach, bool $dev, ?string $under = null, ?string $accepted = null): array
    {
        return ScoreSweep::graded(self::scoreOf($flags, $advisories, $reach, $dev, $under, $accepted));
    }

    /**
     * @param list<string>                $flags
     * @param list<array{string, string}> $advisories
     *
     * @return Graded|Zero
     */
    private static function scoreOf(array $flags, array $advisories, string $reach, bool $dev, ?string $under = null, ?string $accepted = null): array
    {
        return ScoreSweep::basis(['axis' => 'base', 'flags' => $flags, 'advisories' => $advisories, 'reach' => $reach, 'dev' => $dev, 'under' => $under, 'accepted' => $accepted])->toArray();
    }

    /**
     * @param list<array{string, string}> $signals
     *
     * @return list<Signal>
     */
    private static function signalsOf(array $signals): array
    {
        return array_map(static fn (array $s): Signal => new Signal($s[0], $s[1], $s[0].' fired'), $signals);
    }

    private static function grade(int $total): ?string
    {
        foreach (['critical' => 32, 'high' => 16, 'medium' => 8, 'low' => 1] as $grade => $floor) {
            if ($total >= $floor) {
                return $grade;
            }
        }

        return null;
    }
}
