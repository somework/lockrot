<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Tests\Support\FindingRelations;
use Lockrot\Tests\Support\ItemRelations;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The engine-only relations of report-2 ({@see FindingRelations}, {@see ItemRelations}) hold strictly
 * on every committed fixture document: the model's 253 report-2 documents and the flags fixture's
 * composite. ScoreFactsConsistencyTest reads them on the reports that lockrot writes.
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
    private const WALLABAG = __DIR__.'/../fixtures/schema/documents/corpus/wallabag.json.gz';
    /** The sets that hold whole reports. A case outside WHOLE_CASES or a coverage variant holds part of one, so I25 cannot read it. */
    private const WHOLE = ['corpus/', 'variants/', 'coverage/baselined/'];
    /** The case documents that hold a whole report: the composite, and each case whose finding comes with its `parents`. */
    private const WHOLE_CASES = ['cases/commonmark.json', 'cases/composite.json', 'cases/many-dependents.json', 'cases/xmlseclibs.json'];
    private const CASES = __DIR__.'/../fixtures/flags/cases.json';
    private const CONTRACT = __DIR__.'/../fixtures/contract/';

    public function testEveryFixtureDocumentHoldsEveryRelation(): void
    {
        $paths = self::documents();
        self::assertCount(266, $paths, 'the six sets of tests/fixtures/schema/documents/provenance.json');
        $broken = [];
        foreach (array_merge($paths, [self::COMPOSITE]) as $path) {
            $document = self::read($path);
            $set = substr($path, \strlen(self::DOCUMENTS));
            $whole = $path === self::COMPOSITE || \in_array($set, self::WHOLE_CASES, true) || array_filter(self::WHOLE, static fn (string $prefix): bool => strpos($set, $prefix) === 0) !== [];
            foreach (array_merge(FindingRelations::violations($document), ItemRelations::violations($document, $whole)) as $violation) {
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
        yield 'a step of a move that the check refuses' => [['findings', 0, 'next_step', 'check'], static fn (): array => ['result' => 'refused', 'form' => null, 'reason' => null], 'has no update or require step'];
        yield 'a step without its form\'s option' => [['findings', 0, 'next_step', 'check'], static fn (): array => ['result' => 'resolves', 'form' => 'with-dependencies', 'reason' => null], 'ends with --with-dependencies'];
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
     * A change to the wallabag corpus report and the item relation it breaks.
     *
     * @return iterable<string, array{list<int|string>, callable(mixed): mixed, string}>
     */
    public static function itemBreaks(): iterable
    {
        $inc = static fn ($value): int => \is_int($value) ? $value + 1 : 1;
        $to = static fn ($new): callable => static fn () => $new;
        $append = static fn (array $row): callable => static fn ($rows): array => array_merge(\is_array($rows) ? $rows : [], [$row]);
        yield 'A1 a read activity without its event' => [['findings', 5, 'activity', 'event'], $to(null), 'C73 A1'];
        yield 'A2 not_found without its reason' => [['findings', 5, 'activity', 'status'], $to('not_found'), 'C73 A2'];
        yield 'A3 an activity not read with an event' => [['findings', 5, 'activity', 'status'], $to('skipped'), 'C73 A3'];
        yield 'A4 a repo without a forge' => [['findings', 5, 'activity', 'forge'], $to(null), 'C73 A4'];
        yield 'A5 a missing activity check beside a read activity' => [['findings', 5, 'checks_missing'], $append(['check' => 'repository_activity', 'reason' => 'offline', 'blocks' => ['S3', 'S4']]), 'C73 A5'];
        yield 'A6 a read activity without its archived bit' => [['findings', 5, 'activity', 'archived'], $to(null), 'C73 A6'];
        yield 'A7 S3 on a repository that is not archived' => [['findings', 8, 'activity', 'archived'], $to(false), 'C73 A7'];
        yield 'A8 S4 that does not quote age.push' => [['findings', 5, 'age', 'push', 'at'], $to('2020-01-01T00:00:00+00:00'), 'C73 A8'];
        yield 'A10 a host note without a host' => [['note_details'], $append(['code' => 'repository_activity_host_not_supported', 'data' => ['hosts' => []]]), 'C73 A10'];
        yield 'R1 a feed that no repository is' => [['findings', 0, 'security', 'feeds', 0, 'composer_repository'], $to('other'), 'C75 R1'];
        yield 'R2 records without a count' => [['findings', 0, 'security', 'records'], $to(null), 'C75 R2'];
        yield 'R3 a partial lookup without a failure' => [['findings', 0, 'security', 'check'], $to('partial'), 'C75 R3'];
        yield 'R4 one more package asked' => [['run', 'advisory_lookup', 'packages_asked'], $inc, 'C75 R4'];
        yield 'R5 one more package with records' => [['run', 'advisory_lookup', 'repositories', 0, 'packages_with_records'], $inc, 'C75 R5'];
        yield 'R6 fewer records than S9 rows' => [['findings', 1, 'security', 'records'], $to(0), 'C75 R6'];
        yield 'R7 an advisories check with another reason' => [['findings', 0, 'checks_missing'], $append(['check' => 'advisories', 'reason' => 'offline', 'blocks' => ['S9']]), 'C75 R7'];
        yield 'R8 a transport failure without network_failures' => [['run', 'advisory_lookup', 'repositories'], $append(['composer_repository' => 'composer repo (https://mirror.example)', 'outcome' => 'failed', 'reason' => 'transport']), 'C75 R8'];
        yield 'C77 libyears beside its reason' => [['findings', 0, 'libyears_unmeasured'], $to('unavailable'), 'C77 pairing'];
        yield 'C77 a lower bound without libyears' => [['findings', 23, 'libyears_at_least'], $to(true), 'C77 pairing'];
        yield 'C77 metadata not read with another reason' => [['findings', 0, 'metadata', 'status'], $to('unavailable'), 'C77 status'];
        yield 'C77 one more lower bound at the root' => [['libyears', 'at_least_count'], $inc, 'C77: libyears.measured'];
        yield 'C77 the furthest finding read as a lower bound' => [['libyears', 'furthest_behind', 'at_least'], $to(true), 'furthest_behind'];
        yield 'W12 a depth that is not the chain' => [['findings', 23, 'depth'], $inc, 'W12: depth'];
        yield 'W12 a direct finding read as unreached' => [['findings', 0, 'reach'], $to('unreached'), 'W12: reach'];
        yield 'W12 a direct finding named nowhere' => [['findings', 0, 'named_in'], $to([]), 'W12: named_in'];
        yield 'W12 one more unreached finding' => [['rot_depth', 'by_reach', 'unreached'], $inc, 'W12: rot_depth.by_reach'];
        yield 'I25 an exposure count off by one' => [['exposure', 0, 'flagged'], $inc, 'I25: exposure[]'];
        yield 'I25 an unattributed finding missing' => [['unattributed'], $to([]), 'I25:'];
        yield 'I28 years that are not the tenths' => [['findings', 5, 'age', 'release', 'years'], $to(0.1), 'I28: age.release.years'];
        yield 'I28 S2 that does not quote age.release' => [['findings', 5, 'age', 'release', 'level'], $to('info'), 'I28: S2 quotes'];
    }

    /**
     * @param list<int|string>       $path
     * @param callable(mixed): mixed $change
     *
     * @dataProvider itemBreaks
     */
    #[DataProvider('itemBreaks')]
    public function testEachItemRelationCatchesTheChangeThatBreaksIt(array $path, callable $change, string $relation): void
    {
        $wallabag = self::read(self::WALLABAG);
        self::assertSame([], ItemRelations::violations($wallabag));

        $violations = ItemRelations::violations(self::edit($wallabag, $path, $change));

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

    public function testTheWholeCaseDocumentsAreTheCompositeAndTheCasesWithParents(): void
    {
        $withParents = [];
        foreach (JsonPath::arrayAt(JsonPath::decodeFile(self::CASES), ['cases']) as $case) {
            if (\is_array($case) && isset($case['parents'])) {
                $withParents[] = 'cases/'.JsonPath::stringAt($case, ['id']).'.json';
            }
        }
        $withParents[] = 'cases/composite.json';
        sort($withParents);

        self::assertSame(self::WHOLE_CASES, $withParents);
    }

    /** lockrot's own report of a whole case document holds I25 too: its hydrated lock holds the same findings. */
    public function testLockrotsReportOfAWholeCaseHoldsTheExposureRelation(): void
    {
        $broken = [];
        foreach (self::WHOLE_CASES as $case) {
            foreach (ItemRelations::violations(JsonPath::decodeFile(self::CONTRACT.'report-2/'.basename($case)), true) as $violation) {
                $broken[] = basename($case).' '.$violation;
            }
        }

        self::assertSame([], $broken);
    }

    /** The page documents of lockrot's own runs, and the ‡ documents derived from them, hold every relation. */
    public function testTheEnginePageDocumentsHoldEveryRelation(): void
    {
        $paths = array_merge(glob(self::CONTRACT.'page/*.json') ?: [], glob(self::DOCUMENTS.'page/snipe-it.*.json') ?: []);
        self::assertCount(2, $paths);
        $broken = [];
        foreach ($paths as $path) {
            $document = JsonPath::decodeFile($path);
            $findings = array_values(array_filter(JsonPath::arrayAt($document, ['findings']), 'is_array'));
            foreach (array_merge(FindingRelations::violations($document), ItemRelations::violations($document, true), FindingRelations::moveCheckRelation($document, $findings)) as $violation) {
                $broken[] = basename($path).' '.$violation;
            }
        }

        self::assertSame([], $broken);
    }

    public function testEveryRecordedReportWithAMoveCheckSaysHowTheRunChecked(): void
    {
        $paths = array_merge(glob(self::CONTRACT.'report-2/*.json') ?: [], glob(self::CONTRACT.'page/*.json') ?: []);
        self::assertNotSame([], $paths);
        $broken = [];
        foreach ($paths as $path) {
            $document = JsonPath::decodeFile($path);
            $findings = array_values(array_filter(JsonPath::arrayAt($document, ['findings']), 'is_array'));
            foreach (FindingRelations::moveCheckRelation($document, $findings) as $violation) {
                $broken[] = basename($path).' '.$violation;
            }
        }

        self::assertSame([], $broken);
    }

    public function testAMoveThatRunsARequestWithNoCheckInACheckedRunBreaksTheRelation(): void
    {
        $document = JsonPath::decodeFile(__DIR__.'/../fixtures/contract/report-2/move-check-dagger.json');
        $findings = array_values(array_filter(JsonPath::arrayAt($document, ['findings']), 'is_array'));
        self::assertSame([], FindingRelations::moveCheckRelation($document, $findings));

        $findings[0] = self::edit($findings[0], ['next_step', 'check'], static fn (): ?array => null);

        self::assertSame([JsonPath::stringAt($findings[0], ['package']).': a move that runs a Composer request in a checked run has a check'], FindingRelations::moveCheckRelation($document, $findings));
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
