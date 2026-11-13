<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Support;

use JsonSchema\Validator;
use Lockrot\Json\KnownValues;
use Lockrot\Json\Schemas;
use Lockrot\Tests\Support\SchemaCheck;
use Lockrot\Tests\Support\ValidatesJsonSchemas;
use Opis\JsonSchema\Validator as OpisValidator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Each class that uses the trait reads each schema file and builds each strict twin once per test
 * process. Every call still gets a schema decoded afresh, because the validator writes into the
 * schema that it is given. The document check reads a draft-04 schema as justinrainbow/json-schema
 * does, and its error lines name the property path and the keyword.
 *
 * It covers nothing: these tests check test code, and both sides of each strict twin comparison call
 * KnownValues::closed(), so it kills no mutant.
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

    public function testASchemaIsCheckedAgainstItselfWhenAnotherSchemaHasTheSameId(): void
    {
        $string = '{"$schema": "http://json-schema.org/draft-04/schema#", "id": "https://lockrot.dev/schema/same.json", "type": "object", "properties": {"a": {"type": "string"}}}';
        $integer = '{"$schema": "http://json-schema.org/draft-04/schema#", "id": "https://lockrot.dev/schema/same.json", "type": "object", "properties": {"a": {"type": "integer"}}}';

        self::assertSame([], SchemaCheck::errors($string, '{"a": "x"}'));
        self::assertSame(['a: type'], self::prefixes(SchemaCheck::errors($integer, '{"a": "x"}')));
        self::assertSame([], SchemaCheck::errors($string, '{"a": "x"}'));
    }

    public function testEachErrorLineNamesThePropertyPathTheKeywordAndTheMessage(): void
    {
        $schema = '{"type": "object", "required": ["name", "list"], "properties": {"list": {"type": "array", "minItems": 3, "items": {"type": "string", "enum": ["a", "b"]}}, "map": {"type": "object", "properties": {"0": {"type": "string"}, "a.b": {"pattern": "^x"}}}, "none": {"not": {"type": "string"}}, "one": {"anyOf": [{"type": "string"}, {"type": "null"}]}}}';
        $json = '{"list": ["a", "c"], "map": {"0": 1, "a.b": "y"}, "none": "s", "one": 2}';

        foreach (['opis' => SchemaCheck::opisErrors($schema, $json), 'justinrainbow' => SchemaCheck::justinrainbowErrors($schema, $json)] as $validator => $errors) {
            self::assertSame([
                'list: minItems',
                'list[1]: enum',
                'map.a.b: pattern',
                'map[0]: type',
                'name: required',
                'none: not',
                'one: anyOf',
                'one: type',
                'one: type',
            ], self::prefixes($errors), $validator.': '.implode("\n", $errors));
            self::assertContains('name: required: The property name is required', $errors, $validator);
        }
        self::assertSame(['(root): type'], self::prefixes(SchemaCheck::errors($schema, '[]')));
    }

    public function testAnAdditionalPropertyLineNamesTheObjectAndTheKey(): void
    {
        $schema = '{"type": "object", "properties": {"inner": {"type": "object", "properties": {"a": {}}, "additionalProperties": false}}, "additionalProperties": false}';
        $json = '{"inner": {"a": 1, "extra": 2}, "other": 3}';

        foreach (['opis' => SchemaCheck::opisErrors($schema, $json), 'justinrainbow' => SchemaCheck::justinrainbowErrors($schema, $json)] as $validator => $errors) {
            self::assertSame(['(root): additionalProperties', 'inner: additionalProperties'], self::prefixes($errors), $validator);
            self::assertNotSame([], array_filter($errors, static fn (string $error): bool => strpos($error, 'inner: additionalProperties: The property extra is not defined') === 0), $validator.': '.implode("\n", $errors));
            self::assertNotSame([], array_filter($errors, static fn (string $error): bool => strpos($error, '(root): additionalProperties: The property other is not defined') === 0), $validator.': '.implode("\n", $errors));
        }
    }

    /**
     * A characterization of opis/json-schema: the reason that SchemaRelationsTest reads its markers
     * with justinrainbow/json-schema. If an opis upgrade reports every entry, that test can use opis.
     */
    public function testOpisStopsAtTheFirstFailingAllOfEntryAndJustinrainbowChecksEveryEntry(): void
    {
        $schema = '{"type": "object", "properties": {"n": {"type": "string"}}, "allOf": [{"required": ["a"]}, {"required": ["b"]}]}';

        self::assertSame(['n: type'], self::prefixes(SchemaCheck::opisErrors($schema, '{"n": 1}')), 'a failing keyword hides the allOf of its schema');
        self::assertSame(['(root): allOf', 'a: required'], self::prefixes(SchemaCheck::opisErrors($schema, '{"n": "s"}')), 'the first failing entry hides the next one');
        self::assertSame(['(root): allOf', 'a: required', 'b: required', 'n: type'], self::prefixes(SchemaCheck::justinrainbowErrors($schema, '{"n": 1}')));
    }

    public function testOpisReadsAZeroFractionAsAnIntegerAndTheCheckReadsItAsJustinrainbowDoes(): void
    {
        $draft04 = '{"$schema": "http://json-schema.org/draft-04/schema#", "type": "object", "properties": {"n": {"type": "integer"}, "m": {"type": ["integer", "null"]}, "x": {"type": ["integer", "number"]}}}';
        $draft06 = json_decode(str_replace('draft-04', 'draft-06', $draft04));
        self::assertIsObject($draft06);
        $zeroFraction = '{"n": 3.0, "m": 3.0, "x": 3.0}';

        $opis = (new OpisValidator())->validate(json_decode($zeroFraction), $draft06);
        $justinrainbow = new Validator();
        $document = json_decode($zeroFraction);
        $justinrainbow->validate($document, json_decode($draft04));

        self::assertTrue($opis->isValid(), 'opis reads 3.0 as an integer in draft-06');
        self::assertFalse($justinrainbow->isValid(), 'justinrainbow reads 3.0 as no integer in draft-04');
        self::assertSame(['m: type', 'n: type'], self::prefixes(SchemaCheck::errors($draft04, $zeroFraction)));
        self::assertSame([], SchemaCheck::errors($draft04, '{"n": 3, "m": null, "x": 3.5}'));
    }

    public function testEachIntegerFieldOfThePublishedSchemasRefusesAFloat(): void
    {
        $fields = 0;
        foreach ([Schemas::REPORT, Schemas::EXPLAIN, Schemas::BASELINE, Schemas::CONFIG] as $document) {
            foreach (Schemas::numbers($document) as $number) {
                $path = Schemas::path($document, $number);
                foreach (self::integerNodes(json_decode((string) file_get_contents($path)), '#') as $pointer => $node) {
                    $schema = (string) json_encode($node);
                    $value = self::anInteger($node);
                    self::assertSame([], SchemaCheck::errors($schema, (string) $value), $path.' '.$pointer.' takes '.$value);
                    self::assertSame(['(root): type'], self::prefixes(SchemaCheck::errors($schema, $value.'.0')), $path.' '.$pointer.' refuses '.$value.'.0');
                    ++$fields;
                }
            }
        }
        self::assertGreaterThan(0, $fields, 'the integer fields of the published schemas');
    }

    public function testEachPublishedSchemaUsesOnlyKeywordsThatDraft06ReadsAsDraft04Does(): void
    {
        $checked = 0;
        foreach ([Schemas::REPORT, Schemas::EXPLAIN, Schemas::BASELINE, Schemas::CONFIG] as $document) {
            foreach (Schemas::numbers($document) as $number) {
                $path = Schemas::path($document, $number);
                $schema = json_decode((string) file_get_contents($path));
                self::assertInstanceOf(\stdClass::class, $schema, $path);

                self::assertSame([], self::draft04Forms($schema, '#'), $path);
                ++$checked;
            }
        }
        self::assertGreaterThanOrEqual(8, $checked, 'the published schemas');
        self::assertSame(['#/properties/a: exclusiveMinimum', '#/properties/b/items: id'], self::draft04Forms(json_decode('{"id": "x", "properties": {"a": {"minimum": 1, "exclusiveMinimum": true}, "b": {"items": {"id": "y"}}, "id": {"type": "string"}}}'), '#'));
    }

    public function testTheStrictTwinDeclaresDollarSchemaAtTheRootOfASchemaThatDoesNot(): void
    {
        $path = Schemas::path(Schemas::BASELINE, 1);
        $published = json_decode((string) file_get_contents($path));
        self::assertInstanceOf(\stdClass::class, $published);
        $twin = self::strictSchemaAt($path);
        self::assertInstanceOf(\stdClass::class, $published->properties);
        self::assertInstanceOf(\stdClass::class, $twin->properties);
        $baseline = '{"$schema": "https://lockrot.dev/schema/baseline-1.json", "lockrot": {"version": "0.1.0", "schema": 1}, "generated_at": "2026-09-14T00:00:00+00:00", "findings": {}}';

        self::assertFalse(property_exists($published->properties, '$schema'), 'the published schema stays as published');
        self::assertTrue(property_exists($twin->properties, '$schema'));
        self::assertSame([], $this->errorsAt($path, $baseline, true));
        self::assertSame(['(root): additionalProperties'], self::prefixes($this->errorsAt($path, str_replace('"$schema"', '"schema_url"', $baseline), true)));
    }

    public function testATwinKeepsAnEmptyObjectAsAnObject(): void
    {
        $schema = json_decode('{"type": "object", "properties": {"a": {"type": "object", "properties": {}}}}');
        self::assertIsObject($schema);

        self::assertSame(['a: additionalProperties'], self::prefixes($this->errorsAgainst($schema, '{"a": {"b": 1}}', true)));
    }

    public function testThePharBootstrapSelectsJustinrainbow(): void
    {
        $code = 'require "tests/json-schema-bootstrap.php"; echo \defined('.var_export(SchemaCheck::JUSTINRAINBOW, true).') ? "justinrainbow" : "opis";';
        $process = new Process([\PHP_BINARY, '-r', $code], __DIR__.'/../../..', ['LOCKROT_JR_VENDOR' => 'vendor']);
        $process->mustRun();

        self::assertSame('justinrainbow', $process->getOutput());
        self::assertFalse(\defined(SchemaCheck::JUSTINRAINBOW), 'this process checks with opis');
    }

    public function testTheCheckRunsOpisWhenTheBootstrapConstantIsNotDefined(): void
    {
        $schema = '{"type": "object", "properties": {"n": {"type": "string"}}, "allOf": [{"required": ["a"]}]}';

        self::assertSame(['n: type'], self::prefixes(SchemaCheck::errors($schema, '{"n": 1}')), 'opis hides the allOf of a schema with a failing keyword, justinrainbow does not');
    }

    /**
     * @param list<string> $errors
     *
     * @return list<string> each error's `<property>: <keyword>`, sorted
     */
    private static function prefixes(array $errors): array
    {
        $prefixes = array_map(static fn (string $error): string => implode(': ', \array_slice(explode(': ', $error), 0, 2)), $errors);
        sort($prefixes);

        return $prefixes;
    }

    /**
     * Each schema node whose `type` allows an integer and no other number.
     *
     * @param mixed $node
     *
     * @return array<string, \stdClass> by pointer
     */
    private static function integerNodes($node, string $pointer): array
    {
        $found = [];
        if ($node instanceof \stdClass) {
            $types = (array) ($node->type ?? []);
            if (\in_array('integer', $types, true) && !\in_array('number', $types, true)) {
                $found[$pointer] = $node;
            }
            foreach (get_object_vars($node) as $key => $child) {
                if (!\in_array($key, ['enum', 'x-known-values', 'default', 'examples'], true)) {
                    $found += self::integerNodes($child, $pointer.'/'.$key);
                }
            }
        } elseif (\is_array($node)) {
            foreach ($node as $index => $child) {
                $found += self::integerNodes($child, $pointer.'/'.$index);
            }
        }

        return $found;
    }

    private static function anInteger(\stdClass $node): int
    {
        foreach ((array) ($node->enum ?? []) as $value) {
            if (\is_int($value)) {
                return $value;
            }
        }

        return \is_int($node->minimum ?? null) ? $node->minimum : 0;
    }

    /**
     * The keywords whose form differs between draft-04 and draft-06: `exclusiveMinimum` and
     * `exclusiveMaximum` (a boolean in draft-04, a number in draft-06), and `id` below the root
     * (`$id` in draft-06). A member of `properties`, `patternProperties` or `definitions` is a name,
     * not a keyword.
     *
     * @return list<string> `<pointer>: <keyword>`
     */
    private static function draft04Forms(\stdClass $node, string $pointer, bool $root = true): array
    {
        $found = [];
        foreach (get_object_vars($node) as $keyword => $value) {
            $at = $pointer.'/'.$keyword;
            if (\in_array($keyword, ['exclusiveMinimum', 'exclusiveMaximum'], true) || ($keyword === 'id' && !$root)) {
                $found[] = $pointer.': '.$keyword;
            }
            if (\in_array($keyword, ['properties', 'patternProperties', 'definitions', 'dependencies'], true) && $value instanceof \stdClass) {
                foreach (get_object_vars($value) as $name => $member) {
                    if ($member instanceof \stdClass) {
                        $found = array_merge($found, self::draft04Forms($member, $at.'/'.$name, false));
                    }
                }
            } elseif ($value instanceof \stdClass && !\in_array($keyword, ['enum', 'x-known-values', 'default', 'examples'], true)) {
                $found = array_merge($found, self::draft04Forms($value, $at, false));
            } elseif (\is_array($value) && \in_array($keyword, ['items', 'allOf', 'anyOf', 'oneOf'], true)) {
                foreach ($value as $index => $item) {
                    if ($item instanceof \stdClass) {
                        $found = array_merge($found, self::draft04Forms($item, $at.'/'.$index, false));
                    }
                }
            }
        }

        return $found;
    }
}
