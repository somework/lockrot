<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Output\RecordMarks;
use Lockrot\Score\CountedAdvisory;
use Lockrot\Score\GateScore;
use Lockrot\Signal\Data\Check;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\Score;
use PHPUnit\Framework\TestCase;

/** SPEC-0.14.md §6.2, "Record marks follow the fact": the facts whose records are `error`, per value a finding fails. */
final class RecordMarksTest extends TestCase
{
    /** A grade marks the lead and the security term that reach half of its floor. */
    public function testAGradeMarksTheTermsThatReachHalfOfItsFloor(): void
    {
        $score = Score::compute(['left-behind'], [new CountedAdvisory('PKSA-high', 'high', 'update')], 'direct', false);

        self::assertSame(
            [['value' => 'high', 'kind' => 'grade', 'facts' => [['kind' => 'flag', 'id' => 'left-behind'], ['kind' => 'advisory', 'id' => 'PKSA-high']]]],
            self::marks($score, null, ['left-behind'], [], [], 'high')
        );
    }

    /** A rot gate never marks a security issue `error` when the advisory alone does not reach half of the floor. */
    public function testARotGateNeverMarksASmallSecurityTerm(): void
    {
        $score = Score::compute(['left-behind'], [new CountedAdvisory('PKSA-low', 'low', 'update')], 'direct', false);

        self::assertSame([['kind' => 'flag', 'id' => 'left-behind']], self::marks($score, null, ['left-behind'], [], [], 'high')[0]['facts']);
    }

    /**
     * The transitive accepted-critical row: a known critical advisory, then old-promise and a new
     * medium advisory. New 16, gate 32: critical. Under an entry each new term needs a quarter of
     * the floor, so both are marked, and neither reaches half of it.
     */
    public function testUnderAnEntryANewTermNeedsAQuarterOfTheFloor(): void
    {
        $known = new CountedAdvisory('PKSA-known', 'critical', 'update');
        $new = new CountedAdvisory('PKSA-new', 'medium', 'update');
        $score = Score::compute(['old-promise'], [$known, $new], 'transitive', false);
        $gate = GateScore::of($score, ['old-promise'], [$new]);

        self::assertSame([16, 32, 'critical'], [$gate->newScore()->total(), $gate->total(), $gate->verdict()]);
        self::assertSame(
            [['kind' => 'flag', 'id' => 'old-promise'], ['kind' => 'advisory', 'id' => 'PKSA-new']],
            self::marks($score, $gate, ['old-promise'], [$new], [], 'critical')[0]['facts']
        );
    }

    public function testAFlagValueMarksEachUncoveredFlagAtOrAboveIt(): void
    {
        $score = Score::compute(['left-behind', 'stale'], [], 'direct', false);

        self::assertSame([['kind' => 'flag', 'id' => 'left-behind'], ['kind' => 'flag', 'id' => 'stale']], self::marks($score, null, ['left-behind', 'stale'], [], [], 'stale')[0]['facts']);
        self::assertSame([['kind' => 'flag', 'id' => 'left-behind']], self::marks($score, null, ['left-behind', 'stale'], [], [], 'left-behind')[0]['facts']);
        self::assertSame([], self::marks($score, null, ['stale'], [], [], 'left-behind'), 'a covered flag is no record of the value');
    }

    public function testAVulnerableValueMarksEachUnknownAdvisoryAtOrAboveItBySeverity(): void
    {
        $advisories = [new CountedAdvisory('B', 'high', 'update'), new CountedAdvisory('C', 'medium', 'update'), new CountedAdvisory('A', 'critical', 'update')];
        $score = Score::compute([], $advisories, 'direct', false);

        self::assertSame([['kind' => 'advisory', 'id' => 'A'], ['kind' => 'advisory', 'id' => 'B']], self::marks($score, null, [], $advisories, [], 'vulnerable-high')[0]['facts']);
    }

    /** `unchecked` names each signal that the missing checks blocked, once, and an entry with nothing new exempts it. */
    public function testUncheckedMarksTheBlockedSignals(): void
    {
        $score = Score::compute(['stale'], [], 'direct', false);
        $missing = [new Check('repository_activity', 'offline', ['S3', 'S4']), new Check('release_metadata', 'offline', ['S2', 'S4'])];

        self::assertSame(
            [['kind' => 'signal', 'id' => 'S3'], ['kind' => 'signal', 'id' => 'S4'], ['kind' => 'signal', 'id' => 'S2']],
            self::marks($score, null, ['stale'], [], $missing, 'unchecked')[0]['facts']
        );
        self::assertSame([], self::marks($score, GateScore::of($score, [], []), [], [], $missing, 'unchecked'), 'a known finding with S10 is exempt');
    }

    public function testEachFailedValueKeepsTheOrderOfRunGates(): void
    {
        $score = Score::compute(['abandoned'], [], 'direct', false);

        self::assertSame(['critical', 'abandoned'], array_column(self::marks($score, null, ['abandoned'], [], [], 'critical,abandoned'), 'value'));
    }

    /**
     * @param list<string>          $uncovered
     * @param list<CountedAdvisory> $unknown
     * @param list<Check>           $missing
     *
     * @return list<array<string, mixed>>
     */
    private static function marks(Score $score, ?GateScore $gate, array $uncovered, array $unknown, array $missing, string $failOn): array
    {
        /** @var list<array<string, mixed>> $marks */
        $marks = JsonPath::decoded(RecordMarks::of($score, $gate, $uncovered, $unknown, $missing, FailOn::parse($failOn)->values()));

        return $marks;
    }
}
