<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The phar job of ci.yml validates the schema fixtures with the justinrainbow/json-schema of the
 * PHAR. Xdebug stays loaded in that job, because PharTest checks the archive's restart without it.
 * So the schema step turns Xdebug off. Its two processes split JsonSchemaConformanceTest with one
 * pattern, so each test of the class runs in exactly one process.
 *
 * @coversNothing
 *
 * @group covers-nothing
 */
#[CoversNothing]
#[Group('covers-nothing')]
final class PharSchemaStepTest extends TestCase
{
    private const CI = __DIR__.'/../../.github/workflows/ci.yml';
    private const STEP = "The schema fixtures validate with the PHAR's justinrainbow/json-schema";

    public function testTheSchemaStepRunsWithXdebugOff(): void
    {
        self::assertMatchesRegularExpression("/XDEBUG_MODE: 'off'/", self::step());
    }

    public function testTheTwoProcessesSplitTheClassByOnePatternThatNamesOneTestMethod(): void
    {
        $step = self::step();

        if (preg_match('/SPLIT: (\w+)/', $step, $split) !== 1) {
            self::fail('the step names no split pattern in SPLIT');
        }
        self::assertStringContainsString('for part in filter exclude-filter', $step);
        self::assertStringContainsString('"--$part" "$SPLIT"', $step);
        self::assertStringContainsString('--fail-on-empty-test-suite', $step);
        $matching = array_values(array_filter(
            get_class_methods(JsonSchemaConformanceTest::class),
            static fn (string $method): bool => strpos($method, 'test') === 0 && stripos($method, $split[1]) !== false
        ));
        self::assertSame([$split[1]], $matching, 'SPLIT must match one test method of JsonSchemaConformanceTest');
    }

    private static function step(): string
    {
        $workflow = (string) file_get_contents(self::CI);
        if (preg_match('/^  phar:\n(.*?)(?=^  \S|\z)/ms', $workflow, $job) !== 1) {
            self::fail('ci.yml has no phar job');
        }
        if (preg_match('/^      - name: '.preg_quote(self::STEP, '/').'\n(.*?)(?=^      - |\z)/ms', $job[1], $step) !== 1) {
            self::fail('the phar job has no schema step');
        }

        return $step[1];
    }
}
