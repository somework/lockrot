<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Json;

use Lockrot\Json\Schemas;
use Lockrot\Output\ExplainFormatter;
use Lockrot\Output\JsonFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * explain-2 types the finding it explains with report-2's own definitions, so a consumer that reads
 * one document reads the other. The checks are a port of the schema draft's parity check: the
 * builders in tools/schema write both files.
 */
final class SchemaParityTest extends TestCase
{
    /** The roots whose definitions explain-2 copies from report-2. */
    private const SHARED_ROOTS = ['finding', 'noteDetail', 'scoreModel'];

    /** @var array<string, array<string, mixed>> by document */
    private static array $schemas = [];

    public function testExplainCopiesEveryDefinitionTheFindingReachesByteForByte(): void
    {
        $report = self::definitions(Schemas::REPORT, JsonFormatter::SCHEMA);
        $explain = self::definitions(Schemas::EXPLAIN, ExplainFormatter::SCHEMA);

        $closure = self::closure($report, self::SHARED_ROOTS);

        self::assertContains('severity', $closure, 'the closure walks the references');
        foreach ($closure as $name) {
            self::assertArrayHasKey($name, $explain, 'explain-2 lacks report-2 definition '.$name);
            self::assertSame(self::sorted($report[$name]), self::sorted($explain[$name]), 'definition '.$name);
        }
    }

    public function testEveryDefinitionNameBothFilesShareIsTheSameDefinition(): void
    {
        $report = self::definitions(Schemas::REPORT, JsonFormatter::SCHEMA);
        $explain = self::definitions(Schemas::EXPLAIN, ExplainFormatter::SCHEMA);

        $shared = array_intersect_key($report, $explain);

        self::assertNotSame([], $shared);
        foreach (array_keys($shared) as $name) {
            self::assertSame(self::sorted($report[$name]), self::sorted($explain[$name]), 'shared definition '.$name);
        }
    }

    public function testEachRunKeyOfAnExplanationIsTheReportsRunKeyOfTheSameName(): void
    {
        $report = self::properties(self::definitions(Schemas::REPORT, JsonFormatter::SCHEMA), 'run');
        $explain = self::properties(self::definitions(Schemas::EXPLAIN, ExplainFormatter::SCHEMA), 'explainRun');

        self::assertNotSame([], $explain);
        foreach ($explain as $key => $schema) {
            self::assertArrayHasKey($key, $report, 'explain-2 run.'.$key.' is no report-2 run key');
            self::assertSame(self::sorted($report[$key]), self::sorted($schema), 'run.'.$key);
        }
    }

    /**
     * A `multipleOf` without an exact binary form (0.1) fails values that it should accept in
     * validators that test the quotient (ajv, python-jsonschema): only a dyadic value is safe.
     *
     * @dataProvider numberedSchemas
     */
    #[DataProvider('numberedSchemas')]
    public function testEveryMultipleOfHasAnExactBinaryForm(string $path): void
    {
        $found = self::walk(self::decode($path), '#', static function (array $node, string $at): array {
            $multipleOf = $node['multipleOf'] ?? null;
            if (!\is_int($multipleOf) && !\is_float($multipleOf)) {
                return [];
            }

            return self::isDyadic((float) $multipleOf) ? [] : [$at.': multipleOf '.$multipleOf];
        });

        self::assertSame([], $found);
    }

    /**
     * Every `pattern` and `patternProperties` key: no unescaped `/`, which justinrainbow 5.3.0 (the
     * Composer 2.2 leg) rejects in the draft-04 meta-schema check, and no lookaround, which an RE2
     * validator cannot compile.
     *
     * @dataProvider numberedSchemas
     */
    #[DataProvider('numberedSchemas')]
    public function testEveryPatternCompilesEverywhere(string $path): void
    {
        $found = self::walk(self::decode($path), '#', static function (array $node, string $at): array {
            $patterns = \is_string($node['pattern'] ?? null) ? [$node['pattern']] : [];
            if (\is_array($node['patternProperties'] ?? null)) {
                $patterns = array_merge($patterns, array_map('strval', array_keys($node['patternProperties'])));
            }
            $bad = [];
            foreach ($patterns as $pattern) {
                if (preg_match('{(?<!\\\\)(?:\\\\\\\\)*/}', $pattern) === 1) {
                    $bad[] = $at.': unescaped / in '.$pattern;
                }
                foreach (['(?=', '(?!', '(?<=', '(?<!'] as $lookaround) {
                    if (strpos($pattern, $lookaround) !== false) {
                        $bad[] = $at.': lookaround in '.$pattern;
                    }
                }
            }

            return $bad;
        });

        self::assertSame([], $found);
    }

