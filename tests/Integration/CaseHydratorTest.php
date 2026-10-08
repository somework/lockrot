<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Tests\Support\CaseHydrator;
use PHPUnit\Framework\TestCase;

/** A committed case, run through the real analyzer, gives the score its document records. */
final class CaseHydratorTest extends TestCase
{
    public function testCaseAScoresItsRecordedScoreText(): void
    {
        $case = CaseHydrator::case('A');

        $document = CaseHydrator::report($case)->toArray();

        $finding = null;
        foreach ($document['findings'] as $row) {
            if ($row['package'] === $case['finding']['package']) {
                $finding = $row;
            }
        }
        self::assertNotNull($finding, 'the case package has a finding');
        self::assertSame('32 = vulnerable 32 [critical advisory]', $case['finding']['score']['text'], 'the case records its score text');
        self::assertSame($case['finding']['score']['text'], $finding['score']['text']);
        self::assertSame($case['finding']['verdict'], $finding['verdict']);
    }

    public function testACaseWithoutInputsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CaseHydrator::report(['id' => 'X', 'inputs' => null]);
    }
}
