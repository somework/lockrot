<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use JsonSchema\Validator;
use Lockrot\Json\Schemas;
use Lockrot\Output\JsonFormatter;
use Lockrot\Tests\Support\NegativeFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Each relation of report-2 (an `allOf` entry with a description) is reached by a valid document
 * under tests/fixtures/schema/documents/cases and broken by a negative fixture. explain-2 copies
 * the definitions that hold them ({@see \Lockrot\Tests\Unit\Json\SchemaParityTest}).
 *
 * A copy of the schema replaces each relation with itself plus a marker: an `enum` of one word
 * that names the relation. One validation of a document then names every relation that the
 * document breaks, or every relation that it reaches on an object.
 */
final class SchemaRelationsTest extends TestCase
{
    private const DOCUMENTS = __DIR__.'/../fixtures/schema/documents/cases';
    private const NEGATIVE = __DIR__.'/../fixtures/schema/negative';
    private const BROKEN = 'relation-broken-';
    private const REACHED = 'relation-reached-';

    /**
     * The relations that no document reaches with a value that can break them, with the reason.
     * Each relation of `alsoMove` reads an entry of `next_step.also[]`, and no document has one.
     */
    private const WAITING = [
        '#/definitions/finding/allOf/22' => 'reads next_step.also[]',
        '#/definitions/finding/allOf/35' => 'reads next_step.also[]',
    ];

    /** @var array<string, object> by mode */
    private static array $schemas = [];
    /** @var list<string> */
    private static array $relations = [];

    public static function setUpBeforeClass(): void
    {
        $schema = json_decode((string) file_get_contents(Schemas::path(Schemas::REPORT, JsonFormatter::SCHEMA)), true);
        self::assertIsArray($schema);
        foreach (['none', 'break', 'reach'] as $mode) {
            $relations = [];
            $instrumented = json_decode((string) json_encode(self::instrument($schema, '#', $mode, $relations)));
            self::assertIsObject($instrumented);
            self::$schemas[$mode] = $instrumented;
            self::$relations = $relations;
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$schemas = [];
        self::$relations = [];
    }

    public function testEveryRelationIsBrokenByANegativeFixture(): void
    {
        $broken = [];
        foreach (NegativeFixtures::rows(self::NEGATIVE) as [$path]) {
            if (basename(\dirname($path)) !== Schemas::REPORT.'-'.JsonFormatter::SCHEMA) {
                continue;
            }
            [$json] = NegativeFixtures::read($path);
            $broken += self::markers(self::errors('break', $json), self::BROKEN);
        }

        self::assertSame([], array_values(array_diff(self::$relations, array_keys($broken), self::waiting())), 'a relation no negative fixture breaks');
        self::assertSame([], array_values(array_intersect(self::waiting(), array_keys($broken))), 'a waiting relation that a fixture breaks: take it off the list');
    }

    public function testEveryRelationIsReachedByAValidDocument(): void
    {
        $reached = [];
        $documents = glob(self::DOCUMENTS.'/*.json') ?: [];
        self::assertNotSame([], $documents);
        foreach ($documents as $path) {
            $json = (string) file_get_contents($path);
            self::assertSame([], self::errors('none', $json), basename($path).' is valid without its relations');
            $reached += self::markers(self::errors('reach', $json), self::REACHED);
        }

        self::assertSame([], array_values(array_diff(self::$relations, array_keys($reached), self::waiting())), 'a relation no valid document reaches');
    }

    public function testTheMarkersNameTheRelationADocumentBreaks(): void
    {
        $schema = ['type' => 'object', 'allOf' => [
            ['description' => 'a is null exactly when b is.', 'anyOf' => [['properties' => ['a' => ['type' => 'null'], 'b' => ['type' => 'null']]], ['properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'string']]]]],
            ['description' => 'c is never 0.', 'properties' => ['c' => ['not' => ['enum' => [0]]]]],
        ]];
        $relations = [];
        $break = json_decode((string) json_encode(self::instrument($schema, '#', 'break', $relations)));
        self::assertIsObject($break);

        $validator = new Validator();
        $document = json_decode('{"a": "x", "b": null, "c": 1}');
        $validator->validate($document, $break);
        $errors = self::messages($validator);

        self::assertSame(['#/allOf/0' => true], self::markers($errors, self::BROKEN, $relations));
    }

    /** @return list<string> */
    private static function messages(Validator $validator): array
    {
        $messages = [];
        foreach ($validator->getErrors() as $error) {
            self::assertIsArray($error);
            self::assertIsString($error['message'] ?? null);
            $messages[] = $error['message'];
        }

        return $messages;
    }

    /** @return list<string> */
    private static function waiting(): array
    {
        $waiting = array_keys(self::WAITING);
        foreach (self::$relations as $relation) {
            if (strpos($relation, '#/definitions/alsoMove/') === 0) {
                $waiting[] = $relation;
            }
        }

        return $waiting;
    }

    /** @return list<string> the messages of the errors */
    private static function errors(string $mode, string $json): array
    {
        $document = json_decode($json);
        self::assertNotNull($document);
        $validator = new Validator();
        $validator->validate($document, self::$schemas[$mode]);

        return self::messages($validator);
    }

    /**
     * @param list<string>      $messages
     * @param list<string>|null $relations the relations the markers count, the schema's unless given
     *
     * @return array<string, true> the relations that a marker names
     */
    private static function markers(array $messages, string $prefix, ?array $relations = null): array
    {
        $relations ??= self::$relations;
        $found = [];
        foreach ($messages as $message) {
            if (preg_match_all('{'.preg_quote($prefix, '{').'(\d+)}', $message, $matches) > 0) {
                foreach ($matches[1] as $index) {
                    $found[$relations[(int) $index]] = true;
                }
            }
        }

        return $found;
    }

    /**
     * The schema with each described `allOf` entry removed (`none`), or kept beside a marker that
     * fails exactly when the entry fails (`break`), or kept beside a marker that fails on every
     * object the entry is applied to (`reach`).
     *
     * @param mixed        $node
     * @param list<string> $relations the pointers of the relations, in marker order
     *
     * @return mixed
     */
    private static function instrument($node, string $pointer, string $mode, array &$relations)
    {
        if (!\is_array($node)) {
            return $node;
        }
        $out = [];
        foreach ($node as $key => $child) {
            $out[$key] = self::instrument($child, $pointer.'/'.$key, $mode, $relations);
        }
        $isList = $node === [] || array_keys($node) === range(0, \count($node) - 1);
        if ($isList || !\is_array($out['allOf'] ?? null)) {
            return $out;
        }
        foreach ($out['allOf'] as $i => $relation) {
            if (!\is_array($relation) || !isset($relation['description'])) {
                continue;
            }
            $marker = \count($relations);
            $relations[] = $pointer.'/allOf/'.$i;
            if ($mode === 'none') {
                $out['allOf'][$i] = new \stdClass();
            } elseif ($mode === 'break') {
                $out['allOf'][$i] = ['anyOf' => [$relation, ['enum' => [self::BROKEN.$marker]]]];
            } else {
                $out['allOf'][$i] = ['allOf' => [$relation, ['anyOf' => [['not' => ['type' => 'object']], ['enum' => [self::REACHED.$marker]]]]]];
            }
        }

        return $out;
    }
}
