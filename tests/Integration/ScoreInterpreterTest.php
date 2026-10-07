<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Tests\Support\ScoreInterpreter;
use Lockrot\Tests\Support\ScoreSweep;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A consumer that reads only the decoded `run.score_model` gives every sweep row that the engine gives:
 * the model describes itself.
 */
final class ScoreInterpreterTest extends TestCase
{
    private const MODEL = __DIR__.'/../fixtures/score/score-model-1.json';

    public function testTheInterpreterEqualsTheEngineOnTheSampledRows(): void
    {
        $interpreter = self::interpreter();
        $sample = ScoreSweep::sample();
        self::assertNotSame([], $sample);

        foreach ($sample as $label => $row) {
            $inputs = ScoreSweep::parse($row);
            self::assertSame(ScoreSweep::row($inputs), $interpreter->row($inputs), $label);
        }
    }

    /**
     * @group sweep
     */
    #[Group('sweep')]
    public function testTheInterpreterEqualsTheEngineOnTheWholeSweep(): void
    {
        $interpreter = self::interpreter();
        $rows = 0;
        foreach (ScoreSweep::inputs() as $inputs) {
            $engine = ScoreSweep::row($inputs);
            if ($interpreter->row($inputs) !== $engine) {
                self::assertSame($engine, $interpreter->row($inputs));
            }
            ++$rows;
        }

        self::assertSame(array_sum(ScoreSweep::AXES), $rows);
    }

    private static function interpreter(): ScoreInterpreter
    {
        $model = json_decode((string) file_get_contents(self::MODEL), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($model);

        /** @var array<string, mixed> $model */
        return new ScoreInterpreter($model);
    }
}
