<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Tests\Support\GateSweep;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The `gate` axis of the sweep: the gate score, the coverage and the grade marks of every combination
 * under every recorded lead and known advisory set. The default suite compares every 1,000th row.
 * The whole stream runs in the sweep group (sweep.yml).
 *
 * @coversNothing
 */
#[CoversNothing]
final class GateAxisSweepTest extends TestCase
{
    public function testTheSampledRowsEqualTheCommittedRows(): void
    {
        $sample = GateSweep::sample();
        self::assertNotSame([], $sample);

        foreach ($sample as $i => $row) {
            self::assertSame($row, GateSweep::row(GateSweep::parse($row)), 'row #'.(1000 * $i));
        }
    }

    /**
     * @group sweep
     */
    #[Group('sweep')]
    public function testTheWholeGateAxisHashesToTheGolden(): void
    {
        $hash = hash_init('sha256');
        $sample = GateSweep::sample();
        $index = 0;
        foreach (GateSweep::inputs() as $inputs) {
            $row = GateSweep::row($inputs);
            if ($index % 1000 === 0) {
                self::assertSame($sample[intdiv($index, 1000)] ?? '(no sampled row)', $row, 'row #'.$index);
            }
            hash_update($hash, $row."\n");
            ++$index;
        }

        self::assertSame(trim((string) file_get_contents(GateSweep::SHA256)), hash_final($hash), 'python3 tools/score/sweep.py --axis gate --sha256 prints the digest');
    }
}
