<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Json;

use JsonSchema\Validator;
use Lockrot\Exception\ConfigException;
use Lockrot\Json\SchemaErrors;
use PHPUnit\Framework\TestCase;

/** A refused document names each broken rule group by its path and description, the branch errors as details. */
final class SchemaErrorsTest extends TestCase
{
    private const SCHEMA = '{"type": "object", "properties": {
        "a": {"description": "A is a string or a number.", "oneOf": [{"type": "string"}, {"type": "integer"}]},
        "items": {"type": "array", "items": {"description": "An item has x or y.", "anyOf": [{"required": ["x"]}, {"required": ["y"]}], "properties": {"z": {"type": "string"}}}},
        "b": {"type": "string"},
        "c": {"description": "c is never 0.", "not": {"enum": [0]}}
    }}';

    public function testABrokenGroupIsNamedByItsPathAndDescription(): void
    {
        $error = self::exception('{"a": true}');

        self::assertSame("doc is invalid:\n  - a: A is a string or a number.", $error->getMessage());
        self::assertNotSame([], $error->details());
        self::assertStringStartsWith('  - a: ', $error->details()[0]);
    }

    public function testAnErrorBelowABrokenGroupIsADetailAndOneBesideItStaysInTheMessage(): void
    {
        $error = self::exception('{"items": [{"z": 1}], "b": 1}');

        self::assertSame("doc is invalid:\n  - items[0]: An item has x or y.\n  - b: Integer value found, but a string is required", $error->getMessage());
        self::assertContains('  - items[0].z: Integer value found, but a string is required', $error->details());
    }

    public function testANotGroupIsNamedToo(): void
    {
        self::assertSame("doc is invalid:\n  - c: c is never 0.", self::exception('{"c": 0}')->getMessage());
    }

    public function testWithoutABrokenGroupTheMessageHoldsTheValidatorErrors(): void
    {
        $error = self::exception('{"b": 1}');

        self::assertSame("doc is invalid:\n  - b: Integer value found, but a string is required", $error->getMessage());
        self::assertSame([], $error->details());
    }

    public function testAGroupAtTheRootMakesEveryErrorADetail(): void
    {
        $schema = json_decode('{"description": "The root has x.", "anyOf": [{"required": ["x"]}], "properties": {"b": {"type": "string"}}}');
        self::assertInstanceOf(\stdClass::class, $schema);
        $payload = json_decode('{"b": 1}');
        $validator = new Validator();
        $validator->validate($payload, $schema);

        $error = SchemaErrors::exception('doc is invalid:', $schema, $payload, $validator);

        self::assertSame("doc is invalid:\n  - : The root has x.", $error->getMessage());
        self::assertContains('  - b: Integer value found, but a string is required', $error->details());
    }

    public function testTheSchemaItReadsIsLeftAsItWas(): void
    {
        $schema = json_decode(self::SCHEMA);
        self::assertInstanceOf(\stdClass::class, $schema);
        $before = json_encode($schema);
        self::exception('{"a": true}', $schema);
        self::exception('{"a": true}', $schema);

        self::assertSame($before, json_encode($schema));
    }

    private static function exception(string $json, ?\stdClass $schema = null): ConfigException
    {
        $schema ??= json_decode(self::SCHEMA);
        self::assertInstanceOf(\stdClass::class, $schema);
        $payload = json_decode($json);
        $validator = new Validator();
        $validator->validate($payload, $schema);
        self::assertFalse($validator->isValid());

        return SchemaErrors::exception('doc is invalid:', $schema, $payload, $validator);
    }
}
