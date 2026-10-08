<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\TestCase;

/** A committed case, run through the real analyzer, gives the score its document records. */
final class CaseHydratorTest extends TestCase
{
    public function testCaseAScoresItsRecordedScoreText(): void
    {
        $case = CaseHydrator::case('A');
        $recorded = JsonPath::arrayAt($case, ['finding']);

        $document = CaseHydrator::report($case)->toArray();

        $finding = null;
        foreach (JsonPath::arrayAt($document, ['findings']) as $row) {
            self::assertIsArray($row);
            if (JsonPath::stringAt($row, ['package']) === JsonPath::stringAt($recorded, ['package'])) {
                $finding = $row;
            }
        }
        self::assertNotNull($finding, 'the case package has a finding');
        self::assertSame('32 = vulnerable 32 [critical advisory]', JsonPath::stringAt($recorded, ['score', 'text']), 'the case records its score text');
        self::assertSame(JsonPath::stringAt($recorded, ['score', 'text']), JsonPath::stringAt($finding, ['score', 'text']));
        self::assertSame(JsonPath::stringAt($recorded, ['verdict']), JsonPath::stringAt($finding, ['verdict']));
    }

    public function testACaseWithoutInputsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CaseHydrator::report(['id' => 'X', 'inputs' => null]);
    }
}
