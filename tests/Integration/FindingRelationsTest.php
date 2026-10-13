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
     * A change to the composite document, and the relation it breaks.
     *
     * @return iterable<string, array{callable(array<mixed, mixed>): array<mixed, mixed>, string}>
     */
    public static function breaks(): iterable
    {
        yield 'two not_applied entries merged into one (I34)' => [static function (array $d): array {
            $d['gate']['not_applied'] = [['check' => 'advisories', 'packages' => 2, 'reason' => 'offline'], ['check' => 'advisories', 'packages' => 1, 'reason' => 'offline']];

            return $d;
        }, 'one entry per (check, reason)'];
        yield 'a failing_by count off by one' => [static function (array $d): array {
            ++$d['gate']['failing_by'][0]['failing'];

            return $d;
        }, 'gate.failing_by'];
        yield 'failing_by without an entry for an active value' => [static function (array $d): array {
            $d['gate']['failing_by'] = [];

            return $d;
        }, 'gate.failing_by'];
        yield 'a note text that is not its detail' => [static function (array $d): array {
            $d['notes'][] = 'an extra line';

            return $d;
        }, 'notes[i] is note_details[i].text'];
        yield 'an ignored count that is not the list' => [static function (array $d): array {
            ++$d['findings'][0]['security']['ignored_count'];

            return $d;
        }, 'security.ignored_count'];
        yield 'a term contribution that does not add up' => [static function (array $d): array {
            ++$d['findings'][0]['score']['terms'][0]['contribution'];

            return $d;
        }, 'score.exact'];
        yield 'a gate.by value that is not active' => [static function (array $d): array {
            $d['findings'][0]['gate']['by'][] = ['value' => 'stale', 'kind' => 'flag', 'facts' => [['kind' => 'signal', 'id' => 'S2']]];

            return $d;
        }, 'run.gates[] value'];
        yield 'a flag listed twice' => [static function (array $d): array {
            $d['findings'][0]['flags'][] = $d['findings'][0]['flags'][0];

            return $d;
        }, 'each flag once'];
        yield 'S9 without a counted advisory' => [static function (array $d): array {
            $d['findings'][0]['flags'] = array_values(array_filter($d['findings'][0]['flags'], static fn (array $flag): bool => $flag['id'] !== 'vulnerable'));

            return $d;
        }, 'S9 is present'];
        yield 'the oldest cache read moved' => [static function (array $d): array {
            $d['activity_cache_oldest_at'] = '2020-01-01T00:00:00+00:00';

            return $d;
        }, 'activity_cache_oldest_at'];
        yield 'a score model that is not the run one' => [static function (array $d): array {
            $d['findings'][0]['score']['model'] = 99;

            return $d;
        }, 'score.model'];
        yield 'an ignored advisory that S9 counts' => [static function (array $d): array {
            $id = $d['findings'][0]['score']['terms'][0]['advisory'];
            $d['findings'][0]['security']['ignored'][] = ['id' => $id];
            $d['findings'][0]['security']['ignored_count'] = \count($d['findings'][0]['security']['ignored']);

            return $d;
        }, 'disjoint from the S9 rows'];
    }

    /**
     * Each relation can fail: a change to a valid document breaks it.
     *
     * @param callable(array<mixed, mixed>): array<mixed, mixed> $change
     *
     * @dataProvider breaks
     */
    #[DataProvider('breaks')]
    public function testEachRelationCatchesTheChangeThatBreaksIt(callable $change, string $relation): void
    {
        $composite = self::read(self::DOCUMENTS.'cases/composite.json');
        self::assertSame([], FindingRelations::violations($composite));

        $violations = FindingRelations::violations($change($composite));

        self::assertNotSame([], array_filter($violations, static fn (string $v): bool => strpos($v, $relation) !== false), implode("\n", $violations));
    }

    /**
     * I34, coverage variant `not-applied-mixed`: the gap is one entry per reason, so one merged
     * entry named by the most frequent reason breaks the root check.
     */
    public function testAMergedNotAppliedEntryBreaksTheRootCheck(): void
    {
        $mixed = self::read(self::DOCUMENTS.'coverage/variants/not-applied-mixed.json.gz');
        self::assertSame([['check' => 'advisories', 'packages' => 2, 'reason' => 'no_feed'], ['check' => 'advisories', 'packages' => 1, 'reason' => 'not_from_composer_repository']], $mixed['gate']['not_applied']);
        self::assertSame([], FindingRelations::violations($mixed));

        $mixed['gate']['not_applied'] = [['check' => 'advisories', 'packages' => 3, 'reason' => 'no_feed']];

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
