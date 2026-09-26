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
