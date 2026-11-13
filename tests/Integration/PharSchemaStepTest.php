<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The schema step of the phar job in ci.yml validates the fixtures with the justinrainbow/json-schema
 * of the PHAR. Its two processes run JsonSchemaConformanceTest and RecordedContractDocumentsSchemaTest,
 * one class each, and a failure in either process fails the step.
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

    public function testTheTwoProcessesRunTheConformanceClassAndTheContractBundleClass(): void
    {
        $step = self::step();

        self::assertStringContainsString('for class in JsonSchemaConformanceTest RecordedContractDocumentsSchemaTest; do', $step);
        self::assertStringContainsString('--fail-on-empty-test-suite "tests/Integration/$class.php"', $step);
        self::assertFileExists(__DIR__.'/JsonSchemaConformanceTest.php');
        self::assertFileExists(__DIR__.'/RecordedContractDocumentsSchemaTest.php');
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
