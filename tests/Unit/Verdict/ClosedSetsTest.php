<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Lockrot\Analyzer\Libyears;
use Lockrot\Analyzer\RunSettings;
use Lockrot\Config\LockrotConfig;
use Lockrot\Json\KnownValues;
use Lockrot\Json\Schemas;
use Lockrot\Output\JsonFormatter;
use Lockrot\Signal\PhpFloor;
use Lockrot\Signal\Rule\NotCheckedRule;
use Lockrot\Signal\Rule\PinnedRule;
use Lockrot\Tests\Support\ClosedSets;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\Priority;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\TestCase;

/**
 * The closed sets docs/compatibility.md freezes for 1.x, and the order they come in.
 *
 * Verdicts, priorities, signal levels and a finding's standing against the baseline never grow or
 * reorder under one schema number: `--fail-on` reads "at or above" off the order, the baseline reads
 * "worsened" off it, and the report is sorted by it. The other tests that touch these lists go
 * through the class constants, so they would follow a changed value instead of catching it; the
 * lists here are spelled out as literals on purpose. The published schemas are held to the same
 * sets in the same order, because a consumer validating against them reads the order from there.
 *
 * The open sets are held the other way round: an open string in every schema, with the values
 * lockrot writes listed in `x-known-values` and nowhere as an enum, and each named on the pages that
 * say which sets grow. The last test guards the names docs/compatibility.md reserves for extensions.
 */
final class ClosedSetsTest extends TestCase
{
    private const VERDICTS = ['abandoned', 'silent', 'pinned', 'left-behind', 'old-promise', 'stale', 'unknown', 'finished', 'ok'];
    private const FLAGGED = ['abandoned', 'silent', 'pinned', 'left-behind', 'old-promise', 'stale'];
    private const PRIORITIES = ['critical', 'high', 'medium', 'low', 'none'];
    private const LEVELS = ['info', 'warn', 'high'];
    private const STANDINGS = ['known', 'new', 'worsened'];
    /** A signal id: lockrot's own `S<n>`, or a `<vendor>:<name>` one that does not come from lockrot. */
    private const SIGNAL_ID = '^(S[1-9][0-9]*|[a-z0-9][a-z0-9_.-]*:[a-z0-9][a-z0-9_.-]*)$';
    /** A format name: lockrot's own, or a `<vendor>:<name>` one. */
    private const FORMAT = '^([a-z][a-z0-9-]*|[a-z0-9][a-z0-9_.-]*:[a-z0-9][a-z0-9_.-]*)$';
    /** An S10 check or reason, an S6 reason, an S8 floor source, a branch's php_blocked_by and misses_*_php, a finding's libyears_unmeasured: a lower-case word. */
    private const WORD = '^[a-z][a-z0-9_]*$';
    private const ROOT = __DIR__.'/../../../';

    public function testTheVerdictsAreFrozenInTheirOrder(): void
    {
        self::assertSame(self::VERDICTS, Verdict::all());
    }

    /** Covers every neighbour, `left-behind` between `pinned` and `old-promise` included. */
    public function testSeverityFallsStrictlyDownTheOrderAndOnlyFinishedAndOkTie(): void
    {
        $verdicts = Verdict::all();
        $last = \count($verdicts) - 1;
        for ($i = 0; $i < $last - 1; ++$i) {
            self::assertGreaterThan(
                Verdict::severity($verdicts[$i + 1]),
                Verdict::severity($verdicts[$i]),
                $verdicts[$i].' is more severe than '.$verdicts[$i + 1]
            );
        }
        self::assertSame(Verdict::severity('finished'), Verdict::severity('ok'), 'finished and ok share the lowest severity');
    }

    public function testTheFlaggedVerdictsAreTheFirstSix(): void
    {
        self::assertSame(self::FLAGGED, array_values(array_filter(Verdict::all(), [Verdict::class, 'flagged'])));
        self::assertSame(self::FLAGGED, (new RunSettings(null, null, null, null, null, null))->toArray()['flagged_verdicts']);
    }

    public function testThePrioritiesAreFrozenInTheirOrder(): void
    {
        self::assertSame(self::PRIORITIES, Priority::all());
        $priorities = Priority::all();
        for ($i = 0, $last = \count($priorities) - 1; $i < $last; ++$i) {
            self::assertGreaterThan(Priority::rank($priorities[$i + 1]), Priority::rank($priorities[$i]), $priorities[$i]);
        }
    }

