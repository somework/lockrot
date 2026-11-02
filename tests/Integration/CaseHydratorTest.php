<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use JsonSchema\Validator;
use Lockrot\Json\Schemas;
use Lockrot\Output\JsonFormatter;
use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** A committed case, run through the real analyzer, gives the score its document records. */
final class CaseHydratorTest extends TestCase
{
    public function testCaseAScoresItsRecordedScoreText(): void
    {
        $case = CaseHydrator::case('A');
        $recorded = JsonPath::arrayAt($case, ['finding']);

        $document = JsonPath::decoded(CaseHydrator::report($case)->toArray());

        $finding = null;
        foreach (JsonPath::arrayAt($document, ['findings']) as $row) {
            self::assertIsArray($row);
            if (JsonPath::stringAt($row, ['package']) === JsonPath::stringAt($recorded, ['package'])) {
                $finding = $row;
            }
        }
        self::assertNotNull($finding, 'the case package has a finding');
        self::assertSame('32 = vulnerable 32 [critical advisory]', JsonPath::stringAt($recorded, ['score', 'text']), 'the case records its score text');
        self::assertSame(JsonPath::stringAt($recorded, ['score', 'text']), JsonPath::stringAt($finding, ['score', 'text']));
        self::assertSame(JsonPath::stringAt($recorded, ['verdict']), JsonPath::stringAt($finding, ['verdict']));
    }

    /** @return iterable<string, array{string}> */
    public static function provideIgnoredMarkingCases(): iterable
    {
        foreach (['ignored-marking-only‡', 'ignored-marking-stale‡', 'ignored-marking-archived‡', 'ignored-marking-unchecked‡', 'ignored-marking-partial‡'] as $id) {
            yield $id => [$id];
        }
    }

    /**
     * A case whose project lists the package in Composer's abandoned ignore list hydrates with that
     * list, so lockrot ignores the marking as the model does.
     *
     * @dataProvider provideIgnoredMarkingCases
     */
    #[DataProvider('provideIgnoredMarkingCases')]
    public function testAnIgnoredMarkingCaseHydratesWithComposersIgnoreList(string $id): void
    {
        $case = CaseHydrator::case($id);
        $recorded = JsonPath::arrayAt($case, ['finding', 'abandoned_ignored']);

        $finding = null;
        foreach (JsonPath::arrayAt(JsonPath::decoded(CaseHydrator::report($case)->toArray()), ['findings']) as $row) {
            self::assertIsArray($row);
            if (JsonPath::stringAt($row, ['package']) === JsonPath::stringAt($case, ['finding', 'package'])) {
                $finding = $row;
            }
        }
        self::assertNotNull($finding, 'the case package has a finding');
        self::assertSame([JsonPath::stringAt($recorded, ['by']), JsonPath::arrayAt($recorded, ['rules'])], [JsonPath::stringAt($finding, ['abandoned_ignored', 'by']), JsonPath::arrayAt($finding, ['abandoned_ignored', 'rules'])]);
    }

    /** Case snapshot-undated-line‡ has a release without a date: lockrot reads it as undated, and the case still hydrates. */
    public function testACaseWithAnUndatedReleaseHydrates(): void
    {
        $case = CaseHydrator::case('snapshot-undated-line‡');
        $undated = 0;
        foreach (JsonPath::arrayAt($case, ['inputs', 'releases']) as $release) {
            $undated += \is_array($release) && \array_key_exists('time', $release) && $release['time'] === null ? 1 : 0;
        }
        self::assertGreaterThan(0, $undated, 'case snapshot-undated-line‡ records an undated release');

        $packages = array_column(JsonPath::arrayAt(CaseHydrator::report($case)->toArray(), ['findings']), 'package');

        self::assertContains(JsonPath::stringAt($case, ['finding', 'package']), $packages);
    }

    /**
     * Every case's recorded `finding`, with or without inputs, is a report-2 finding. It covers
     * nothing: its validations outlast the timeout of a mutation run, and they read no product code.
     *
     * @coversNothing
     *
     * @group covers-nothing
     */
    #[CoversNothing]
    #[Group('covers-nothing')]
    public function testEveryCaseFindingValidatesAgainstReport2(): void
    {
        $schema = JsonPath::decodeFile(Schemas::path(Schemas::REPORT, JsonFormatter::SCHEMA));
        $finding = json_decode((string) json_encode(['$ref' => '#/definitions/finding', 'definitions' => JsonPath::arrayAt($schema, ['definitions'])]));
        $cases = JsonPath::decodeFile(__DIR__.'/../fixtures/flags/cases.json');
        $cases = JsonPath::has($cases, ['cases']) ? JsonPath::arrayAt($cases, ['cases']) : $cases;
        self::assertNotSame([], $cases);

        $invalid = [];
        foreach ($cases as $case) {
            self::assertIsArray($case);
            $validator = new Validator();
            $document = json_decode((string) json_encode(JsonPath::arrayAt($case, ['finding'])));
            $validator->validate($document, $finding);
            if (!$validator->isValid()) {
                $invalid[] = JsonPath::stringAt($case, ['id']);
            }
        }

        self::assertSame([], $invalid, 'every case finding validates');
    }

