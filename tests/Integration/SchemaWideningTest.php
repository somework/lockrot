<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Json\Schemas;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\SchemaWidening;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The widening check SchemaEvolutionTest holds every released schema to, held to what it has to
 * catch: deliberately narrowed copies of the current schemas, each of which a document an older
 * release wrote could fail, and widened copies, which none could.
 *
 * The first twelve narrowings are the ones a review found the recorded documents alone let through,
 * because no recording happens to carry the value or shape they take away.
 */
final class SchemaWideningTest extends TestCase
{
    private const OLDEST = __DIR__.'/../fixtures/schema-evolution/schemas/0.9.0/';

    /** @return iterable<string, array{string}> */
    public static function schemaDocuments(): iterable
    {
        foreach ([Schemas::REPORT, Schemas::EXPLAIN, Schemas::BASELINE, Schemas::CONFIG] as $document) {
            yield $document => [$document];
        }
    }

    /**
     * @dataProvider schemaDocuments
     */
    #[DataProvider('schemaDocuments')]
    public function testASchemaOnlyWidensOnItself(string $document): void
    {
        self::assertSame([], SchemaWidening::narrowings(self::current($document), self::current($document)));
    }

    /** @return iterable<string, array{string, string, string}> the document, the narrowing, and what the check has to say about it */
    public static function narrowings(): iterable
    {
        yield 'report verdict loses unknown' => [Schemas::REPORT, 'verdict-unknown', '/properties/verdict: no longer accepts "unknown"'];
        yield 'baseline standing only known' => [Schemas::REPORT, 'standing-known', '/properties/status: no longer accepts "new"'];
        yield 's10 check and reason lose values' => [Schemas::REPORT, 's10-enums', '/properties/check: no longer accepts "repository_activity"'];
        yield 's8 floor_source loses target' => [Schemas::REPORT, 'floor-source', '/properties/floor_source: no longer accepts "target"'];
        yield 'run made required' => [Schemas::REPORT, 'run-required', '#: made required run'];
        yield 'baseline stale typed integer' => [Schemas::REPORT, 'stale-integer', '/properties/stale/items: no longer accepts type string'];
        yield 'finding note only null' => [Schemas::REPORT, 'note-null', '/properties/note: no longer accepts type string'];
        yield 'finding replacement only null' => [Schemas::REPORT, 'replacement-null', '/properties/replacement/oneOf/0: no longer accepts type string'];
        yield 'finding chain never empty' => [Schemas::REPORT, 'chain-min', '/properties/chain: minItems raised to 1'];
        yield 'explain verdict loses four' => [Schemas::EXPLAIN, 'explain-verdict', '/properties/verdict: no longer accepts "old-promise"'];
        yield 'explain priority loses three' => [Schemas::EXPLAIN, 'explain-priority', '/properties/priority: no longer accepts "medium"'];
        yield 'explain from_cache only true' => [Schemas::EXPLAIN, 'from-cache', '/properties/from_cache: no longer accepts false'];
        yield 'finding evidence no longer listed' => [Schemas::REPORT, 'evidence-dropped', '/properties/evidence: no longer listed'];
        yield 'counts closed' => [Schemas::REPORT, 'counts-closed', '/properties/counts: closed, additionalProperties false'];
        yield 'package name pattern changed' => [Schemas::REPORT, 'package-pattern', '/properties/package: pattern "^[a-z]+/[a-z]+$"'];
        yield 'notes with an uncompared keyword' => [Schemas::REPORT, 'notes-unique', '/properties/notes: uniqueItems is a keyword this check does not compare'];
        yield 'a new signal branch that narrows S1' => [Schemas::REPORT, 's1-branch', '/properties/replacement: no longer accepts type null'];
        yield 'baseline findings lose stale' => [Schemas::BASELINE, 'baseline-verdict', '#/properties/findings/additionalProperties/properties/verdict: no longer accepts "stale"'];
        yield 'baseline schema maximum lowered' => [Schemas::BASELINE, 'baseline-maximum', '#/properties/lockrot/properties/schema: maximum lowered to 0'];
        yield 'config budget maximum lowered' => [Schemas::CONFIG, 'budget-maximum', '#/properties/install-time-budget: maximum lowered to 60'];
    }