    public function testTheLevelsAndStandingsAreFrozenInTheirOrder(): void
    {
        self::assertSame(self::LEVELS, ClosedSets::levels());
        self::assertSame(self::STANDINGS, ClosedSets::standings());
    }

    public function testTheReportSchemaSpellsTheSameClosedSetsInTheSameOrder(): void
    {
        $schema = self::schema(Schemas::REPORT);

        self::assertSame(self::VERDICTS, JsonPath::arrayAt($schema, ['definitions', 'verdict', 'enum']));
        self::assertSame(self::PRIORITIES, JsonPath::arrayAt($schema, ['definitions', 'priority', 'enum']));
        self::assertSame(self::LEVELS, JsonPath::arrayAt($schema, ['definitions', 'level', 'enum']));
        self::assertSame(self::STANDINGS, JsonPath::arrayAt($schema, ['definitions', 'baselineStanding', 'oneOf', 0, 'properties', 'status', 'enum']));
        self::assertSame([JsonFormatter::SCHEMA], JsonPath::arrayAt($schema, ['definitions', 'envelope', 'properties', 'schema', 'enum']));
    }

    public function testTheExplainSchemaSpellsTheSameVerdictsPrioritiesAndLevels(): void
    {
        $schema = self::schema(Schemas::EXPLAIN);

        self::assertSame(self::VERDICTS, JsonPath::arrayAt($schema, ['definitions', 'verdict', 'enum']));
        self::assertSame(self::PRIORITIES, JsonPath::arrayAt($schema, ['definitions', 'priority', 'enum']));
        self::assertSame(self::LEVELS, JsonPath::arrayAt($schema, ['definitions', 'finding', 'properties', 'signals', 'items', 'properties', 'level', 'enum']));
    }

    public function testABaselineEntryHoldsExactlyTheFlaggedVerdicts(): void
    {
        self::assertSame(
            self::FLAGGED,
            JsonPath::arrayAt(self::schema(Schemas::BASELINE), ['properties', 'findings', 'additionalProperties', 'properties', 'verdict', 'enum'])
        );
    }

    /**
     * No closed set is described as open: nothing that spells one out carries `x-known-values`.
     */
    public function testNoClosedSetCarriesKnownValues(): void
    {
        $report = self::schema(Schemas::REPORT);
        $explain = self::schema(Schemas::EXPLAIN);
        $config = self::schema(Schemas::CONFIG);
        $closed = [
            JsonPath::arrayAt($report, ['definitions', 'verdict']),
            JsonPath::arrayAt($report, ['definitions', 'priority']),
            JsonPath::arrayAt($report, ['definitions', 'level']),
            JsonPath::arrayAt($report, ['definitions', 'baselineStanding', 'oneOf', 0, 'properties', 'status']),
            JsonPath::arrayAt($report, ['definitions', 'envelope', 'properties', 'schema']),
            JsonPath::arrayAt($explain, ['definitions', 'verdict']),
            JsonPath::arrayAt($explain, ['definitions', 'priority']),
            JsonPath::arrayAt($explain, ['definitions', 'finding', 'properties', 'signals', 'items', 'properties', 'level']),
            JsonPath::arrayAt($explain, ['properties', 'lockrot', 'properties', 'schema']),
            JsonPath::arrayAt(self::schema(Schemas::BASELINE), ['properties', 'findings', 'additionalProperties', 'properties', 'verdict']),
            JsonPath::arrayAt($config, ['properties', 'fail-on']),
            JsonPath::arrayAt($config, ['properties', 'install-time']),
        ];
        foreach ($closed as $i => $node) {
            self::assertArrayHasKey('enum', $node, 'closed set '.$i);
            self::assertArrayNotHasKey(KnownValues::KEYWORD, $node, 'closed set '.$i);
        }
    }

