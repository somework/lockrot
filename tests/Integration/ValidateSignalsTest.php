<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The signal checks on every fixture document: each signal's `data` has exactly its keys, S7's
 * `packages[]` entries have theirs, and S10's `blocks` is the union of its entries' blocks.
 */
final class ValidateSignalsTest extends TestCase
{
    /** The reference model's `SIG_KEYS`, as `project_fixtures.py` projects them onto this draft: S4 keeps `activity`. */
    private const KEYS = [
        'S1' => ['marked_by', 'replacement', 'replacement_url'],
        'S2' => ['last_release', 'last_version', 'years', 'dated_by'],
        'S3' => ['repo', 'host', 'forge'],
        'S4' => ['last_push', 'repo', 'host', 'years', 'forge', 'activity'],
        'S5' => ['target_php', 'target_major', 'ga_date', 'php_constraint', 'written_for_php', 'released'],
        'S6' => ['version', 'reason', 'has_stable_release', 'last_stable_release', 'last_stable_version', 'last_stable_dated_by', 'snapshot_time', 'tag_relation'],
        'S7' => ['flagged', 'packages'],
        'S8' => ['branch', 'branch_last_release', 'branch_last_version', 'years', 'newest_branch', 'newest_version', 'newest_release', 'newest_php', 'newest_within_reach', 'newest_years', 'floor_php', 'floor_source', 'reachable_branch', 'reachable_version', 'reachable_release', 'reachable_php', 'reachable_admits', 'reachable_years', 'suggested_constraint', 'dated_by'],
        'S9' => ['advisories', 'releases_read', 'complete'],
        'S10' => ['unchecked', 'blocks'],
    ];
    private const S7_PACKAGE = ['package', 'chain', 'verdict', 'lead', 'flag_ids'];
    private const S10_ENTRY = ['check', 'reason', 'blocks'];
    private const GRADES = ['critical', 'high', 'medium', 'low'];

    /** @return iterable<string, array{array<mixed, mixed>}> */
    public static function findings(): iterable
    {
        $cases = JsonPath::arrayAt(JsonPath::decodeFile(CaseHydrator::CASES), ['cases']);
        self::assertNotSame([], $cases);
        foreach ($cases as $case) {
            self::assertIsArray($case);
            yield 'case '.JsonPath::stringAt($case, ['id']) => [JsonPath::arrayAt($case, ['finding'])];
        }
        foreach (JsonPath::arrayAt(JsonPath::decodeFile(__DIR__.'/../fixtures/flags/composite.json'), ['findings']) as $finding) {
            self::assertIsArray($finding);
            yield 'composite '.JsonPath::stringAt($finding, ['package']) => [$finding];
        }
        foreach (CaseHydrator::findings() as $id => $finding) {
            yield 'lockrot '.$id => [$finding];
        }
    }

    /**
     * @param array<mixed, mixed> $finding
     *
     * @dataProvider findings
     *
     * @coversNothing
     *
     * @group covers-nothing
     */
    #[CoversNothing]
    #[Group('covers-nothing')]
    #[DataProvider('findings')]
    public function testEverySignalHasExactlyItsDataKeys(array $finding): void
    {
        $ids = [];
        foreach (JsonPath::arrayAt($finding, ['signals']) as $signal) {
            self::assertIsArray($signal);
            $data = JsonPath::arrayAt($signal, ['data']);
            $id = JsonPath::stringAt($signal, ['id']);
            $ids[] = $id;
            $expected = self::KEYS[$id];
            sort($expected);
            $actual = array_keys($data);
            sort($actual);
            self::assertSame($expected, $actual, $id);
        }
        self::assertSame(\count($ids), \count(array_unique($ids)), 'each signal once');
    }

    /**
     * @param array<mixed, mixed> $finding
     *
     * @dataProvider findings
     *
     * @coversNothing
     *
     * @group covers-nothing
     */
    #[CoversNothing]
    #[Group('covers-nothing')]
    #[DataProvider('findings')]
    public function testS7NamesGradedPackagesAndS10BlocksTheUnionOfItsEntries(array $finding): void
    {
        $checked = 0;
        foreach (JsonPath::arrayAt($finding, ['signals']) as $signal) {
            self::assertIsArray($signal);
            if ($signal['id'] === 'S7') {
                $packages = JsonPath::arrayAt($signal, ['data', 'packages']);
                foreach ($packages as $package) {
                    self::assertIsArray($package);
                    $keys = array_keys($package);
                    sort($keys);
                    $expected = self::S7_PACKAGE;
                    sort($expected);
                    self::assertSame($expected, $keys);
                    self::assertContains($package['verdict'], self::GRADES);
                }
                self::assertSame(\count($packages), JsonPath::intAt($signal, ['data', 'flagged']));
                ++$checked;
            }
            if ($signal['id'] === 'S10') {
                $union = [];
                foreach (JsonPath::arrayAt($signal, ['data', 'unchecked']) as $entry) {
                    self::assertIsArray($entry);
                    $keys = array_keys($entry);
                    sort($keys);
                    $expected = self::S10_ENTRY;
                    sort($expected);
                    self::assertSame($expected, $keys);
                    $union = array_merge($union, JsonPath::arrayAt($entry, ['blocks']));
                }
                $blocks = JsonPath::arrayAt($signal, ['data', 'blocks']);
                sort($blocks);
                $union = array_values(array_unique(array_map(static fn ($block): string => \is_string($block) ? $block : '', $union)));
                sort($union);
                self::assertSame($union, $blocks);
                ++$checked;
            }
        }
        self::assertSame(\count(array_filter(JsonPath::arrayAt($finding, ['signals']), static fn ($signal): bool => \is_array($signal) && \in_array($signal['id'], ['S7', 'S10'], true))), $checked);
    }
}
