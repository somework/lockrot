<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Json\KnownValues;
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
        yield 's8 floor_source loses target' => [Schemas::REPORT, 'floor-source', '/properties/floor_source/oneOf/0: no longer accepts "target"'];
        yield 'a known signal id dropped' => [Schemas::REPORT, 'signal-id-dropped', '/properties/id: no longer accepts "S10"'];
        yield 'known values on a free string' => [Schemas::EXPLAIN, 'forge-known', '/properties/forge: enum ["github"] where any value was accepted'];
        yield 'config format loses html' => [Schemas::CONFIG, 'format-dropped', '#/properties/format: no longer accepts "html"'];
        yield 'an unknown signal id left untyped by the older schema' => [Schemas::REPORT, 'not-list-drifted', '/anyOf/10/properties/data: made required unchecked, blocks'];
        yield 'a typed signal narrowed in three places' => [Schemas::REPORT, 's1-three-places', '/anyOf/0/properties/data/properties/replacement: no longer accepts type null'];
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
        yield 's6 reason loses no_stable_release' => [Schemas::REPORT, 's6-reason-dropped', '/properties/data/properties/reason: no longer accepts "no_stable_release"'];
        yield 'finding from_composer_repository made required' => [Schemas::REPORT, 'provenance-required', 'made required from_composer_repository'];
        yield 'explain finding from_composer_repository made required' => [Schemas::EXPLAIN, 'provenance-required', 'made required from_composer_repository'];
        yield 'finding libyears_unmeasured made required' => [Schemas::REPORT, 'unmeasured-required', 'made required libyears_unmeasured'];
        yield 'explain finding libyears_unmeasured made required' => [Schemas::EXPLAIN, 'unmeasured-required', 'made required libyears_unmeasured'];
        yield 'finding libyears_unmeasured loses null' => [Schemas::REPORT, 'unmeasured-not-null', '/properties/libyears_unmeasured/oneOf/1: no longer accepts null'];
        yield 'finding libyears_unmeasured loses a reason' => [Schemas::REPORT, 'unmeasured-reason-dropped', '/properties/libyears_unmeasured/oneOf/0: no longer accepts "metadata_unavailable"'];
        yield 'libyears unmeasured closed' => [Schemas::REPORT, 'unmeasured-block-closed', '/properties/unmeasured: closed, additionalProperties false'];
        yield 'finding priority_basis made required' => [Schemas::REPORT, 'basis-required', 'made required priority_basis'];
        yield 'explain finding priority_basis made required' => [Schemas::EXPLAIN, 'basis-required', 'made required priority_basis'];
        yield 'finding no_fix_expected made required' => [Schemas::REPORT, 'no-fix-required', 'made required no_fix_expected'];
        yield 'explain finding no_fix_expected made required' => [Schemas::EXPLAIN, 'no-fix-required', 'made required no_fix_expected'];
        yield 'finding no_fix_expected loses null' => [Schemas::REPORT, 'no-fix-not-null', '/properties/no_fix_expected/oneOf/1: no longer accepts null'];
        yield 'explain finding no_fix_expected loses null' => [Schemas::EXPLAIN, 'no-fix-not-null', '/properties/no_fix_expected/oneOf/1: no longer accepts null'];
        yield 'a priority step loses a reason' => [Schemas::REPORT, 'step-reason-dropped', '/properties/reason: no longer accepts "no_fix_expected"'];
        yield 'explain priority step loses a reason' => [Schemas::EXPLAIN, 'step-reason-dropped', '/properties/reason: no longer accepts "no_fix_expected"'];
        yield 'a no-fix advisory loses a reason' => [Schemas::REPORT, 'no-fix-reason-dropped', '/properties/reason: no longer accepts "releases_unknown"'];
        yield 'explain no-fix advisory loses a reason' => [Schemas::EXPLAIN, 'no-fix-reason-dropped', '/properties/reason: no longer accepts "releases_unknown"'];
        yield 's9 releases_read made required' => [Schemas::REPORT, 'releases-read-required', 'made required releases_read'];
    }

    /**
     * @dataProvider narrowings
     */
    #[DataProvider('narrowings')]
    public function testEachNarrowingIsFound(string $document, string $narrowing, string $expected): void
    {
        $problems = $narrowing === 'not-list-drifted'
            ? SchemaWidening::narrowings(self::narrowed($document, $narrowing), self::current($document))
            : SchemaWidening::narrowings(self::current($document), self::narrowed($document, $narrowing));

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
        yield 'a signal id added to the explanation' => [Schemas::EXPLAIN, 's11-explain'];
        yield 'an S10 reason added' => [Schemas::REPORT, 'reason-added'];
        yield 'a minItems dropped' => [Schemas::REPORT, 's10-min-items'];
        yield 'a type list as oneOf' => [Schemas::REPORT, 'note-oneof'];
        yield 'a pattern dropped' => [Schemas::BASELINE, 'first-seen-any'];
        yield 'a config value added' => [Schemas::CONFIG, 'format-added'];
        yield 'a libyears reason added' => [Schemas::REPORT, 'unmeasured-reason-added'];
        yield 'a priority step reason added' => [Schemas::REPORT, 'step-reason-added'];
        yield 'a no-fix reason added' => [Schemas::EXPLAIN, 'no-fix-reason-added'];
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

    /** @return iterable<string, array{array<mixed, mixed>, array<mixed, mixed>, list<string>}> an older node, a newer one, and the narrowings between them */
    public static function valuesFacingAPattern(): iterable
    {
        $date = ['type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$'];
        yield 'a value the quantified pattern takes' => [['type' => 'string', 'enum' => ['2024-01-02']], $date, []];
        yield 'a value the quantified pattern refuses' => [['type' => 'string', 'enum' => ['2024-1-2']], $date, ['#: no longer accepts "2024-1-2"']];
        yield 'a pattern with an unbalanced brace' => [['type' => 'string', 'enum' => ['abc']], ['type' => 'string', 'pattern' => '^[^}]+$'], []];
        // The one pattern the check cannot delimit is one it cannot decide, which counts as refusing.
        yield 'a pattern holding the delimiter byte' => [['type' => 'string', 'enum' => ['abc']], ['type' => 'string', 'pattern' => "^[a-c\x01]+$"], ['#: no longer accepts "abc"']];
    }

    /**
     * An older value is checked against the newer pattern as the pattern is written, braces and all:
     * a `{4}` quantifier or a lone `}` in a character class must not turn into a false narrowing.
     *
     * @param array<mixed, mixed> $older
     * @param array<mixed, mixed> $newer
     * @param list<string>        $expected
     *
     * @dataProvider valuesFacingAPattern
     */
    #[DataProvider('valuesFacingAPattern')]
    public function testAnOlderValueIsReadAgainstTheNewerPatternAsWritten(array $older, array $newer, array $expected): void
    {
        self::assertSame($expected, SchemaWidening::narrowings($older, $newer));
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

    /**
     * The branch for unknown signal ids admits none of the ids its schema knows, so the check skips
     * it on the older side and never compares an older signal with it on the newer side: adding S11
     * reads as a widening above, and a narrowed typed branch is reported as that branch's problem.
     * Read against the schemas released before the branch existed, the current one only widens.
     */
    public function testTheBranchForUnknownIdsIsNeitherANarrowingNorAHidingPlace(): void
    {
        foreach (['0.9.0', '0.12.0'] as $version) {
            $released = self::decode(__DIR__.'/../fixtures/schema-evolution/schemas/'.$version.'/lockrot-report.schema.json');
            self::assertSame([], SchemaWidening::narrowings($released, self::current(Schemas::REPORT)), $version);
        }

        $problems = SchemaWidening::narrowings(self::current(Schemas::REPORT), self::narrowed(Schemas::REPORT, 's1-three-places'));
        self::assertSame([], array_values(array_filter($problems, static fn (string $problem): bool => strpos($problem, 'not is a keyword') !== false)), implode("\n", $problems));
    }

    /**
     * `from_composer_repository` joined the finding in 0.13.0 as an optional boolean: a schema
     * without it is what 0.12 documents were written against, and the current one only widens it.
     *
     * @dataProvider findingSchemas
     */
    #[DataProvider('findingSchemas')]
    public function testTheFindingGainingFromComposerRepositoryIsAWidening(string $document): void
    {
        $current = self::current($document);
        $before = self::without($current, ['definitions', 'finding', 'properties', 'from_composer_repository']);

        self::assertNotContains('from_composer_repository', JsonPath::arrayAt($current, ['definitions', 'finding', 'required']));
        self::assertSame(['type' => 'boolean'], array_diff_key(JsonPath::arrayAt($current, ['definitions', 'finding', 'properties', 'from_composer_repository']), ['description' => true]));
        self::assertSame([], SchemaWidening::narrowings($before, $current));
    }

    /**
     * `libyears_unmeasured` joined the finding in 0.13.0 as an optional open code, and the block it
     * counts under gained a type for the keys it does not list: from the schemas without either, the
     * current ones only widen.
     *
     * @dataProvider findingSchemas
     */
    #[DataProvider('findingSchemas')]
    public function testTheFindingGainingLibyearsUnmeasuredIsAWidening(string $document): void
    {
        $current = self::current($document);
        $before = self::without($current, ['definitions', 'finding', 'properties', 'libyears_unmeasured']);
        if ($document === Schemas::REPORT) {
            $before = self::without($before, ['properties', 'libyears', 'properties', 'unmeasured', 'additionalProperties']);
            self::assertSame(['type' => 'integer', 'minimum' => 0], JsonPath::arrayAt($current, ['properties', 'libyears', 'properties', 'unmeasured', 'additionalProperties']));
        }

        self::assertNotContains('libyears_unmeasured', JsonPath::arrayAt($current, ['definitions', 'finding', 'required']));
        self::assertSame([], SchemaWidening::narrowings($before, $current));
    }

    /**
     * `priority_basis` and `no_fix_expected` joined the finding in 0.13.0, and S9's data gained
     * `releases_read`, all optional: from the schemas without them, the current ones only widen.
     *
     * @dataProvider findingSchemas
     */
    #[DataProvider('findingSchemas')]
    public function testTheFindingGainingItsPriorityBasisAndNoFixListIsAWidening(string $document): void
    {
        $current = self::current($document);
        $before = self::without($current, ['definitions', 'finding', 'properties', 'priority_basis']);
        $before = self::without($before, ['definitions', 'finding', 'properties', 'no_fix_expected']);
        if ($document === Schemas::REPORT) {
            $before = self::without($before, ['definitions', 's9', 'properties', 'releases_read']);
            self::assertNotContains('releases_read', JsonPath::arrayAt($current, ['definitions', 's9', 'required']));
        }

        foreach (['priority_basis', 'no_fix_expected'] as $key) {
            self::assertNotContains($key, JsonPath::arrayAt($current, ['definitions', 'finding', 'required']));
        }
        self::assertSame([], SchemaWidening::narrowings($before, $current));
    }

    /** @return iterable<string, array{string}> */
    public static function findingSchemas(): iterable
    {
        yield Schemas::REPORT => [Schemas::REPORT];
        yield Schemas::EXPLAIN => [Schemas::EXPLAIN];
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
                $s = self::with($s, array_merge($s10Entry, ['check', KnownValues::KEYWORD]), ['release_dates']);

                return self::with($s, array_merge($s10Entry, ['reason', KnownValues::KEYWORD]), ['undated_releases']);
            case 'floor-source':
                return self::with($s, ['definitions', 's8', 'properties', 'floor_source', 'oneOf', 0, KnownValues::KEYWORD], ['project']);
            case 'signal-id-dropped':
                return self::with($s, ['definitions', 'signalId', KnownValues::KEYWORD], ['S1', 'S2', 'S3', 'S4', 'S5', 'S6', 'S7', 'S8', 'S9']);
            case 'forge-known':
                return self::with($s, ['definitions', 'activity', 'properties', 'forge', KnownValues::KEYWORD], ['github']);
            case 'format-dropped':
                return self::with($s, ['properties', 'format', KnownValues::KEYWORD], ['table', 'json', 'github', 'sarif', 'gitlab', 'markdown']);
            case 'not-list-drifted':
                // Read the other way round below: the older schema's last branch leaves S10 out of its
                // not-list, so it takes an S10 with any data, which the current S10 branch refuses.
                return self::with($s, ['definitions', 'signal', 'anyOf', 10, 'not', 'properties', 'id', 'enum'], ['S1', 'S2', 'S3', 'S4', 'S5', 'S6', 'S7', 'S8', 'S9']);
            case 's1-three-places':
                // Three places in S1's own branch: the signal, its data and the replacement in it.
                // Compared with the branch for unknown ids an older S1 differs in two (the `not`, and
                // the replacement that branch does not list), so the fewest-places rule alone would
                // report that branch's problems instead of these.
                $s = self::with($s, ['definitions', 's1', 'properties', 'replacement', 'type'], 'string');
                $s = self::appended($s, ['definitions', 's1', 'required'], 'since');

                return self::with($s, ['definitions', 'signal', 'anyOf', 0, 'required'], ['since']);
            case 'provenance-required':
                return self::appended($s, ['definitions', 'finding', 'required'], 'from_composer_repository');
            case 'unmeasured-required':
                return self::appended($s, ['definitions', 'finding', 'required'], 'libyears_unmeasured');
            case 'unmeasured-not-null':
                return self::with($s, array_merge($finding, ['libyears_unmeasured']), JsonPath::arrayAt($s, array_merge($finding, ['libyears_unmeasured', 'oneOf', 0])));
            case 'unmeasured-reason-dropped':
                return self::with($s, array_merge($finding, ['libyears_unmeasured', 'oneOf', 0, KnownValues::KEYWORD]), ['branch_snapshot', 'no_stable_release_date', 'not_from_composer_repository']);
            case 'unmeasured-reason-added':
                return self::appended($s, array_merge($finding, ['libyears_unmeasured', 'oneOf', 0, KnownValues::KEYWORD]), 'installed_undated');
            case 'unmeasured-block-closed':
                return self::with($s, ['properties', 'libyears', 'properties', 'unmeasured', 'additionalProperties'], false);
            case 'basis-required':
                return self::appended($s, ['definitions', 'finding', 'required'], 'priority_basis');
            case 'no-fix-required':
                return self::appended($s, ['definitions', 'finding', 'required'], 'no_fix_expected');
            case 'no-fix-not-null':
                return self::with($s, array_merge($finding, ['no_fix_expected']), JsonPath::arrayAt($s, array_merge($finding, ['no_fix_expected', 'oneOf', 0])));
            case 'step-reason-dropped':
                return self::with($s, ['definitions', 'priorityStep', 'properties', 'reason', KnownValues::KEYWORD], ['transitive', 'unreached', 'dev']);
            case 'step-reason-added':
                return self::appended($s, ['definitions', 'priorityStep', 'properties', 'reason', KnownValues::KEYWORD], 'vendored');
            case 'no-fix-reason-dropped':
                return self::with($s, ['definitions', 'noFixAdvisory', 'properties', 'reason', KnownValues::KEYWORD], ['not_on_installed_branch', 'affected_range_unknown', 'no_release_fixes']);
            case 'no-fix-reason-added':
                return self::appended($s, ['definitions', 'noFixAdvisory', 'properties', 'reason', KnownValues::KEYWORD], 'withdrawn_upstream');
            case 'releases-read-required':
                return self::appended($s, ['definitions', 's9', 'required'], 'releases_read');
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
            case 's6-reason-dropped':
                // An open set, read strictly: a known reason dropped from x-known-values narrows it.
                return self::with($s, ['definitions', 's6', 'properties', 'reason', KnownValues::KEYWORD], ['branch_snapshot']);
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
                // Known to the schema, typed by a branch of its own before the one for unknown ids, and
                // no longer unknown to that one.
                $s = self::appended($s, ['definitions', 'signalId', KnownValues::KEYWORD], 'S11');
                $anyOf = JsonPath::arrayAt($s, ['definitions', 'signal', 'anyOf']);
                $unknown = array_pop($anyOf);
                $anyOf[] = ['properties' => ['id' => ['enum' => ['S11']], 'data' => ['type' => 'object']]];
                $anyOf[] = $unknown;
                $s = self::with($s, ['definitions', 'signal', 'anyOf'], $anyOf);

                return self::appended($s, ['definitions', 'signal', 'anyOf', \count($anyOf) - 1, 'not', 'properties', 'id', 'enum'], 'S11');
            case 's11-explain':
                return self::appended($s, ['definitions', 'signalId', KnownValues::KEYWORD], 'S11');
            case 's10-min-items':
                return self::without($s, ['definitions', 's10', 'properties', 'unchecked', 'minItems']);
            case 'note-oneof':
                return self::with($s, array_merge($finding, ['note']), ['oneOf' => [['type' => 'string'], ['type' => 'null']]]);
            case 'first-seen-any':
                return self::without($s, ['properties', 'findings', 'additionalProperties', 'properties', 'first_seen', 'pattern']);
            case 'format-added':
                return self::appended($s, ['properties', 'format', KnownValues::KEYWORD], 'csv');
            case 'reason-added':
                return self::appended($s, array_merge($s10Entry, ['reason', KnownValues::KEYWORD]), 'quota_exhausted');
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
