<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Tests\Support\ScoreSweep;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The sweep differential: PHP regenerates the enumeration of tools/score/sweep.py and prints each row
 * as the committed stream holds it. JSON and a hash alone would hide which row differs, so a
 * mismatch shows the first differing row and its neighbours. ScoreSweep names the command that
 * regenerates the stream.
 */
final class SweepDifferentialTest extends TestCase
{
    public function testTheCommittedStreamHasItsPinnedHash(): void
    {
        $hash = hash_init('sha256');
        foreach (ScoreSweep::golden() as $row) {
            hash_update($hash, $row."\n");
        }

        self::assertSame(trim((string) file_get_contents(ScoreSweep::GOLDEN_SHA256)), hash_final($hash));
    }

    public function testTheSampledRowsEqualTheCommittedStream(): void
    {
        $sample = ScoreSweep::sample();
        self::assertNotSame([], $sample);

        foreach ($sample as $label => $row) {
            self::assertSame($row, ScoreSweep::row(ScoreSweep::parse($row)), $label);
        }
    }

    /**
     * @group sweep
     */
    #[Group('sweep')]
    public function testEveryRowEqualsTheCommittedStream(): void
    {
        $golden = ScoreSweep::golden();
        $counts = [];
        $previous = [];
        foreach (ScoreSweep::inputs() as $inputs) {
            $expected = $golden->valid() ? $golden->current() : '(the committed stream ends)';
            $actual = ScoreSweep::row($inputs);
            if ($actual !== $expected) {
                $golden->next();
                self::assertSame(implode("\n", array_merge($previous, [$expected, $golden->valid() ? $golden->current() : ''])), implode("\n", array_merge($previous, [$actual, '…'])), 'the first differing row, with the rows around it');
            }
            $previous = \array_slice(array_merge($previous, [$expected]), -2);
            $counts[$inputs['axis']] = ($counts[$inputs['axis']] ?? 0) + 1;
            $golden->next();
        }

        self::assertFalse($golden->valid(), 'the committed stream holds more rows');
        $expected = ScoreSweep::AXES;
        ksort($expected);
        ksort($counts);
        self::assertSame($expected, $counts);
    }
}
