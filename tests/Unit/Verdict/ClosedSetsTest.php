<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Lockrot\Analyzer\RunSettings;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Config\LockrotConfig;
use Lockrot\Json\Schemas;
use Lockrot\Output\JsonFormatter;
use Lockrot\Signal\Signal;
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
 * The last test guards the names docs/compatibility.md reserves for extensions: no name lockrot
 * ships contains a colon (`<vendor>:<name>` is for everyone else), no configuration key starts with
 * `x-`, and nothing reads a `LOCKROT_X_` variable.
 */
final class ClosedSetsTest extends TestCase
{
    private const VERDICTS = ['abandoned', 'silent', 'pinned', 'left-behind', 'old-promise', 'stale', 'unknown', 'finished', 'ok'];
    private const FLAGGED = ['abandoned', 'silent', 'pinned', 'left-behind', 'old-promise', 'stale'];
    private const PRIORITIES = ['critical', 'high', 'medium', 'low', 'none'];
    private const LEVELS = ['info', 'warn', 'high'];
    private const STANDINGS = ['known', 'new', 'worsened'];

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
        self::assertSame(self::FLAGGED, (new RunSettings(null, null, null, null, null))->toArray()['flagged_verdicts']);
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
        self::assertSame(self::LEVELS, [Signal::LEVEL_INFO, Signal::LEVEL_WARN, Signal::LEVEL_HIGH]);
        self::assertSame(self::STANDINGS, [BaselineComparison::KNOWN, BaselineComparison::NEW_FINDING, BaselineComparison::WORSENED]);
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
     * own `S<n>`, and the schemas list exactly those while they still spell the set out.
     */
    public function testTheSignalIdsAreLockrotsOwnAndTheSchemasListThem(): void
    {
        $ids = [];
        foreach ((new \ReflectionClass(Signal::class))->getConstants() as $name => $value) {
            if (preg_match('/^S\d+$/', $name) === 1) {
                self::assertSame($name, $value, 'a signal constant holds its own id');
                self::assertMatchesRegularExpression('/^S[1-9][0-9]*$/', (string) $value);
                $ids[] = (string) $value;
            }
        }

        self::assertSame($ids, JsonPath::arrayAt(self::schema(Schemas::REPORT), ['definitions', 'signalId', 'enum']));
        self::assertSame($ids, JsonPath::arrayAt(self::schema(Schemas::EXPLAIN), ['definitions', 'finding', 'properties', 'signals', 'items', 'properties', 'id', 'enum']));
    }

    public function testNoCoreNameUsesANameReservedForExtensions(): void
    {
        $configKeys = array_map('strval', array_keys(JsonPath::arrayAt(self::schema(Schemas::CONFIG), ['properties'])));
        $names = array_merge(Verdict::all(), Priority::all(), FailOn::allowed(), LockrotConfig::FORMATS, $configKeys);

        foreach ($names as $name) {
            self::assertStringNotContainsString(':', $name, '<vendor>:<name> is reserved for names that are not lockrot\'s');
        }
        foreach ($configKeys as $key) {
            self::assertStringStartsNotWith('x-', $key, 'extra.lockrot keys starting with x- are reserved');
        }

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../../../src', \FilesystemIterator::SKIP_DOTS));
        $read = 0;
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            self::assertStringNotContainsString('LOCKROT_X_', $source, $file->getPathname().': LOCKROT_X_* variables are reserved');
            ++$read;
        }
        self::assertGreaterThan(0, $read, 'the source tree was read');
    }

    /** @return array<mixed, mixed> */
    private static function schema(string $document): array
    {
        $contents = file_get_contents(Schemas::path($document));
        self::assertIsString($contents);
        $decoded = json_decode($contents, true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
