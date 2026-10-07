<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Tests\Support\ScoreInvariants;
use Lockrot\Tests\Support\ScoreSweep;
use PHPUnit\Framework\TestCase;

/**
 * The invariants I0 to I17b on every 1,000th row of each sweep axis, in the default suite. The sweep
 * group (ScoreBasisTest) checks every row.
 */
final class ScoreBasisSampleTest extends TestCase
{
    public function testTheInvariantsHoldOnTheSampledRows(): void
    {
        $sample = ScoreSweep::sample();
        self::assertNotSame([], $sample);

        foreach ($sample as $label => $row) {
            $inputs = ScoreSweep::parse($row);
            self::assertSame([], ScoreInvariants::violations($inputs, ScoreSweep::basis($inputs)->toArray()), $label.': '.$row);
        }
    }
}