    /**
     * @dataProvider narrowings
     */
    #[DataProvider('narrowings')]
    public function testEachNarrowingIsFound(string $document, string $narrowing, string $expected): void
    {
        $problems = SchemaWidening::narrowings(self::current($document), self::narrowed($document, $narrowing));

        self::assertNotSame([], $problems);
        $found = array_filter($problems, static fn (string $problem): bool => strpos($problem, $expected) !== false);
        self::assertNotSame([], $found, 'expected "'.$expected.'" among '.json_encode($problems, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
    }

    /** @return iterable<string, array{string, string}> */
    public static function widenings(): iterable
    {
        yield 'a field added' => [Schemas::REPORT, 'field-added'];
        yield 'a verdict added' => [Schemas::REPORT, 'verdict-added'];
        yield 'null allowed' => [Schemas::REPORT, 'evidence-nullable'];
        yield 'a field no longer required' => [Schemas::REPORT, 'note-optional'];
        yield 'a minimum lowered' => [Schemas::REPORT, 'flagged-minimum'];
        yield 'a signal added' => [Schemas::REPORT, 's11'];
        yield 'a minItems dropped' => [Schemas::REPORT, 's10-min-items'];
        yield 'a type list as oneOf' => [Schemas::REPORT, 'note-oneof'];
        yield 'a pattern dropped' => [Schemas::BASELINE, 'first-seen-any'];
        yield 'a config value added' => [Schemas::CONFIG, 'format-added'];
    }

    /**
     * @dataProvider widenings
     */
    #[DataProvider('widenings')]
    public function testEachWideningPasses(string $document, string $widening): void
    {
        $widened = self::narrowed($document, $widening);
        self::assertNotSame(self::current($document), $widened, 'the copy differs');

        self::assertSame([], SchemaWidening::narrowings(self::current($document), $widened));
    }

    /**
     * The check is not blind to the difference between releases: read the other way round — the
     * current schema as the older one — it finds what 0.9.0's schemas cannot accept, which is why
     * a document a newer lockrot writes is not promised to validate against an older copy.
     */
    public function testTheForwardDirectionIsNarrowing(): void
    {
        $report = SchemaWidening::narrowings(self::current(Schemas::REPORT), self::decode(self::OLDEST.'lockrot-report.schema.json'));
        $explain = SchemaWidening::narrowings(self::current(Schemas::EXPLAIN), self::decode(self::OLDEST.'lockrot-explain.schema.json'));

        self::assertContains('#/properties/finding/properties/signals/items/properties/id: no longer accepts "S10"', $explain);
        self::assertContains('#/properties/finding/properties/chain: minItems raised to 1', $explain);
        self::assertContains('#/properties/run: no longer listed', $report);
    }

    /** @return array<mixed, mixed> */
    private static function current(string $document): array
    {
        return self::decode(Schemas::path($document));
    }

    /** @return array<mixed, mixed> */
    private static function decode(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($decoded, $path);

        return $decoded;
    }

    /**
     * The current schema with one change applied.
     *
     * @return array<mixed, mixed>
     */
    private static function narrowed(string $document, string $change): array
    {
        $s = self::current($document);
        $finding = ['definitions', 'finding', 'properties'];
        $s10Entry = ['definitions', 's10', 'properties', 'unchecked', 'items', 'properties'];
        switch ($change) {
            case 'verdict-unknown':
                return self::with($s, ['definitions', 'verdict', 'enum'], ['abandoned', 'silent', 'pinned', 'left-behind', 'old-promise', 'stale', 'finished', 'ok']);
            case 'standing-known':
                return self::with($s, ['definitions', 'baselineStanding', 'oneOf', 0, 'properties', 'status', 'enum'], ['known']);
            case 's10-enums':
                $s = self::with($s, array_merge($s10Entry, ['check', 'enum']), ['release_dates']);

                return self::with($s, array_merge($s10Entry, ['reason', 'enum']), ['undated_releases']);
            case 'floor-source':
                return self::with($s, ['definitions', 's8', 'properties', 'floor_source', 'enum'], ['project', null]);
            case 'run-required':
                return self::appended($s, ['required'], 'run');
            case 'stale-integer':
                return self::with($s, ['definitions', 'baselineComparison', 'properties', 'stale', 'items'], ['type' => 'integer']);
            case 'note-null':
                return self::with($s, array_merge($finding, ['note', 'type']), 'null');
            case 'replacement-null':
                return self::with($s, array_merge($finding, ['replacement']), ['type' => 'null']);
            case 'chain-min':
                return self::with($s, array_merge($finding, ['chain', 'minItems']), 1);
            case 'explain-verdict':
                return self::with($s, ['definitions', 'verdict', 'enum'], ['abandoned', 'silent', 'pinned', 'left-behind']);
            case 'explain-priority':
                return self::with($s, ['definitions', 'priority', 'enum'], ['critical', 'high']);
            case 'from-cache':
                return self::with($s, ['definitions', 'activity', 'properties', 'from_cache', 'enum'], [true]);
            case 'evidence-dropped':
                return self::without($s, array_merge($finding, ['evidence']));
            case 'counts-closed':
                return self::with($s, ['properties', 'counts', 'additionalProperties'], false);
            case 'package-pattern':
                return self::with($s, ['definitions', 'packageName', 'pattern'], '^[a-z]+/[a-z]+$');
            case 'notes-unique':
                return self::with($s, ['properties', 'notes', 'uniqueItems'], true);
            case 's1-branch':
                // The S1 data now demands a replacement: an older S1 with a null one fits no branch.
                return self::with($s, ['definitions', 's1', 'properties', 'replacement', 'type'], 'string');
            case 'baseline-verdict':
                return self::with($s, ['properties', 'findings', 'additionalProperties', 'properties', 'verdict', 'enum'], ['abandoned', 'silent', 'pinned', 'left-behind', 'old-promise']);
            case 'baseline-maximum':
                return self::with($s, ['properties', 'lockrot', 'properties', 'schema', 'maximum'], 0);
            case 'budget-maximum':
                return self::with($s, ['properties', 'install-time-budget', 'maximum'], 60);
            case 'field-added':
                return self::with($s, array_merge($finding, ['x_future']), ['type' => 'string']);
            case 'verdict-added':
                return self::appended($s, ['definitions', 'verdict', 'enum'], 'forked');
            case 'evidence-nullable':
                return self::with($s, array_merge($finding, ['evidence', 'type']), ['string', 'null']);
            case 'note-optional':
                return self::with($s, ['definitions', 'finding', 'required'], array_values(array_filter(JsonPath::arrayAt($s, ['definitions', 'finding', 'required']), static fn ($name): bool => $name !== 'note')));
            case 'flagged-minimum':
                return self::with($s, ['properties', 'exposure', 'items', 'properties', 'flagged', 'minimum'], 0);
            case 's11':
                $s = self::appended($s, ['definitions', 'signalId', 'enum'], 'S11');
                $s = self::appended($s, ['definitions', 'signal', 'properties', 'id', 'enum'], 'S11');

                return self::appended($s, ['definitions', 'signal', 'anyOf'], ['properties' => ['id' => ['enum' => ['S11']], 'data' => ['type' => 'object']]]);
            case 's10-min-items':
                return self::without($s, ['definitions', 's10', 'properties', 'unchecked', 'minItems']);
            case 'note-oneof':
                return self::with($s, array_merge($finding, ['note']), ['oneOf' => [['type' => 'string'], ['type' => 'null']]]);
            case 'first-seen-any':
                return self::without($s, ['properties', 'findings', 'additionalProperties', 'properties', 'first_seen', 'pattern']);
            case 'format-added':
                return self::appended($s, ['properties', 'format', 'enum'], 'csv');
        }

        self::fail('no change named '.$change);
    }

    /**
     * @param array<mixed, mixed> $node
     * @param list<int|string>    $path
     * @param mixed               $value
     *
     * @return array<mixed, mixed>
     */
    private static function with(array $node, array $path, $value): array
    {
        $key = array_shift($path);
        self::assertNotNull($key, 'a path');
        if ($path === []) {
            $node[$key] = $value;

            return $node;
        }
        self::assertArrayHasKey($key, $node, 'the change names what the schema has');
        $node[$key] = self::with(JsonPath::arrayAt($node, [$key]), $path, $value);

        return $node;
    }

    /**
     * @param array<mixed, mixed> $node
     * @param list<int|string>    $path
     * @param mixed               $value
     *
     * @return array<mixed, mixed>
     */
    private static function appended(array $node, array $path, $value): array
    {
        return self::with($node, $path, array_merge(JsonPath::arrayAt($node, $path), [$value]));
    }

    /**
     * @param array<mixed, mixed> $node
     * @param list<int|string>    $path
     *
     * @return array<mixed, mixed>
     */
    private static function without(array $node, array $path): array
    {
        $key = array_pop($path);
        self::assertNotNull($key, 'a path');
        $parent = $path === [] ? $node : JsonPath::arrayAt($node, $path);
        self::assertArrayHasKey($key, $parent, 'the change names what the schema has');
        unset($parent[$key]);

        return $path === [] ? $parent : self::with($node, $path, $parent);
    }
}