    /**
     * Signal ids are an open set — they grow in minor releases — but the ids lockrot ships are its
     * own `S<n>`, and the schemas list exactly those in `x-known-values`. The report schema names
     * them three times: `signalId`, which a signal's `id` and S10's `blocks` refer to, one typed
     * `anyOf` branch per id that types that signal's `data`, and the last branch, which takes every
     * id the schema does not list and says which those are with a `not` over the same list. A signal
     * added to the code and to one of the three would otherwise validate against the others only by
     * accident. The last branch carries no `type` and no `properties` of its own: the strict twin
     * closes a typed object that lists its properties, and closed inside the `not` it would let every
     * signal through, untyped.
     */
    public function testTheSignalIdsAreLockrotsOwnAndTheSchemasListThem(): void
    {
        $ids = ClosedSets::signalIds();
        self::assertNotEmpty($ids);
        foreach ($ids as $id) {
            self::assertMatchesRegularExpression('/^S[1-9][0-9]*$/', $id);
        }

        $report = self::schema(Schemas::REPORT);
        self::assertSame($ids, JsonPath::arrayAt($report, ['definitions', 'signalId', KnownValues::KEYWORD]));
        self::assertSame(['$ref' => '#/definitions/signalId'], JsonPath::arrayAt($report, ['definitions', 'signal', 'properties', 'id']));
        self::assertSame(['$ref' => '#/definitions/signalId'], JsonPath::arrayAt($report, ['definitions', 's10', 'properties', 'blocks', 'items']));
        self::assertSame(['$ref' => '#/definitions/signalId'], JsonPath::arrayAt($report, ['definitions', 's10', 'properties', 'unchecked', 'items', 'properties', 'blocks', 'items']));

        $anyOf = JsonPath::arrayAt($report, ['definitions', 'signal', 'anyOf']);
        $unknown = array_pop($anyOf);
        self::assertIsArray($unknown, 'the last branch');
        self::assertSame(['description', 'not'], array_keys($unknown), 'the last branch holds only its not');
        self::assertSame(['properties' => ['id' => ['enum' => $ids]]], $unknown['not']);

        $branches = [];
        foreach ($anyOf as $index => $branch) {
            self::assertIsArray($branch, 'anyOf branch '.$index);
            self::assertCount(1, JsonPath::arrayAt($branch, ['properties', 'id', 'enum']), 'anyOf branch '.$index.' names one signal');
            $id = JsonPath::stringAt($branch, ['properties', 'id', 'enum', 0]);
            $branches[] = $id;
            self::assertSame(
                '#/definitions/'.strtolower($id),
                JsonPath::stringAt($branch, ['properties', 'data', '$ref']),
                'anyOf branch '.$index.' types its own signal\'s data'
            );
        }
        self::assertSame($ids, $branches);

        $explain = self::schema(Schemas::EXPLAIN);
        self::assertSame(JsonPath::arrayAt($report, ['definitions', 'signalId']), JsonPath::arrayAt($explain, ['definitions', 'signalId']), 'the explain schema spells signalId as the report does');
        self::assertSame(['$ref' => '#/definitions/signalId'], JsonPath::arrayAt($explain, ['definitions', 'finding', 'properties', 'signals', 'items', 'properties', 'id']));
    }