    /** Every case's lock holds each name once, and its root holds only keys that composer.json defines. */
    public function testEveryCaseInputHasOneLockEntryPerNameAndAComposerJsonRoot(): void
    {
        $cases = JsonPath::arrayAt(JsonPath::decodeFile(CaseHydrator::CASES), ['cases']);
        self::assertNotSame([], $cases);

        $repeated = [];
        $strayKeys = [];
        foreach ($cases as $case) {
            self::assertIsArray($case);
            if (!\is_array($case['inputs'] ?? null)) {
                continue;
            }
            $id = JsonPath::stringAt($case, ['id']);
            $names = [];
            foreach (JsonPath::arrayAt($case, ['inputs', 'lock']) as $entry) {
                self::assertIsArray($entry);
                $names[] = JsonPath::stringAt($entry, ['name']);
            }
            if (\count($names) !== \count(array_unique($names))) {
                $repeated[] = $id;
            }
            $root = $case['inputs']['root'];
            foreach (\is_array($root) ? array_diff(array_keys($root), ['name', 'require', 'require-dev', 'conflict', 'replace', 'repositories', 'config']) : [] as $key) {
                $strayKeys[] = $id.': '.$key;
            }
        }

        self::assertSame([], $repeated, 'cases whose lock repeats a name');
        self::assertSame([], $strayKeys, 'root keys that composer.json does not define');
    }

    /**
     * In a case of train 7d, a hold of the case's own moves with a target names a link of the
     * case's world: a root requirement of the target, or a locked package's link to it. The
     * hydrated root and lock hold that link with the world's constraint. A hold of the release
     * that a move asks for is no link of the lock.
     */
    public function testEachTargetHoldOfATrain7dMoveIsALinkOfTheHydratedRootOrLock(): void
    {
        $checked = 0;
        $missing = [];
        foreach (JsonPath::arrayAt(JsonPath::decodeFile(CaseHydrator::CASES), ['cases']) as $case) {
            self::assertIsArray($case);
            if (self::arrayOrNone($case, ['inputs', 'solver_world']) === []) {
                continue;
            }
            $id = JsonPath::stringAt($case, ['id']);
            $inputs = JsonPath::arrayAt($case, ['inputs']);
            $world = JsonPath::arrayAt($inputs, ['solver_world', 'world']);
            $next = self::arrayOrNone($case, ['finding', 'next_step']);
            $holds = self::arrayOrNone($next, ['held_by']);
            foreach (self::arrayOrNone($next, ['also']) as $also) {
                self::assertIsArray($also);
                $holds = array_merge($holds, self::arrayOrNone($also, ['held_by']));
            }
            foreach (self::arrayOrNone($case, ['details', 'metadata', 'branches']) as $row) {
                self::assertIsArray($row);
                $holds = array_merge($holds, self::arrayOrNone($row, ['fixes', 'held_by']));
            }
            foreach ($holds as $hold) {
                self::assertIsArray($hold);
                $source = JsonPath::stringAt($hold, ['source']);
                $link = JsonPath::stringAt($hold, ['link']);
                $target = \is_string($hold['target'] ?? null) ? $hold['target'] : null;
                $package = \is_string($hold['package'] ?? null) ? $hold['package'] : '';
                $locked = $source === 'root' || (self::lockEntry($world, ['lock', 'packages'], $package)['version'] ?? null) === ($hold['version'] ?? null);
                if ($target === null || !$locked) {
                    continue;
                }
                ++$checked;
                [$expected, $actual] = $source === 'root'
                    ? [self::arrayOrNone($world, ['root', 'require'])[$target] ?? null, self::arrayOrNone($inputs, ['root', 'require'])[$target] ?? null]
                    : [self::arrayOrNone(self::lockEntry($world, ['lock', 'packages'], $package), [$link])[$target] ?? null, self::arrayOrNone(self::lockEntry($inputs, ['lock'], $package), [$link])[$target] ?? null];
                if ($expected === null || $expected !== $actual) {
                    $missing[] = \sprintf('%s: %s %s %s %s, world %s, hydrated %s', $id, $source, $package === '' ? 'root' : $package, $link, $target, var_export($expected, true), var_export($actual, true));
                }
            }
        }

        self::assertGreaterThan(0, $checked, 'the train 7d moves have holds with a target on the root or the lock');
        self::assertSame([], $missing, 'holds whose link the hydrated inputs lack');
    }

    /**
     * @param array<mixed, mixed> $data
     * @param list<string>        $path
     *
     * @return array<mixed, mixed> the array at $path, empty when a segment is missing or null
     */
    private static function arrayOrNone(array $data, array $path): array
    {
        foreach ($path as $key) {
            if (!\is_array($data[$key] ?? null)) {
                return [];
            }
            $data = $data[$key];
        }

        return $data;
    }

    /**
     * @param array<mixed, mixed> $data
     * @param list<string>        $path the lock section, a list of entries with a `name`
     *
     * @return array<mixed, mixed> the entry of $name, empty when the section has none
     */
    private static function lockEntry(array $data, array $path, string $name): array
    {
        foreach (self::arrayOrNone($data, $path) as $entry) {
            if (\is_array($entry) && ($entry['name'] ?? null) === $name) {
                return $entry;
            }
        }

        return [];
    }

    public function testACaseWithoutInputsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CaseHydrator::report(['id' => 'X', 'inputs' => null]);
    }
}
