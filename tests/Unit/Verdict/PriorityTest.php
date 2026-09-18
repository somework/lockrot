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
}
