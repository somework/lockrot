<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The ParaTest runs of the tests job in ci.yml exclude the group covers-nothing, so a covers_nothing
 * row must take the processes that the step starts itself, even with ParaTest installed.
 *
 * @coversNothing
 *
 * @group covers-nothing
 */
#[CoversNothing]
#[Group('covers-nothing')]
final class TestsStepTest extends TestCase
{
    private const CI = __DIR__.'/../../.github/workflows/ci.yml';
    private const STEP = 'Run tests with the matrix Composer binary';

    public function testACoversNothingRowNeverTakesTheParaTestRuns(): void
    {
        self::assertStringContainsString(
            'if [ -x vendor/bin/paratest ] && [ "${{ matrix.covers_nothing }}" != "true" ]; then',
            self::step()
        );
    }

    private static function step(): string
    {
        $workflow = (string) file_get_contents(self::CI);
        if (preg_match('/^  tests:\n(.*?)(?=^  \S|\z)/ms', $workflow, $job) !== 1) {
            self::fail('ci.yml has no tests job');
        }
        if (preg_match('/^      - name: '.preg_quote(self::STEP, '/').'\n(.*?)(?=^      - |\z)/ms', $job[1], $step) !== 1) {
            self::fail('the tests job has no test step');
        }

        return $step[1];
    }
}
