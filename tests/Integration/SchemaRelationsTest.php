<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Json\Schemas;
use Lockrot\Output\JsonFormatter;
use Lockrot\Tests\Support\NegativeFixtures;
use Lockrot\Tests\Support\SchemaCheck;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Each relation of report-2 (an `allOf` entry with a description) is reached by a valid document
 * under tests/fixtures/schema/documents/cases and broken by a negative fixture. explain-2 copies
 * the definitions that hold them ({@see \Lockrot\Tests\Unit\Json\SchemaParityTest}).
 *
 * A copy of the schema replaces each relation with itself plus a marker: an `enum` of one word
 * that names the relation. One validation of a document then names every relation that the
 * document breaks, or every relation that it reaches on an object.
 *
 * It covers nothing: it validates every case document and negative fixture against an instrumented
 * copy of report-2, which outlasts the timeout of a mutation run. SchemaRelationsTest guards the
 * schema, not the code.
 * The group parallel-rows gives each data row and each test a unit of its own in the ParaTest
 * run of ci.yml.
 *
 * @coversNothing
 *
 * @group covers-nothing
 * @group parallel-rows
 */
#[CoversNothing]
#[Group('covers-nothing')]
#[Group('parallel-rows')]
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
        'Under score model 1 each `next_step.also[].if_applied.verdict` is the band of its total, a score-0 word at 0.' => 'reads next_step.also[]',
        "Each `also[]` move of the `require` or `tag` kind of a transitive or unreached package that composer.json does not list writes no `composer require` command." => 'reads next_step.also[]',
    ];

    /** @var list<string> the pointers of the WAITING relations, found by their description */
    private static array $waiting = [];

    /** @var array<string, string> the schema JSON by mode */
    private static array $schemas = [];
    /** @var list<string> */
    private static array $relations = [];

    public static function setUpBeforeClass(): void
    {
        $published = (string) file_get_contents(Schemas::path(Schemas::REPORT, JsonFormatter::SCHEMA));
        $schema = self::arrays(json_decode($published));
        self::assertIsArray($schema);
        self::$schemas['published'] = $published;
        self::$waiting = [];
        foreach (array_keys(self::WAITING) as $description) {
            $pointers = self::pointersOf($schema, '#', $description);
            self::assertCount(1, $pointers, 'one relation has the description: '.$description);
            self::$waiting[] = $pointers[0];
        }
        foreach (['none', 'break', 'reach'] as $mode) {
            $relations = [];
            self::$schemas[$mode] = json_encode(self::instrument($schema, '#', $mode, $relations), SchemaCheck::ENCODE);
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
            self::assertSame([], self::errors('published', $json), basename($path).' is valid against report-2');
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
        $break = json_encode(self::instrument($schema, '#', 'break', $relations), SchemaCheck::ENCODE);

        $errors = SchemaCheck::justinrainbowErrors($break, '{"a": "x", "b": null, "c": 1}');

        self::assertSame(['#/allOf/0' => true], self::markers($errors, self::BROKEN, $relations));
    }

    /**
     * A decoded schema as arrays, with each `{}` kept as an object: an empty array is no schema,
     * and `properties: []` is no map of schemas.
     *
     * @param mixed $node
     *
     * @return mixed
     */
    private static function arrays($node)
    {
        if ($node instanceof \stdClass && get_object_vars($node) !== []) {
            $node = get_object_vars($node);
        }

        return \is_array($node) ? array_map(static fn ($child) => self::arrays($child), $node) : $node;
    }

    /** @return list<string> */
    private static function waiting(): array
    {
        $waiting = self::$waiting;
        foreach (self::$relations as $relation) {
            if (strpos($relation, '#/definitions/alsoMove/') === 0) {
                $waiting[] = $relation;
            }
        }

        return $waiting;
    }

    /**
     * The pointers of the schema nodes whose `description` is $description.
     *
     * @param array<mixed, mixed> $node
     *
     * @return list<string>
     */
    private static function pointersOf(array $node, string $pointer, string $description): array
    {
        $found = ($node['description'] ?? null) === $description ? [$pointer] : [];
        foreach ($node as $key => $child) {
            if (\is_array($child)) {
                $found = array_merge($found, self::pointersOf($child, $pointer.'/'.$key, $description));
            }
        }

        return $found;
    }

    /**
     * The markers need every keyword checked: opis stops at the first failing `allOf` entry, and a
     * failing keyword hides the `allOf` of its schema ({@see \Lockrot\Tests\Unit\Support\ValidatesJsonSchemasTest}).
     *
     * @return list<string> the error lines ({@see SchemaCheck::errors()})
     */
    private static function errors(string $mode, string $json): array
    {
        return $mode === 'published' ? SchemaCheck::errors(self::$schemas[$mode], $json) : SchemaCheck::justinrainbowErrors(self::$schemas[$mode], $json);
    }

    /**
     * @param list<string>      $messages the error lines
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
