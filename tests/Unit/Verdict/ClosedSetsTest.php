<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Lockrot\Analyzer\RunSettings;
use Lockrot\Config\LockrotConfig;
use Lockrot\Json\Schemas;
use Lockrot\Output\JsonFormatter;
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
 * The last test guards the names docs/compatibility.md reserves for extensions.
 */
final class ClosedSetsTest extends TestCase
{
    private const VERDICTS = ['abandoned', 'silent', 'pinned', 'left-behind', 'old-promise', 'stale', 'unknown', 'finished', 'ok'];
    private const FLAGGED = ['abandoned', 'silent', 'pinned', 'left-behind', 'old-promise', 'stale'];
    private const PRIORITIES = ['critical', 'high', 'medium', 'low', 'none'];
    private const LEVELS = ['info', 'warn', 'high'];
    private const STANDINGS = ['known', 'new', 'worsened'];
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
     * Signal ids are an open set — they grow in minor releases — but the ids lockrot ships are its
     * own `S<n>`, and the schemas list exactly those while they still spell the set out. The report
     * schema spells it three times: the `signalId` enum S10's `blocks` use, the enum on a signal's
     * `id`, and one `anyOf` branch per id that types that signal's `data`. A signal added to the code
     * and to one of the three would otherwise validate against the other two only by accident.
     */
    public function testTheSignalIdsAreLockrotsOwnAndTheSchemasListThem(): void
    {
        $ids = ClosedSets::signalIds();
        self::assertNotEmpty($ids);
        foreach ($ids as $id) {
            self::assertMatchesRegularExpression('/^S[1-9][0-9]*$/', $id);
        }

        $report = self::schema(Schemas::REPORT);
        self::assertSame($ids, JsonPath::arrayAt($report, ['definitions', 'signalId', 'enum']));
        self::assertSame($ids, JsonPath::arrayAt($report, ['definitions', 'signal', 'properties', 'id', 'enum']));

        $branches = [];
        foreach (JsonPath::arrayAt($report, ['definitions', 'signal', 'anyOf']) as $index => $branch) {
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

        self::assertSame($ids, JsonPath::arrayAt(self::schema(Schemas::EXPLAIN), ['definitions', 'finding', 'properties', 'signals', 'items', 'properties', 'id', 'enum']));
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
        $names = array_merge(Verdict::all(), Priority::all(), FailOn::allowed(), LockrotConfig::FORMATS, ClosedSets::signalIds(), $topLevelKeys, $ignoreKeys);

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
