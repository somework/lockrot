<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Tests\Support\FindingRelations;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The engine-only relations of report-2 ({@see FindingRelations}) hold strictly on every committed
 * fixture document: the model's 251 report-2 documents and the flags fixture's composite.
 * ScoreFactsConsistencyTest reads them on the reports that lockrot writes.
 *
 * @coversNothing
 *
 * @group covers-nothing
 */
#[CoversNothing]
#[Group('covers-nothing')]
final class FindingRelationsTest extends TestCase
{
    private const DOCUMENTS = __DIR__.'/../fixtures/schema/documents/';
    private const COMPOSITE = __DIR__.'/../fixtures/flags/composite.json';

    public function testEveryFixtureDocumentHoldsEveryRelation(): void
    {
        $paths = self::documents();
        self::assertCount(251, $paths, 'the six sets of tests/fixtures/schema/documents/provenance.json');
        $broken = [];
        foreach (array_merge($paths, [self::COMPOSITE]) as $path) {
            foreach (FindingRelations::violations(self::read($path)) as $violation) {
                $broken[] = basename($path).' '.$violation;
            }
        }

        self::assertSame([], \array_slice($broken, 0, 20), \count($broken).' violations');
    }

    /**
     * A change to the composite document (a path and what the value at it becomes), and the
     * relation it breaks.
     *
     * @return iterable<string, array{list<int|string>, callable(mixed): mixed, string}>
     */
    public static function breaks(): iterable
    {
        $inc = static fn ($value): int => \is_int($value) ? $value + 1 : 1;
        yield 'two not_applied entries for one reason (I34)' => [['gate', 'not_applied'], static fn (): array => [['check' => 'advisories', 'packages' => 2, 'reason' => 'offline'], ['check' => 'advisories', 'packages' => 1, 'reason' => 'offline']], 'one entry per (check, reason)'];
        yield 'a failing_by count off by one' => [['gate', 'failing_by', 0, 'failing'], $inc, 'gate.failing_by'];
        yield 'failing_by without an entry for an active value' => [['gate', 'failing_by'], static fn (): array => [], 'gate.failing_by'];
        yield 'a note text that is not its detail' => [['notes'], static fn ($notes): array => array_merge(\is_array($notes) ? $notes : [], ['an extra line']), 'notes[i] is note_details[i].text'];
        yield 'an ignored count that is not the list' => [['findings', 0, 'security', 'ignored_count'], $inc, 'security.ignored_count'];
        yield 'a term contribution that does not add up' => [['findings', 0, 'score', 'terms', 0, 'contribution'], $inc, 'score.exact'];
        yield 'a gate.by value that is not active' => [['findings', 0, 'gate', 'by'], static fn ($by): array => array_merge(\is_array($by) ? $by : [], [['value' => 'stale', 'kind' => 'flag', 'facts' => [['kind' => 'signal', 'id' => 'S2']]]]), 'run.gates[] value'];
        yield 'a flag listed twice' => [['findings', 0, 'flags'], static fn ($flags): array => \is_array($flags) ? array_merge($flags, [$flags[0]]) : [], 'each flag once'];
        yield 'S9 without a counted advisory' => [['findings', 0, 'flags'], static fn ($flags): array => array_values(array_filter(\is_array($flags) ? $flags : [], static fn ($flag): bool => !\is_array($flag) || $flag['id'] !== 'vulnerable')), 'S9 is present'];
        yield 'the oldest cache read moved' => [['activity_cache_oldest_at'], static fn (): string => '2020-01-01T00:00:00+00:00', 'activity_cache_oldest_at'];
        yield 'a score model that is not the run one' => [['findings', 0, 'score', 'model'], static fn (): int => 99, 'score.model'];
        yield 'an ignored advisory that S9 counts' => [['findings', 0, 'security', 'ignored'], static fn (): array => [['id' => 'PKSA-h8hf-ytnd-5t9q']], 'disjoint from the S9 rows'];
    }

    /**
     * Each relation can fail: a change to a valid document breaks it.
     *
     * @param list<int|string>      $path
     * @param callable(mixed): mixed $change
     *
     * @dataProvider breaks
     */
    #[DataProvider('breaks')]
    public function testEachRelationCatchesTheChangeThatBreaksIt(array $path, callable $change, string $relation): void
    {
        $composite = self::read(self::DOCUMENTS.'cases/composite.json');
        self::assertSame([], FindingRelations::violations($composite));

        $violations = FindingRelations::violations(self::edit($composite, $path, $change));

        self::assertNotSame([], array_filter($violations, static fn (string $v): bool => strpos($v, $relation) !== false), implode("\n", $violations));
    }

    /**
     * @param array<mixed, mixed>    $node
     * @param list<int|string>       $path
     * @param callable(mixed): mixed $change
     *
     * @return array<mixed, mixed>
     */
    private static function edit(array $node, array $path, callable $change): array
    {
        $key = array_shift($path);
        self::assertNotNull($key);
        $node[$key] = $path === [] ? $change($node[$key] ?? null) : self::edit(\is_array($node[$key] ?? null) ? $node[$key] : [], $path, $change);

        return $node;
    }

    /**
     * I34, coverage variant `not-applied-mixed`: the gap is one entry per reason, so one merged
     * entry named by the most frequent reason breaks the root check.
     */
    public function testAMergedNotAppliedEntryBreaksTheRootCheck(): void
    {
        $mixed = self::read(self::DOCUMENTS.'coverage/variants/not-applied-mixed.json.gz');
        self::assertSame([['check' => 'advisories', 'packages' => 2, 'reason' => 'no_feed'], ['check' => 'advisories', 'packages' => 1, 'reason' => 'not_from_composer_repository']], JsonPath::arrayAt($mixed, ['gate', 'not_applied']));
        self::assertSame([], FindingRelations::violations($mixed));

        $mixed = self::edit($mixed, ['gate', 'not_applied'], static fn (): array => [['check' => 'advisories', 'packages' => 3, 'reason' => 'no_feed']]);

        self::assertContains('gate.not_applied: an entry counts the findings with its reason (I34)', FindingRelations::violations($mixed));
    }

    /** @return list<string> every report-2 document, the gzipped corpus-scale sets included */
    private static function documents(): array
    {
        $paths = [];
        foreach (['cases/*.json', 'variants/*.json', 'corpus/*.json.gz', 'coverage/baselined/*.json.gz', 'coverage/variants/*.json.gz'] as $pattern) {
            $paths = array_merge($paths, glob(self::DOCUMENTS.$pattern) ?: []);
        }
        sort($paths);

        return $paths;
    }

    /** @return array<mixed, mixed> */
    private static function read(string $path): array
    {
        return JsonPath::decodeFile(substr($path, -3) === '.gz' ? 'compress.zlib://'.$path : $path);
    }
}
