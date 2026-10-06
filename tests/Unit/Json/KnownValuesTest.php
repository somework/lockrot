<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Json;

use Lockrot\Json\KnownValues;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The strict reading of a published schema: `x-known-values` read as the `enum` it lists, wherever a
 * schema can sit, and nowhere else.
 */
final class KnownValuesTest extends TestCase
{
    public function testTheListBecomesTheEnumAndThePatternGoes(): void
    {
        $closed = KnownValues::closed(self::decode('{"type": "string", "pattern": "^[a-z]+$", "x-known-values": ["a", "b"]}'));

        self::assertSame('{"type":"string","x-known-values":["a","b"],"enum":["a","b"]}', json_encode($closed));
    }

    /** @return iterable<string, array{string, string}> a schema with an open node somewhere inside it, and that schema read strictly */
    public static function positions(): iterable
    {
        $open = '{"pattern": "^x$", "x-known-values": ["x"]}';
        $closed = '{"x-known-values":["x"],"enum":["x"]}';
        foreach (['properties', 'definitions', 'patternProperties', 'dependencies'] as $map) {
            yield 'a member of '.$map => ['{"'.$map.'": {"k": '.$open.'}}', '{"'.$map.'":{"k":'.$closed.'}}'];
        }
        foreach (['items', 'additionalItems', 'additionalProperties', 'not'] as $one) {
            yield 'under '.$one => ['{"'.$one.'": '.$open.'}', '{"'.$one.'":'.$closed.'}'];
        }
        foreach (['anyOf', 'oneOf', 'allOf', 'items'] as $list) {
            yield 'the second entry of '.$list => ['{"'.$list.'": [{"type": "null"}, '.$open.']}', '{"'.$list.'":[{"type":"null"},'.$closed.']}'];
        }
        yield 'deep down' => [
            '{"definitions": {"s": {"properties": {"floor": {"oneOf": ['.$open.', {"type": "null"}]}}}}}',
            '{"definitions":{"s":{"properties":{"floor":{"oneOf":['.$closed.',{"type":"null"}]}}}}}',
        ];
    }

    /** @dataProvider positions */
    #[DataProvider('positions')]
    public function testEveryPlaceASchemaSitsIsRead(string $schema, string $expected): void
    {
        self::assertSame($expected, json_encode(KnownValues::closed(self::decode($schema))));
    }

    /**
     * An object that is a value, not a schema, is left as written, whatever it holds: a `default`,
     * even one whose members look like schemas, an `enum` member, an `examples` entry, a `required`
     * list, a map member that is not an object, or a map keyword that holds a list.
     */
    public function testAValueThatIsNotASchemaIsLeftAlone(): void
    {
        $schema = '{"default":{"x-known-values":["a"],"k":{"pattern":"^x$","x-known-values":["x"]}},"enum":[{"x-known-values":["a"]}],'
            .'"examples":[{"x-known-values":["a"]}],"const":{"x-known-values":["a"]},"required":["x-known-values"],'
            .'"properties":{"k":true},"definitions":[],"anyOf":[true]}';

        self::assertSame($schema, json_encode(KnownValues::closed(self::decode($schema))));
    }

    public function testAnEnumAlreadyThereIsKept(): void
    {
        $schema = '{"type":"string","pattern":"^[a-z]+$","enum":["a"],"x-known-values":["a","b"]}';

        self::assertSame($schema, json_encode(KnownValues::closed(self::decode($schema))));
    }

    public function testAKeywordThatIsNotAListIsIgnored(): void
    {
        $schema = '{"type":"string","pattern":"^[a-z]+$","x-known-values":"a"}';

        self::assertSame($schema, json_encode(KnownValues::closed(self::decode($schema))));
    }

    public function testANodeWithoutTheKeywordIsUnchanged(): void
    {
        $schema = '{"type":"string","pattern":"^[a-z]+$","properties":{"a":{"type":"integer"}},"anyOf":[{"minLength":1}]}';

        self::assertSame($schema, json_encode(KnownValues::closed(self::decode($schema))));
    }

    public function testAnIntegerListBecomesTheEnumToo(): void
    {
        $closed = KnownValues::closed(self::decode('{"type": "integer", "minimum": 1, "x-known-values": [1, 4]}'));

        self::assertSame('{"type":"integer","minimum":1,"x-known-values":[1,4],"enum":[1,4]}', json_encode($closed));
    }

    /**
     * An open map read strictly: its known keys become its only properties, each typed by every
     * `patternProperties` regex it matches (one schema, or an `allOf` of them), and the map closes.
     */
    public function testTheKnownKeysBecomeTheMapsOnlyProperties(): void
    {
        $closed = KnownValues::closed(self::decode(
            '{"type": "object", "patternProperties": {"^sort$": {"type": "null"}, "^[a-z-]+$": {"type": "integer"}}, "x-known-keys": ["counted", "sort"]}'
        ));

        self::assertSame(
            '{"type":"object","x-known-keys":["counted","sort"],"properties":{"counted":{"type":"integer"},"sort":{"allOf":[{"type":"null"},{"type":"integer"}]}},"additionalProperties":false}',
            json_encode($closed)
        );
        // A schema, as the validator reads one: an object, not an array that encodes like one.
        $properties = $closed->properties ?? null;
        self::assertInstanceOf(\stdClass::class, $properties);
        self::assertInstanceOf(\stdClass::class, $properties->sort ?? null);
    }

