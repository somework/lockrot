<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Lockrot\Verdict\Priority;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PriorityTest extends TestCase
{
    /** @dataProvider cases */
    #[DataProvider('cases')]
    public function testOf(string $verdict, bool $direct, bool $dev, string $expected): void
    {
        self::assertSame($expected, Priority::of($verdict, $direct, $dev));
    }

    /** @return iterable<string, array{string, bool, bool, string}> */
    public static function cases(): iterable
    {
        $base = [
            Verdict::ABANDONED => [Priority::CRITICAL, Priority::HIGH, Priority::HIGH, Priority::MEDIUM],
            Verdict::SILENT => [Priority::CRITICAL, Priority::HIGH, Priority::HIGH, Priority::MEDIUM],
            Verdict::PINNED => [Priority::HIGH, Priority::MEDIUM, Priority::MEDIUM, Priority::LOW],
            Verdict::LEFT_BEHIND => [Priority::HIGH, Priority::MEDIUM, Priority::MEDIUM, Priority::LOW],
            Verdict::OLD_PROMISE => [Priority::HIGH, Priority::MEDIUM, Priority::MEDIUM, Priority::LOW],
            Verdict::STALE => [Priority::MEDIUM, Priority::LOW, Priority::LOW, Priority::LOW],
            Verdict::UNKNOWN => [Priority::NONE, Priority::NONE, Priority::NONE, Priority::NONE],
            Verdict::FINISHED => [Priority::NONE, Priority::NONE, Priority::NONE, Priority::NONE],
            Verdict::OK => [Priority::NONE, Priority::NONE, Priority::NONE, Priority::NONE],
        ];
        $shapes = [
            ['direct prod', true, false, 0],
            ['transitive prod', false, false, 1],
            ['direct dev', true, true, 2],
            ['transitive dev', false, true, 3],
        ];
        foreach ($base as $verdict => $expected) {
            foreach ($shapes as [$label, $direct, $dev, $index]) {
                yield $verdict.' '.$label => [$verdict, $direct, $dev, $expected[$index]];
            }
        }
    }

    public function testEveryVerdictIsCovered(): void
    {
        self::assertCount(9, Verdict::all(), 'the case table above enumerates every verdict');
    }

    /** @dataProvider raised */
    #[DataProvider('raised')]
    public function testAnUnfixableAdvisoryRaisesOneStepUpToCritical(string $verdict, bool $direct, bool $dev, string $expected): void
    {
        self::assertSame($expected, Priority::of($verdict, $direct, $dev, true));
    }

    /** @return iterable<string, array{string, bool, bool, string}> */
    public static function raised(): iterable
    {
        yield 'critical stays critical' => [Verdict::ABANDONED, true, false, Priority::CRITICAL];
        yield 'high becomes critical' => [Verdict::ABANDONED, false, false, Priority::CRITICAL];
        yield 'medium becomes high' => [Verdict::ABANDONED, false, true, Priority::HIGH];
        yield 'left-behind transitive dev: low becomes medium' => [Verdict::LEFT_BEHIND, false, true, Priority::MEDIUM];
        yield 'stale direct prod: medium becomes high' => [Verdict::STALE, true, false, Priority::HIGH];
        yield 'an unflagged verdict is never raised' => [Verdict::OK, true, false, Priority::NONE];
        yield 'unknown is never raised' => [Verdict::UNKNOWN, true, false, Priority::NONE];
    }

    public function testRank(): void
    {
        self::assertSame(4, Priority::rank(Priority::CRITICAL));
        self::assertSame(3, Priority::rank(Priority::HIGH));
        self::assertSame(2, Priority::rank(Priority::MEDIUM));
        self::assertSame(1, Priority::rank(Priority::LOW));
        self::assertSame(0, Priority::rank(Priority::NONE));
    }

    public function testRankOfAnUnknownLevelIsZero(): void
    {
        self::assertSame(0, Priority::rank('urgent'));
    }

    public function testAllIsOrderedFromCriticalToNone(): void
    {
        self::assertSame(
            [Priority::CRITICAL, Priority::HIGH, Priority::MEDIUM, Priority::LOW, Priority::NONE],
            Priority::all()
        );
    }

    public function testFlaggedVerdictsNeverProduceNoneAndUnflaggedOnesAlwaysDo(): void
    {
        foreach (Verdict::all() as $verdict) {
            $priority = Priority::of($verdict, true, false);
            if (Verdict::flagged($verdict)) {
                self::assertNotSame(Priority::NONE, $priority, $verdict.' is flagged');
                continue;
            }
            self::assertSame(Priority::NONE, $priority, $verdict.' is not flagged');
        }
    }

    public function testTheLadderNeverFallsBelowLow(): void
    {
        self::assertSame(Priority::LOW, Priority::of(Verdict::STALE, false, true));
    }

    /**
     * The oracle for the basis: each base level's steps written out by hand, for every shape a
     * flagged finding can take, clamped steps included (a dev step at `low`, a raise at `critical`).
     * An unreached finding reads as a transitive one with the other reason.
     *
     * @return iterable<string, array{string, bool, bool, bool, bool, string, list<array{string, string, string}>, string}>
     */
    public static function bases(): iterable
    {
        $byBase = [
            'critical' => [
                'direct prod' => [],
                'direct prod no-fix' => [['no_fix_expected', 'critical', 'critical']],
                'direct dev' => [['dev', 'critical', 'high']],
                'direct dev no-fix' => [['dev', 'critical', 'high'], ['no_fix_expected', 'high', 'critical']],
                'transitive prod' => [['transitive', 'critical', 'high']],
                'transitive prod no-fix' => [['transitive', 'critical', 'high'], ['no_fix_expected', 'high', 'critical']],
                'transitive dev' => [['transitive', 'critical', 'high'], ['dev', 'high', 'medium']],
                'transitive dev no-fix' => [['transitive', 'critical', 'high'], ['dev', 'high', 'medium'], ['no_fix_expected', 'medium', 'high']],
            ],
            'high' => [
                'direct prod' => [],
                'direct prod no-fix' => [['no_fix_expected', 'high', 'critical']],
                'direct dev' => [['dev', 'high', 'medium']],
                'direct dev no-fix' => [['dev', 'high', 'medium'], ['no_fix_expected', 'medium', 'high']],
                'transitive prod' => [['transitive', 'high', 'medium']],
                'transitive prod no-fix' => [['transitive', 'high', 'medium'], ['no_fix_expected', 'medium', 'high']],
                'transitive dev' => [['transitive', 'high', 'medium'], ['dev', 'medium', 'low']],
                'transitive dev no-fix' => [['transitive', 'high', 'medium'], ['dev', 'medium', 'low'], ['no_fix_expected', 'low', 'medium']],
            ],
            'medium' => [
                'direct prod' => [],
                'direct prod no-fix' => [['no_fix_expected', 'medium', 'high']],
                'direct dev' => [['dev', 'medium', 'low']],
                'direct dev no-fix' => [['dev', 'medium', 'low'], ['no_fix_expected', 'low', 'medium']],
                'transitive prod' => [['transitive', 'medium', 'low']],
                'transitive prod no-fix' => [['transitive', 'medium', 'low'], ['no_fix_expected', 'low', 'medium']],
                'transitive dev' => [['transitive', 'medium', 'low'], ['dev', 'low', 'low']],
                'transitive dev no-fix' => [['transitive', 'medium', 'low'], ['dev', 'low', 'low'], ['no_fix_expected', 'low', 'medium']],
            ],
        ];
        $verdicts = [
            Verdict::ABANDONED => 'critical', Verdict::SILENT => 'critical',
            Verdict::PINNED => 'high', Verdict::LEFT_BEHIND => 'high', Verdict::OLD_PROMISE => 'high',
            Verdict::STALE => 'medium',
        ];
        foreach ($verdicts as $verdict => $base) {
            foreach ($byBase[$base] as $shape => $steps) {
                $direct = strpos($shape, 'direct') === 0;
                $dev = strpos($shape, ' dev') !== false;
                $noFix = strpos($shape, 'no-fix') !== false;
                $priority = $steps === [] ? $base : $steps[\count($steps) - 1][2];
                yield $verdict.' '.$shape => [$verdict, $direct, true, $dev, $noFix, $base, $steps, $priority];
                if (!$direct) {
                    $unreached = array_map(static fn (array $step): array => $step[0] === 'transitive' ? ['unreached', $step[1], $step[2]] : $step, $steps);
                    yield $verdict.' '.str_replace('transitive', 'unreached', $shape) => [$verdict, false, false, $dev, $noFix, $base, $unreached, $priority];
                }
            }
        }
    }

    /**
     * @param list<array{string, string, string}> $steps
     *
     * @dataProvider bases
     */
    #[DataProvider('bases')]
    public function testTheBasisIsTheWalkWrittenOut(string $verdict, bool $direct, bool $reached, bool $dev, bool $noFix, string $base, array $steps, string $priority): void
    {
        $basis = Priority::basis($verdict, $direct, $dev, $noFix, $reached);

        self::assertSame(['base' => $base, 'steps' => array_map(static fn (array $step): array => ['reason' => $step[0], 'from' => $step[1], 'to' => $step[2]], $steps)], $basis->toArray());
        self::assertSame($base, $basis->base());
        self::assertSame($priority, $basis->priority());
        self::assertSame($priority, Priority::of($verdict, $direct, $dev, $noFix), 'the table above agrees with of()');
    }

    /** @return iterable<string, array{string, bool, bool, bool, bool}> every verdict under every combination of the four flags */
    public static function everyShape(): iterable
    {
        foreach (Verdict::all() as $verdict) {
            foreach ([true, false] as $direct) {
                foreach ([true, false] as $reached) {
                    foreach ([true, false] as $dev) {
                        foreach ([true, false] as $noFix) {
                            yield \sprintf('%s direct=%d reached=%d dev=%d no-fix=%d', $verdict, $direct, $reached, $dev, $noFix) => [$verdict, $direct, $reached, $dev, $noFix];
                        }
                    }
                }
            }
        }
    }

    /**
     * What a reader of the basis relies on without knowing the rules: the steps chain from the base
     * to the priority, each moves one rank at most and in its reason's direction, a step stays put
     * only at the end of the order it moves towards, no step names `none`, and an unflagged verdict
     * has no steps whatever the flags say. The fold equal to of() is a serialization check; the
     * table above is the oracle.
     *
     * @dataProvider everyShape
     */
    #[DataProvider('everyShape')]
    public function testTheBasisChainsFromItsBaseToThePriority(string $verdict, bool $direct, bool $reached, bool $dev, bool $noFix): void
    {
        $basis = Priority::basis($verdict, $direct, $dev, $noFix, $reached);
        $array = $basis->toArray();
        $steps = $array['steps'];

        self::assertSame(Priority::of($verdict, $direct, $dev, $noFix), $basis->priority());
        if (!Verdict::flagged($verdict)) {
            self::assertSame(['base' => Priority::NONE, 'steps' => []], $array);

            return;
        }
        $at = $array['base'];
        $reasons = [];
        foreach ($steps as $step) {
            self::assertSame($at, $step['from'], 'each step starts where the last one ended');
            self::assertNotSame(Priority::NONE, $step['from']);
            self::assertNotSame(Priority::NONE, $step['to']);
            $move = Priority::rank($step['to']) - Priority::rank($step['from']);
            if ($step['reason'] === 'no_fix_expected') {
                self::assertSame($step['from'] === Priority::CRITICAL ? 0 : 1, $move, 'a raise is one step up, none at critical');
            } else {
                self::assertSame($step['from'] === Priority::LOW ? 0 : -1, $move, 'a lowering is one step down, none at low');
            }
            $at = $step['to'];
            $reasons[] = $step['reason'];
        }
        self::assertSame($basis->priority(), $at, 'the last step ends at the priority');
        $expected = [];
        if (!$direct) {
            $expected[] = $reached ? 'transitive' : 'unreached';
        }
        if ($dev) {
            $expected[] = 'dev';
        }
        if ($noFix) {
            $expected[] = 'no_fix_expected';
        }
        self::assertSame($expected, $reasons, 'one step for each fact that holds, in the order they apply');
    }

    /** A caller that does not say whether the package is reached, as of() does not, gets `transitive`. */
    public function testTheBasisTakesAPackageAsReachedUnlessToldOtherwise(): void
    {
        self::assertSame([['reason' => 'transitive', 'from' => 'medium', 'to' => 'low']], Priority::basis(Verdict::STALE, false, false, false)->steps());
    }
}
