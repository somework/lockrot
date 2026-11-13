<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Support;

use JsonSchema\Validator;
use Lockrot\Json\KnownValues;
use Lockrot\Json\Schemas;
use Lockrot\Tests\Support\ValidatesJsonSchemas;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Each class that uses the trait reads each schema file and builds each strict twin once per test
 * process. Every call still gets a schema decoded afresh, because the validator writes into the
 * schema that it is given.
 *
 * It covers nothing: both sides of each comparison call KnownValues::closed(), so it kills no mutant.
 *
 * @coversNothing
 */
#[CoversNothing]
final class ValidatesJsonSchemasTest extends TestCase
{
    use ValidatesJsonSchemas;

    public function testTheStrictTwinOfAFileEqualsTheTwinBuiltAfreshFromTheFile(): void
    {
        $checked = 0;
        foreach ([Schemas::REPORT, Schemas::EXPLAIN, Schemas::BASELINE, Schemas::CONFIG] as $document) {
            foreach (Schemas::numbers($document) as $number) {
                $path = Schemas::path($document, $number);
                $file = json_decode((string) file_get_contents($path));
                self::assertInstanceOf(\stdClass::class, $file, $path);
                $fresh = self::strictTwin(KnownValues::closed($file));

                // serialize() tells 1 from 1.0 and "1" from 1, which assertEquals() does not.
                self::assertSame(serialize($fresh), serialize(self::strictSchemaAt($path)), $path.' (first call)');
                self::assertSame(serialize($fresh), serialize(self::strictSchemaAt($path)), $path.' (second call)');
                self::assertSame(serialize($file), serialize(self::schemaAt($path)), $path.' (published)');
                ++$checked;
            }
        }
        self::assertGreaterThanOrEqual(8, $checked, 'the published schemas');
    }

    public function testAValidationThatWritesIntoItsSchemaChangesNoLaterSchema(): void
    {
        $path = Schemas::path(Schemas::REPORT, 2);
        $file = json_decode((string) file_get_contents($path));
        self::assertInstanceOf(\stdClass::class, $file);
        $twin = self::strictTwin(KnownValues::closed($file));

        foreach ([[self::schemaAt($path), $file], [self::strictSchemaAt($path), $twin]] as [$given, $expected]) {
            $document = json_decode('{}');
            (new Validator())->validate($document, $given);
            self::assertNotEquals($expected, $given, 'the validator writes into the schema it is given, or this test proves nothing');
        }

        self::assertSame(serialize($file), serialize(self::schemaAt($path)));
        self::assertSame(serialize($twin), serialize(self::strictSchemaAt($path)));
    }

    public function testEachCallGetsItsOwnSchemaObject(): void
    {
        $path = Schemas::path(Schemas::BASELINE, 2);

        self::assertNotSame(self::schemaAt($path), self::schemaAt($path));
        self::assertNotSame(self::strictSchemaAt($path), self::strictSchemaAt($path));
    }
}