    /**
     * Every open set, by document and JSON pointer: its node, its pattern, the values lockrot writes,
     * and the words docs/compatibility.md and docs/schema.md name it by. The one registry: a new set
     * is added here and nowhere else.
     *
     * @return array<string, array{array<mixed, mixed>, string, list<string>, string}>
     */
    private static function openSets(): array
    {
        $report = self::schema(Schemas::REPORT);
        $explain = self::schema(Schemas::EXPLAIN);
        $config = self::schema(Schemas::CONFIG);
        $s10Entry = ['definitions', 's10', 'properties', 'unchecked', 'items', 'properties'];
        $branchRow = ['definitions', 'metadata', 'properties', 'branches', 'items', 'properties'];

        return [
            'report #/definitions/signalId' => [JsonPath::arrayAt($report, ['definitions', 'signalId']), self::SIGNAL_ID, ClosedSets::signalIds(), 'signal ids'],
            'report #/definitions/s10/properties/unchecked/items/properties/check' => [JsonPath::arrayAt($report, array_merge($s10Entry, ['check'])), self::WORD, ['repository_activity', 'release_dates'], "S10's `check` and `reason`"],
            'report #/definitions/s10/properties/unchecked/items/properties/reason' => [
                JsonPath::arrayAt($report, array_merge($s10Entry, ['reason'])),
                self::WORD,
                [NotCheckedRule::NO_TOKEN, NotCheckedRule::RATE_BUDGET, NotCheckedRule::BUDGET, NotCheckedRule::RATE_LIMIT, NotCheckedRule::FETCH_FAILED, NotCheckedRule::OFFLINE, 'undated_releases'],
                "S10's `check` and `reason`",
            ],
            'report #/definitions/s6/properties/reason' => [JsonPath::arrayAt($report, ['definitions', 's6', 'properties', 'reason']), self::WORD, [PinnedRule::REASON_BRANCH_SNAPSHOT, PinnedRule::REASON_NO_STABLE_RELEASE], "S6's `reason`"],
            'report #/definitions/s8/properties/floor_source/oneOf/0' => [JsonPath::arrayAt($report, ['definitions', 's8', 'properties', 'floor_source', 'oneOf', 0]), self::WORD, [PhpFloor::PROJECT, PhpFloor::TARGET], "S8's `floor_source`"],
            'report #/definitions/finding/properties/libyears_unmeasured/oneOf/0' => [JsonPath::arrayAt($report, ['definitions', 'finding', 'properties', 'libyears_unmeasured', 'oneOf', 0]), self::WORD, Libyears::REASONS, "a finding's `libyears_unmeasured`"],
            'explain #/definitions/finding/properties/libyears_unmeasured/oneOf/0' => [JsonPath::arrayAt($explain, ['definitions', 'finding', 'properties', 'libyears_unmeasured', 'oneOf', 0]), self::WORD, Libyears::REASONS, "a finding's `libyears_unmeasured`"],
            'explain #/definitions/metadata/properties/branches/items/properties/php_blocked_by/oneOf/0' => [JsonPath::arrayAt($explain, array_merge($branchRow, ['php_blocked_by', 'oneOf', 0])), self::WORD, [PhpFloor::PROJECT, PhpFloor::TARGET], "the explanation's `php_blocked_by`"],
            'explain #/definitions/metadata/properties/branches/items/properties/misses_target_php/oneOf/0' => [JsonPath::arrayAt($explain, array_merge($branchRow, ['misses_target_php', 'oneOf', 0])), self::WORD, [PhpFloor::NEEDS_NEWER, PhpFloor::STOPS_BEFORE, PhpFloor::SKIPS, PhpFloor::UNSATISFIABLE], '`misses_target_php`'],
            'explain #/definitions/metadata/properties/branches/items/properties/misses_project_php/oneOf/0' => [JsonPath::arrayAt($explain, array_merge($branchRow, ['misses_project_php', 'oneOf', 0])), self::WORD, [PhpFloor::NEEDS_NEWER, PhpFloor::STOPS_BEFORE, PhpFloor::SKIPS, PhpFloor::UNSATISFIABLE], '`misses_project_php`'],
            'explain #/definitions/signalId' => [JsonPath::arrayAt($explain, ['definitions', 'signalId']), self::SIGNAL_ID, ClosedSets::signalIds(), 'signal ids'],
            'config #/properties/format' => [JsonPath::arrayAt($config, ['properties', 'format']), self::FORMAT, LockrotConfig::FORMATS, "the configuration's `format`"],
        ];
    }

