<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Lockrot\Signal\Signal;
use Lockrot\Verdict\Verdict;
use Lockrot\Verdict\VerdictEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VerdictEngineTest extends TestCase
{
    /**
     * @param list<Signal> $signals
     *
     * @dataProvider cases
     */
    #[DataProvider('cases')]
    public function testDecide(array $signals, bool $allowlisted, bool $hasData, string $expected): void
    {
        self::assertSame($expected, (new VerdictEngine())->decide($signals, $allowlisted, $hasData));
    }

    /** @return iterable<string, array{list<Signal>, bool, bool, string}> */
    public static function cases(): iterable
    {
        $h = Signal::LEVEL_HIGH;
        $w = Signal::LEVEL_WARN;
        $s = static fn (string $id, string $l = Signal::LEVEL_HIGH): Signal => new Signal($id, $l, $id);
        yield 'nothing' => [[], false, true, Verdict::OK];
        yield 'no data' => [[], false, false, Verdict::UNKNOWN];
        yield 'allowlisted beats silent' => [[$s('S2', $h), $s('S4', $h)], true, true, Verdict::FINISHED];
        yield 'allowlisted with no data' => [[], true, false, Verdict::FINISHED];
        yield 'S1 abandoned' => [[$s('S1', $h), $s('S2', $h), $s('S4', $h)], false, true, Verdict::ABANDONED];
        yield 'S3 archived' => [[$s('S3', $h)], false, true, Verdict::ABANDONED];
        yield 'silent' => [[$s('S2', $h), $s('S4', $h)], false, true, Verdict::SILENT];
        yield 'S2 high + S4 warn is stale' => [[$s('S2', $h), $s('S4', $w)], false, true, Verdict::STALE];
        yield 'S2 warn + S4 high is stale' => [[$s('S2', $w), $s('S4', $h)], false, true, Verdict::STALE];
        yield 'S2 high alone is stale' => [[$s('S2', $h)], false, true, Verdict::STALE];
        yield 'S4 alone is stale' => [[$s('S4', $w)], false, true, Verdict::STALE];
        yield 'pinned beats old-promise and stale' => [[$s('S6', $w), $s('S5', $w), $s('S2', $h)], false, true, Verdict::PINNED];
        yield 'old-promise beats stale' => [[$s('S5', $w), $s('S2', $w)], false, true, Verdict::OLD_PROMISE];
        yield 'silent beats pinned' => [[$s('S2', $h), $s('S4', $h), $s('S6', $w)], false, true, Verdict::SILENT];
        yield 'lock-only signals without data still verdict' => [[$s('S6', $w)], false, false, Verdict::PINNED];
        // S7 describes what a package pulls in; it never decides what the package itself is.
        yield 'S7 alone is ok' => [[$s('S7', Signal::LEVEL_INFO)], false, true, Verdict::OK];
        yield 'S7 without data is still unknown' => [[$s('S7', Signal::LEVEL_INFO)], false, false, Verdict::UNKNOWN];
        yield 'S7 next to stale stays stale' => [[$s('S2', $w), $s('S7', Signal::LEVEL_INFO)], false, true, Verdict::STALE];
        yield 'S5 without data' => [[$s('S5', $w)], false, false, Verdict::OLD_PROMISE];
        yield 'duplicate S2 signal ids: last one wins' => [[$s('S2', $w), $s('S2', $h), $s('S4', $h)], false, true, Verdict::SILENT];
    }

    public function testSeverityOrderAndFlagged(): void
    {
        self::assertGreaterThan(Verdict::severity(Verdict::SILENT), Verdict::severity(Verdict::ABANDONED));
        self::assertGreaterThan(Verdict::severity(Verdict::PINNED), Verdict::severity(Verdict::SILENT));
        self::assertGreaterThan(Verdict::severity(Verdict::OLD_PROMISE), Verdict::severity(Verdict::PINNED));
        self::assertGreaterThan(Verdict::severity(Verdict::STALE), Verdict::severity(Verdict::OLD_PROMISE));
        self::assertGreaterThan(Verdict::severity(Verdict::UNKNOWN), Verdict::severity(Verdict::STALE));
        self::assertSame(0, Verdict::severity(Verdict::OK));
        self::assertSame(0, Verdict::severity(Verdict::FINISHED));
        self::assertTrue(Verdict::flagged(Verdict::STALE));
        self::assertFalse(Verdict::flagged(Verdict::UNKNOWN));
        self::assertFalse(Verdict::flagged(Verdict::FINISHED));
        self::assertTrue(Verdict::isValid('silent'));
        self::assertFalse(Verdict::isValid('dead'));
        self::assertSame(0, Verdict::severity('not-a-real-verdict'));
        self::assertSame(
            [Verdict::ABANDONED, Verdict::SILENT, Verdict::PINNED, Verdict::OLD_PROMISE, Verdict::STALE, Verdict::UNKNOWN, Verdict::FINISHED, Verdict::OK],
            Verdict::all()
        );
    }
}