    public function testTheChecksCanFail(): void
    {
        self::assertFalse(self::isDyadic(0.1));
        self::assertTrue(self::isDyadic(0.5));
        self::assertTrue(self::isDyadic(0.01 * 25 * 4));
        self::assertSame(['x', 'y'], self::closure(['x' => ['$ref' => '#/definitions/y'], 'y' => ['type' => 'string'], 'z' => []], ['x']));
        self::assertNotSame(self::sorted(['a' => 1, 'b' => ['c' => 2]]), self::sorted(['a' => 1, 'b' => ['c' => 3]]));
        self::assertSame(self::sorted(['a' => 1, 'b' => 2]), self::sorted(['b' => 2, 'a' => 1]));
    }

    /** @return iterable<string, array{string}> every numbered file from 2 on: a `-1` file is frozen as it shipped */
    public static function numberedSchemas(): iterable
    {
        foreach ([Schemas::REPORT, Schemas::EXPLAIN, Schemas::BASELINE, Schemas::CONFIG] as $document) {
            foreach (Schemas::numbers($document) as $number) {
                if ($number >= 2) {
                    yield $document.'-'.$number => [Schemas::path($document, $number)];
                }
            }
        }
    }

    /** @return array<string, mixed> */
    private static function decode(string $path): array
    {
        if (!isset(self::$schemas[$path])) {
            $decoded = json_decode((string) file_get_contents($path), true);
            self::assertIsArray($decoded, $path);
            self::$schemas[$path] = $decoded;
        }

        return self::$schemas[$path];
    }

    /** @return array<string, mixed> */
    private static function definitions(string $document, int $number): array
    {
        $definitions = self::decode(Schemas::path($document, $number))['definitions'] ?? null;
        self::assertIsArray($definitions, $document.'-'.$number);

        return $definitions;
    }

    /**
     * @param array<string, mixed> $definitions
     *
     * @return array<string, mixed>
     */
    private static function properties(array $definitions, string $name): array
    {
        $definition = $definitions[$name] ?? null;
        self::assertIsArray($definition, $name);
        $properties = $definition['properties'] ?? null;
        self::assertIsArray($properties, $name);

        return $properties;
    }

    /**
     * The definitions that the roots reach through `#/definitions/` references, roots included.
     *
     * @param array<string, mixed> $definitions
     * @param list<string>         $roots
     *
     * @return list<string> sorted
     */
    private static function closure(array $definitions, array $roots): array
    {
        $seen = [];
        $todo = $roots;
        while ($todo !== []) {
            $name = array_pop($todo);
            if (isset($seen[$name])) {
                continue;
            }
            self::assertArrayHasKey($name, $definitions, 'a reference to a missing definition');
            $seen[$name] = true;
            self::walk($definitions[$name], '', static function (array $node) use (&$todo): array {
                $ref = $node['$ref'] ?? null;
                if (\is_string($ref) && strpos($ref, '#/definitions/') === 0) {
                    $todo[] = substr($ref, \strlen('#/definitions/'));
                }

                return [];
            });
        }
        $names = array_map('strval', array_keys($seen));
        sort($names);

        return $names;
    }

    /**
     * Applies $visit to every object node and collects what it returns.
     *
     * @param mixed                                                 $node
     * @param callable(array<mixed, mixed>, string): list<string> $visit
     *
     * @return list<string>
     */
    private static function walk($node, string $at, callable $visit): array
    {
        if (!\is_array($node)) {
            return [];
        }
        $found = self::isListArray($node) ? [] : $visit($node, $at);
        foreach ($node as $key => $child) {
            $found = array_merge($found, self::walk($child, $at.'/'.$key, $visit));
        }

        return $found;
    }

    /**
     * @param mixed $value
     *
     * @return mixed the value with every object's keys sorted
     */
    private static function sorted($value)
    {
        if (!\is_array($value)) {
            return $value;
        }
        $value = array_map([self::class, 'sorted'], $value);
        if (!self::isListArray($value)) {
            ksort($value);
        }

        return $value;
    }

    /** @param array<mixed, mixed> $value */
    private static function isListArray(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, \count($value) - 1);
    }

    /** Whether the value is a whole number over a power of two. */
    private static function isDyadic(float $value): bool
    {
        for ($denominator = 1; $denominator <= 1 << 30; $denominator <<= 1) {
            $scaled = $value * $denominator;
            if (abs($scaled - round($scaled)) < 1e-9 * max(1.0, abs($scaled))) {
                return true;
            }
        }

        return false;
    }
}