    /**
     * Every open set is a string with a `pattern` and the values lockrot writes in `x-known-values`,
     * never an enum, and every known value fits the pattern. The patterns are spelled out, since the
     * strict reading drops them and the widening check never sees one narrowed; the known values are
     * held to the code. The list of places is complete: an `x-known-values` anywhere else fails.
     */
    public function testTheOpenSetsAreOpenStringsWithTheirKnownValues(): void
    {
        $report = self::schema(Schemas::REPORT);
        $explain = self::schema(Schemas::EXPLAIN);
        $config = self::schema(Schemas::CONFIG);
        $branchRow = ['definitions', 'metadata', 'properties', 'branches', 'items', 'properties'];
        $open = self::openSets();
        foreach ($open as $where => [$node, $pattern, $known]) {
            self::assertSame('string', $node['type'] ?? null, $where);
            self::assertArrayNotHasKey('enum', $node, $where);
            self::assertSame($pattern, $node['pattern'] ?? null, $where);
            self::assertSame($known, $node[KnownValues::KEYWORD] ?? null, $where);
            self::assertSame($known, array_values(array_unique($known)), $where);
            foreach ($known as $value) {
                // Delimited as SchemaWidening delimits a schema pattern, so a quantifier's braces
                // or a lone `}` never end it early.
                self::assertMatchesRegularExpression("\x01".$pattern."\x01", $value, $where);
            }
        }
        self::assertSame(['type' => 'null'], JsonPath::arrayAt($report, ['definitions', 's8', 'properties', 'floor_source', 'oneOf', 1]), 'floor_source is otherwise null');
        self::assertCount(2, JsonPath::arrayAt($report, ['definitions', 's8', 'properties', 'floor_source', 'oneOf']));
        self::assertSame(['type' => 'null'], JsonPath::arrayAt($explain, array_merge($branchRow, ['php_blocked_by', 'oneOf', 1])), 'php_blocked_by is otherwise null');
        self::assertCount(2, JsonPath::arrayAt($explain, array_merge($branchRow, ['php_blocked_by', 'oneOf'])));
        foreach (['report' => $report, 'explain' => $explain] as $document => $schema) {
            self::assertSame(['type' => 'null'], JsonPath::arrayAt($schema, ['definitions', 'finding', 'properties', 'libyears_unmeasured', 'oneOf', 1]), $document.': libyears_unmeasured is otherwise null');
            self::assertCount(2, JsonPath::arrayAt($schema, ['definitions', 'finding', 'properties', 'libyears_unmeasured', 'oneOf']));
        }
        foreach (['misses_target_php', 'misses_project_php'] as $side) {
            self::assertSame(['type' => 'null'], JsonPath::arrayAt($explain, array_merge($branchRow, [$side, 'oneOf', 1])), $side.' is otherwise null');
            self::assertCount(2, JsonPath::arrayAt($explain, array_merge($branchRow, [$side, 'oneOf'])));
        }

        $found = [];
        foreach (['report' => $report, 'explain' => $explain, 'config' => $config, 'baseline' => self::schema(Schemas::BASELINE)] as $document => $schema) {
            foreach (self::withKnownValues($schema, '#') as $path) {
                $found[] = $document.' '.$path;
            }
        }
        sort($found);
        $expected = array_keys($open);
        sort($expected);
        self::assertSame($expected, $found);
    }

    /**
     * Every open set is named where a consumer reads which sets grow: the bullet list of
     * docs/compatibility.md's "Open sets", the paragraph there that says which of them the schemas
     * describe, and docs/schema.md's "Open sets" paragraph.
     */
    public function testEveryOpenSetIsNamedOnBothPages(): void
    {
        $compatibility = self::section(self::page('compatibility.md'), '### Open sets');
        $bullets = self::flat(implode(' ', array_filter(explode("\n", $compatibility), static fn (string $line): bool => strpos($line, '- ') === 0)));
        $schemas = self::paragraphStartingWith($compatibility, 'The schemas describe');
        $schemaPage = self::paragraphStartingWith(self::section(self::page('schema.md'), '## Open sets'), 'Objects are open');
        self::assertNotSame('', $bullets, 'the bullet list');

        foreach (self::openSets() as $where => [, , , $phrase]) {
            self::assertStringContainsString($phrase, $bullets, $where.': docs/compatibility.md\'s list of open sets');
            self::assertStringContainsString($phrase, $schemas, $where.': docs/compatibility.md\'s "The schemas describe" paragraph');
            self::assertStringContainsString($phrase, $schemaPage, $where.': docs/schema.md\'s "Open sets" paragraph');
        }
    }

    /** From the heading to the next heading of the same or a higher level. */
    private static function section(string $page, string $heading): string
    {
        $start = strpos($page, "\n".$heading."\n");
        self::assertNotFalse($start, $heading);
        $level = \strlen((string) strstr($heading, ' ', true));
        $rest = substr($page, $start + \strlen($heading) + 2);
        $end = preg_match('/^#{1,'.$level.'} /m', $rest, $match, \PREG_OFFSET_CAPTURE) === 1 ? $match[0][1] : \strlen($rest);

        return substr($rest, 0, $end);
    }

