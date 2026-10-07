<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Score\ScoreBasis;
use Lockrot\Tests\Support\ScoreInvariants;
use Lockrot\Tests\Support\ScoreSweep;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The invariants I0 to I17b on every score of the sweep: the base enumeration and its three extra
 * axes, in integer half points. One method per axis iterates its rows: a data set per row forks one
 * process per row. ScoreBasisSampleTest runs a sample (ScoreSweep::SAMPLE) in the default suite.
 *
 * It covers nothing and runs only in the sweep group (sweep.yml): Infection skips a mutant whose
 * covering tests together outlast its timeout.
 *
 * @coversNothing
 *
 * @group sweep
 *
 * @phpstan-import-type Graded from ScoreBasis
 * @phpstan-import-type Zero from ScoreBasis
 */
#[CoversNothing]
#[Group('sweep')]
final class ScoreBasisTest extends TestCase
{
    public function testTheInvariantsHoldOnTheBaseAxis(): void
    {
        $decidedBy = [];
        $largest = 0;
        foreach ($this->axis('base') as $score) {
            $decidedBy[$score['decided_by'] ?? '-'] = ($decidedBy[$score['decided_by'] ?? '-'] ?? 0) + 1;
            $largest = max($largest, $score['total']);
        }
        ksort($decidedBy);

        self::assertSame(['combination' => 10809, 'either' => 3070, 'maintenance' => 12225, 'security' => 21508], $decidedBy);
        self::assertSame(104, $largest);
    }

    public function testTheInvariantsHoldOnTheUnreachedAxis(): void
    {
        self::assertSame(ScoreSweep::AXES['unreached'], iterator_count($this->axis('unreached')));
    }

    public function testTheInvariantsHoldUnderAHiddenLivenessReading(): void
    {
        self::assertSame(ScoreSweep::AXES['under'], iterator_count($this->axis('under')));
    }

    public function testTheInvariantsHoldUnderAnEntryThatAcceptsTheHiddenWord(): void
    {
        self::assertSame(ScoreSweep::AXES['under_entry'], iterator_count($this->axis('under_entry')));
    }

    public function testTheInvariantsHoldWithEachFlagAccepted(): void
    {
        self::assertSame(ScoreSweep::AXES['accepted'], iterator_count($this->axis('accepted')));
    }

    /** @return \Generator<int, Graded|Zero> the score object of each row, once its invariants hold */
    private function axis(string $axis): \Generator
    {
        foreach (ScoreSweep::inputs() as $inputs) {
            if ($inputs['axis'] !== $axis) {
                continue;
            }
            $score = ScoreSweep::basis($inputs)->toArray();
            $bad = ScoreInvariants::violations($inputs, $score);
            if ($bad !== []) {
                self::fail(ScoreSweep::format($inputs, $score).': '.implode('; ', $bad));
            }
            yield $score;
        }
    }
}