    /** @return iterable<string, array{string, string}> an open map, and that map read strictly */
    public static function knownKeys(): iterable
    {
        yield 'a key no regex matches takes the schema of the other keys' => [
            '{"patternProperties":{"^a$":{"type":"null"}},"additionalProperties":{"type":"string"},"x-known-keys":["a","b"]}',
            '{"additionalProperties":false,"x-known-keys":["a","b"],"properties":{"a":{"type":"null"},"b":{"type":"string"}}}',
        ];
        yield 'a key no regex matches, other keys left open' => [
            '{"patternProperties":{"^a$":{"type":"null"}},"x-known-keys":["b"]}',
            '{"x-known-keys":["b"],"properties":{"b":{}},"additionalProperties":false}',
        ];
        yield 'a key no regex matches, other keys open in so many words' => [
            '{"patternProperties":{"^a$":{"type":"null"}},"additionalProperties":true,"x-known-keys":["b"]}',
            '{"additionalProperties":false,"x-known-keys":["b"],"properties":{"b":{}}}',
        ];
        yield 'a key no regex matches, other keys refused, is left out' => [
            '{"patternProperties":{"^a$":{"type":"null"}},"additionalProperties":false,"x-known-keys":["a","b"]}',
            '{"additionalProperties":false,"x-known-keys":["a","b"],"properties":{"a":{"type":"null"}}}',
        ];
        yield 'a map with no regexes' => [
            '{"additionalProperties":{"type":"integer"},"x-known-keys":["a"]}',
            '{"additionalProperties":false,"x-known-keys":["a"],"properties":{"a":{"type":"integer"}}}',
        ];
        yield 'regexes that are not a map' => [
            '{"patternProperties":[{"type":"null"}],"x-known-keys":["a"]}',
            '{"x-known-keys":["a"],"properties":{"a":{}},"additionalProperties":false}',
        ];
        yield 'a regex holding the delimiter the validator puts around it' => [
            '{"patternProperties":{"^a~b$":{"type":"null"}},"x-known-keys":["a~b"]}',
            '{"x-known-keys":["a~b"],"properties":{"a~b":{"type":"null"}},"additionalProperties":false}',
        ];
        yield 'a known key that is not a string is left out' => [
            '{"patternProperties":{"^1$":{"type":"null"}},"x-known-keys":[1,"x"]}',
            '{"x-known-keys":[1,"x"],"properties":{"x":{}},"additionalProperties":false}',
        ];
        yield 'an open set in a regex schema is read too' => [
            '{"patternProperties":{"^a$":{"pattern":"^x$","x-known-values":["x"]}},"x-known-keys":["a"]}',
            '{"x-known-keys":["a"],"properties":{"a":{"x-known-values":["x"],"enum":["x"]}},"additionalProperties":false}',
        ];
        yield 'deep down' => [
            '{"definitions":{"run":{"properties":{"rules":{"patternProperties":{"^a$":{"type":"integer"}},"x-known-keys":["a"]}}}}}',
            '{"definitions":{"run":{"properties":{"rules":{"x-known-keys":["a"],"properties":{"a":{"type":"integer"}},"additionalProperties":false}}}}}',
        ];
    }

    /** @dataProvider knownKeys */
    #[DataProvider('knownKeys')]
    public function testEveryKnownKeyIsTypedAsTheMapTypesIt(string $schema, string $expected): void
    {
        self::assertSame($expected, json_encode(KnownValues::closed(self::decode($schema))));
    }

    /** A node that already lists its properties keeps them, as one that already has an `enum` keeps it. */
    public function testPropertiesAlreadyThereAreKept(): void
    {
        $schema = '{"properties":{"a":{"type":"null"}},"patternProperties":{"^b$":{"type":"integer"}},"x-known-keys":["b"]}';

        self::assertSame($schema, json_encode(KnownValues::closed(self::decode($schema))));
    }

    public function testKnownKeysThatAreNotAListAreIgnored(): void
    {
        $schema = '{"patternProperties":{"^b$":{"type":"integer"}},"x-known-keys":"b"}';

        self::assertSame($schema, json_encode(KnownValues::closed(self::decode($schema))));
    }

    /** The validator is free to annotate what it is given, and the runtime keeps the result: the input stays as it was. */
    public function testTheInputIsNotTouched(): void
    {
        $json = '{"properties":{"a":{"pattern":"^x$","x-known-values":["x"]}},"anyOf":[{"x-known-values":["y"]}],"not":{"x-known-values":["z"]},'
            .'"definitions":{"d":{"x-known-values":["w"]}}}';
        $schema = self::decode($json);

        $closed = KnownValues::closed($schema);

        self::assertSame($json, json_encode($schema));
        self::assertNotSame($json, json_encode($closed));
    }

    private static function decode(string $json): \stdClass
    {
        $decoded = json_decode($json);
        self::assertInstanceOf(\stdClass::class, $decoded, $json);

        return $decoded;
    }
}
