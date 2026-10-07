<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Tests\Support\ScoreLineEvaluator;
use Lockrot\Tests\Support\ScoreSweep;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Every score line, read back as arithmetic, evaluates to `exact`, and its head to `total` (I17b).
 */
final class ScoreTextEvaluatesToExactTest extends TestCase
{
    public function testTheEvaluatorReadsAWrappedLine(): void
    {
        self::assertSame([4, 9], ScoreLineEvaluator::evaluate('4 = ((left-behind 16 + stale 2 [¼ of 8]) ÷ 2 transitive) ÷ 2 dev (4.5, rounded down)'));
        self::assertSame([34, 68], ScoreLineEvaluator::evaluate('34 = abandoned 32 + old-promise 4 [¼ of 16] ÷ 2 dev'), 'without the wrap the line reads as 34');
        self::assertSame([0, 0], ScoreLineEvaluator::evaluate('0 (left-behind accepted)'));
    }

    /**
     * @dataProvider brokenLines
     */
    #[DataProvider('brokenLines')]
    public function testTheEvaluatorRefusesABrokenLine(string $line): void
    {
        $this->expectException(\UnexpectedValueException::class);
        ScoreLineEvaluator::evaluate($line);
    }

    /** @return iterable<string, array{string}> */
    public static function brokenLines(): iterable
    {
        yield 'an unclosed parenthesis' => ['18 = (abandoned 32 + old-promise 4 [¼ of 16] ÷ 2 transitive'];
        yield 'a dangling operator' => ['16 = old-promise 16 + )'];
        yield 'a missing rounded-down tail' => ['4 = ((left-behind 16 + stale 2 [¼ of 8]) ÷ 2 transitive) ÷ 2 dev'];
        yield 'an extra rounded-down tail' => ['16 = old-promise 16 (16, rounded down)'];
        yield 'a tail that is not the body' => ['4 = ((left-behind 16 + stale 2 [¼ of 8]) ÷ 2 transitive) ÷ 2 dev (5.5, rounded down)'];
        yield 'a divisor that is no integer' => ['8 = old-promise 16 ÷ 2.5 transitive'];
    }

    public function testTheSampledLinesEvaluateToExact(): void
    {
        $sample = ScoreSweep::sample();
        self::assertNotSame([], $sample);

        foreach ($sample as $label => $row) {
            $score = ScoreSweep::basis(ScoreSweep::parse($row))->toArray();
            self::assertSame([$score['total'], ScoreSweep::halves($score['exact'])], ScoreLineEvaluator::evaluate($score['text']), $label.': '.$score['text']);
        }
    }

    /**
     * @group sweep
     */
    #[Group('sweep')]
    public function testEverySweepLineEvaluatesToExact(): void
    {
        $lines = 0;
        foreach (ScoreSweep::inputs() as $inputs) {
            $score = ScoreSweep::basis($inputs)->toArray();
            if (ScoreLineEvaluator::evaluate($score['text']) !== [$score['total'], ScoreSweep::halves($score['exact'])]) {
                self::fail(ScoreSweep::format($inputs, $score).': '.$score['text']);
            }
            ++$lines;
        }

        self::assertSame(array_sum(ScoreSweep::AXES), $lines);
    }
}
