<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The schema step of the phar job in ci.yml validates the fixtures with the justinrainbow/json-schema
 * of the PHAR. Its two processes split JsonSchemaConformanceTest with one pattern, so each test of
 * the class runs in exactly one process, and a failure in either process fails the step.
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

    public function testBothProcessesLoadTheValidatorOfThePhar(): void
    {
        $step = self::step();

        self::assertMatchesRegularExpression("#LOCKROT_JR_VENDOR: '?build/phar/vendor'?[,}\\s]#", $step);
        self::assertStringContainsString('--bootstrap tests/json-schema-bootstrap.php', $step);
    }

    public function testAFailureInEitherProcessFailsTheStep(): void
    {
        $step = self::step();

        self::assertStringContainsString('set -o pipefail', $step);
        self::assertStringContainsString('wait "$pid" || failed=1', $step);
        self::assertStringContainsString('exit "$failed"', $step);
    }

    public function testTheSchemaStepRunsWithXdebugOff(): void
    {
        // Unquoted, YAML reads off as false, and Xdebug gets the mode "false".
        self::assertMatchesRegularExpression("/XDEBUG_MODE: 'off'/", self::step());
    }

    public function testTheTwoProcessesSplitTheClassByOnePatternThatNamesOneTestMethod(): void
    {
        $step = self::step();

        if (preg_match("/SPLIT: (?|'([^']*)'|([^,}\\s]+))/", $step, $split) !== 1) {
            self::fail('the step names no split pattern in SPLIT');
        }
        self::assertMatchesRegularExpression('/^\w+$/', $split[1], 'SPLIT must be a plain method name, not a wider pattern');
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