    /** The paragraph that opens with these words, its lines joined. */
    private static function paragraphStartingWith(string $text, string $opening): string
    {
        foreach (preg_split('/\n\s*\n/', $text) ?: [] as $paragraph) {
            if (strpos(ltrim($paragraph), $opening) === 0) {
                return self::flat($paragraph);
            }
        }
        self::fail('no paragraph opens with "'.$opening.'"');
    }

    private static function flat(string $text): string
    {
        return (string) preg_replace('/\s+/', ' ', $text);
    }

    private static function page(string $name): string
    {
        $contents = file_get_contents(self::ROOT.'docs/'.$name);
        self::assertIsString($contents, $name);

        return $contents;
    }

    /**
     * The places in a decoded schema that carry `x-known-values`.
     *
     * @param array<mixed, mixed> $node
     *
     * @return list<string>
     */
    private static function withKnownValues(array $node, string $path): array
    {
        $found = \array_key_exists(KnownValues::KEYWORD, $node) ? [$path] : [];
        foreach ($node as $key => $value) {
            if (\is_array($value) && $key !== KnownValues::KEYWORD) {
                $found = array_merge($found, self::withKnownValues($value, $path.'/'.$key));
            }
        }

        return $found;
    }

    /**
     * No name lockrot ships holds a colon, since `<vendor>:<name>` is for everyone else; no
     * configuration key starts with `x-`, at the top level or in an `ignore` entry; `extensions` at
     * the top level means nothing to lockrot; nothing reads a `LOCKROT_X_` variable; and nothing is
     * declared under `Lockrot\Extension\`, in any letter case, since PHP class names are
     * case-insensitive.
     */
    public function testNoCoreNameUsesANameReservedForExtensions(): void
    {
        $config = self::schema(Schemas::CONFIG);
        $topLevelKeys = self::keys(JsonPath::arrayAt($config, ['properties']));
        $ignoreKeys = self::keys(JsonPath::arrayAt($config, ['properties', 'ignore', 'items', 'properties']));
        $names = array_merge(Verdict::all(), Priority::all(), FailOn::allowed(), LockrotConfig::FORMATS, ClosedSets::signalIds(), [PhpFloor::PROJECT, PhpFloor::TARGET, PhpFloor::NEEDS_NEWER, PhpFloor::STOPS_BEFORE, PhpFloor::SKIPS, PhpFloor::UNSATISFIABLE], Libyears::REASONS, $topLevelKeys, $ignoreKeys);

        foreach ($names as $name) {
            self::assertStringNotContainsString(':', $name, '<vendor>:<name> is reserved for names that are not lockrot\'s');
        }
        foreach (array_merge($topLevelKeys, $ignoreKeys) as $key) {
            self::assertStringStartsNotWith('x-', strtolower($key), 'extra.lockrot keys starting with x- are reserved');
        }
        self::assertNotContains('extensions', $topLevelKeys, 'extra.lockrot.extensions is reserved');

        $read = 0;
        foreach (self::shippedSources() as $path) {
            $source = file_get_contents($path);
            self::assertIsString($source, $path);
            self::assertStringNotContainsString('LOCKROT_X_', $source, $path.': LOCKROT_X_* variables are reserved');
            self::assertDoesNotMatchRegularExpression('/^\s*namespace\s+Lockrot\\\\Extension\b/im', $source, $path.': Lockrot\\Extension\\ is reserved');
            ++$read;
        }
        self::assertGreaterThan(1, $read, 'the source tree and bin/lockrot were read');

        foreach (scandir(self::ROOT.'src') ?: [] as $entry) {
            self::assertNotSame('extension', strtolower($entry), 'src/'.$entry.' would hold Lockrot\\Extension\\, which is reserved');
        }
    }

    /**
     * Every file under src/, whatever its extension, and bin/lockrot, which has none.
     *
     * @return list<string>
     */
    private static function shippedSources(): array
    {
        $paths = [self::ROOT.'bin/lockrot'];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT.'src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $paths[] = $file->getPathname();
            }
        }

        return $paths;
    }

    /**
     * @param array<mixed, mixed> $properties
     *
     * @return list<string>
     */
    private static function keys(array $properties): array
    {
        return array_map('strval', array_keys($properties));
    }

    /** @return array<mixed, mixed> */
    private static function schema(string $document): array
    {
        return JsonPath::decodeFile(Schemas::path($document));
    }
}
