<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Json;

use Lockrot\Signal\PhpFloor;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\TestCase;

/**
 * A consumer reads the examples of the `run.project_php_lowest` description as the rule that turns
 * `require.php` into the field. Each example is a value that lockrot writes for its constraint.
 */
final class LowestPhpDescriptionTest extends TestCase
{
    public function testEveryExampleOfTheDescriptionIsTheLowestThatTheFloorGives(): void
    {
        $schema = JsonPath::decodeFile(__DIR__.'/../../../resources/lockrot-report-2.schema.json');
        $description = JsonPath::stringAt($schema, ['definitions', 'run', 'properties', 'project_php_lowest', 'description']);
        preg_match_all('/`([^`]+)` (?:gives|is) `([^`]+)`/', $description, $given, \PREG_SET_ORDER);
        preg_match_all('/`([^`]+)` for `([^`]+)`/', $description, $for, \PREG_SET_ORDER);
        $examples = [];
        foreach ($given as [, $constraint, $lowest]) {
            $examples[$constraint] = $lowest;
        }
        foreach ($for as [, $lowest, $constraint]) {
            $examples[$constraint] = $lowest;
        }
        self::assertArrayHasKey('>7.4', $examples, 'the description gives the lowest of an exclusive bound');

        foreach ($examples as $constraint => $lowest) {
            self::assertSame($lowest, (new PhpFloor('8.4', (string) $constraint))->lowestAsString(), 'require.php '.$constraint);
        }
    }
}
