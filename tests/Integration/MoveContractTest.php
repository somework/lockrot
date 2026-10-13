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
 * `next_step` against docs/verdicts.md#move on every fixture document: each committed case, the composite
 * report, and each case with an `inputs` block as the real analyzer writes it. The model reference
 * compares the analyzer's move with the case's own where the inputs can say it.
 */
final class MoveContractTest extends TestCase
{
    private const VERSION = 'the model leaves `version` null on a move whose constraint comes from S8 : lockrot names the lowest admitted release';
    private const DATED = 'the model dates a branch by its newest release only : a lower release leaves old-promise unverified, where lockrot dates it';
    private const BY_POSITION = 'the model classes a higher branch `upgrade` by its position : no link of the inputs excludes the release, so lockrot classes it `update`';
    private const CLOCK = 'S2 reads the case run date, not the corpus clock: its years, and `quiet_years` with them, move by a tenth';
    private const SCORE = 'the inputs carry no date that S8 took from another branch: the hydrated score differs, so the move does too';
    private const S9_VERSION = 'the model leaves this S9 `fix.version` null : SPEC §5.3 names the lowest release that fixes the advisory, and lockrot writes it';
    private const LOWEST = 'the model leaves `lowest` null on a row whose only read release is its newest : lockrot names that release';

    /**
     * The fields of a hydrated finding that differ from the case's, each list with its reason. Every
     * other field of `next_step`, `also[]`, `security.move_in`, `security.fix_kind`, each branch
     * row's `fixes` (`rows.`) and each S9 `fix` (`s9.`) must be equal. Each reference also leaves
     * out the text, the replacement's url and finding, and each holder's finding: they read packages
     * that the inputs do not analyse.
     */
    private const DIFFERENCES = [
        'wp-config' => [[['latest', 'leaves'], self::SCORE]],
        'psr7' => [[['version'], self::VERSION], [['rows.lowest'], self::LOWEST]],
        'flysystem' => [[['fix_kind', 'security.fix_kind', 'rows.fix_kind', 's9.kind'], self::BY_POSITION], [['s9.version'], self::S9_VERSION]],
        'unreached-move' => [[['version', 'clears', 'unverified', 'clears_all', 'if_applied'], self::DATED]],
        'many-dependents' => [[['version'], self::VERSION]],
        'silent' => [[['quiet_years'], self::CLOCK]],
        'pagerfanta' => [[['next_step'], self::SCORE]],
        'guzzle-5' => [[['fix_kind', 'security.fix_kind', 'rows.fix_kind', 's9.kind'], self::BY_POSITION]],
        'routing' => [[['fix_kind', 'security.fix_kind', 'rows.fix_kind', 's9.kind'], self::BY_POSITION]],
        'B-also‡' => [[['also'], self::DATED], [['rows.lowest'], self::LOWEST], [['rows.if_applied'], self::DATED]],
        'A-php-star‡' => [[['fix_kind', 'security.fix_kind', 'rows.fix_kind', 's9.kind'], self::BY_POSITION], [['s9.version'], self::S9_VERSION]],
        'B-baselined‡' => [[['fix_kind', 'security.fix_kind', 'rows.fix_kind', 's9.kind'], self::BY_POSITION]],
        'tie‡' => [[['version', 'clears', 'also'], self::SCORE]],
        'B-dev‡' => [[['fix_kind', 'security.fix_kind', 'rows.fix_kind', 's9.kind'], self::BY_POSITION]],
        'B-offline‡' => [[['version', 'clears', 'unverified', 'clears_all', 'if_applied'], self::DATED]],
        'holder-vuln‡' => [[['version'], self::VERSION]],
        'partial-op‡' => [[['version'], self::VERSION]],
        'B-accepted‡' => [[['fix_kind', 'security.fix_kind', 'rows.fix_kind', 's9.kind'], self::BY_POSITION]],
        'unreached-dev‡' => [[['version'], self::VERSION]],
        'either-critical‡' => [[['also', 'security.fix_kind', 'rows.fix_kind', 's9.kind'], self::BY_POSITION]],
        'B-offline-high‡' => [[['version', 'clears', 'unverified', 'clears_all', 'if_applied'], self::DATED]],
        'fail-on-config‡' => [[['fix_kind', 'security.fix_kind', 'rows.fix_kind', 's9.kind'], self::BY_POSITION]],
        'fail-on-default‡' => [[['fix_kind', 'security.fix_kind', 'rows.fix_kind', 's9.kind'], self::BY_POSITION]],
        'require-dev-name‡' => [[['version'], self::VERSION]],
        'A' => [[['s9.version'], self::S9_VERSION]],
        'twig-1' => [[['rows.fix_kind', 's9.kind'], self::BY_POSITION], [['s9.version'], self::S9_VERSION]],
        'xmlseclibs' => [[['rows.fix_kind'], self::BY_POSITION], [['rows.lowest'], self::LOWEST], [['s9.version'], self::S9_VERSION]],
        'commonmark' => [[['s9.version'], self::S9_VERSION]],
        'guzzle-mixed' => [[['rows.fix_kind', 's9.kind'], self::BY_POSITION]],
        'A-blocked‡' => [[['s9.version'], self::S9_VERSION]],
        'A-baselined‡' => [[['s9.version'], self::S9_VERSION]],
        'twig-dev‡' => [[['s9.version'], self::S9_VERSION]],
        'partial' => [[['rows.fix_kind', 's9.kind'], self::BY_POSITION]],
        'feeds-drupal‡' => [[['s9.version'], self::S9_VERSION]],
        'A-gate-vulnerable' => [[['s9.version'], self::S9_VERSION]],
        'A-gate-critical' => [[['s9.version'], self::S9_VERSION]],
    ];

    private const NO_SCORE_AFTER = ['replace', 'find-alternative', 'test', 'blocked', 'no-tag', 'no-move', 'no-fix', 'no-single-fix'];
    private const BRANCH_KINDS = ['update', 'require', 'raise-php', 'blocked', 'tag'];

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

    /** @return iterable<string, array{array<mixed, mixed>, list<array<mixed, mixed>>}> */
    public static function findingsWithRows(): iterable
    {
        foreach (JsonPath::arrayAt(JsonPath::decodeFile(CaseHydrator::CASES), ['cases']) as $case) {
            self::assertIsArray($case);
            $metadata = \is_array($case['details'] ?? null) ? ($case['details']['metadata'] ?? null) : null;
            $rows = [];
            foreach (\is_array($metadata) && \is_array($metadata['branches'] ?? null) ? $metadata['branches'] : [] as $row) {
                self::assertIsArray($row);
                $rows[] = $row;
            }
            yield 'case '.JsonPath::stringAt($case, ['id']) => [JsonPath::arrayAt($case, ['finding']), $rows];
        }
        $rows = CaseHydrator::branchRows();
        foreach (CaseHydrator::findings() as $id => $finding) {
            yield 'lockrot '.$id => [$finding, $rows[$id]];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function casesWithInputs(): iterable
    {
        foreach (array_keys(CaseHydrator::findings()) as $id) {
            yield $id => [$id];
        }
    }

    /**
     * what a move clears, leaves and cannot check partition the counted facts,
     * and `if_applied` is a rerun only where the move clears something.
     *
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
    public function testEveryMovePartitionsTheCountedFacts(array $finding): void
    {
        if ($finding['next_step'] === null) {
            self::assertSame([], self::countedFacts($finding), 'a finding with no move counts no fact');

            return;
        }
        $counted = self::countedFacts($finding);
        $fired = array_column(JsonPath::arrayAt($finding, ['flags']), 'id');
        foreach (self::moves($finding) as $move) {
            $clears = self::keys($move['clears']);
            $unverified = self::keys($move['unverified']);
            $leaves = self::keys($move['leaves']);
            $adds = self::keys($move['adds']);
            self::assertSame([], array_intersect($clears, $unverified), 'clears and unverified are disjoint');
            self::assertSame([], array_intersect($leaves, array_merge($clears, $unverified)), 'leaves is disjoint from both');
            self::assertSame([], array_diff($counted, array_merge($clears, $unverified, $leaves)), 'together they hold every counted fact');
            self::assertSame([], array_diff(array_merge($clears, $leaves), $counted), 'clears and leaves hold counted facts only');
            foreach (array_diff($unverified, $counted) as $extra) {
                self::assertStringStartsWith('flag ', $extra, 'an unverified fact outside them is a flag the move may raise');
            }
            self::assertSame([], array_intersect($adds, array_merge($clears, $unverified, $leaves)), 'adds is disjoint from all three');
            foreach (JsonPath::arrayAt($move, ['adds']) as $add) {
                self::assertIsArray($add);
                self::assertNotContains($add['id'], $fired, 'adds names no fact that fired');
            }
            self::assertSame($clears !== [] && $leaves === [] && $unverified === [], $move['clears_all']);
            $ifApplied = $move['if_applied'];
            if (\in_array($move['kind'], self::NO_SCORE_AFTER, true)) {
                self::assertNull($ifApplied, ''.$move['kind'].' has no score after it');
            }
            if ($ifApplied === null) {
                continue;
            }
            self::assertIsArray($ifApplied);
            $assumes = $ifApplied['assumes'];
            self::assertIsArray($assumes);
            self::assertNotSame([], $clears, 'a rerun only with a non-empty clears');
            self::assertSame($unverified !== [], $ifApplied['at_most'], 'at_most exactly when a fact is unverified');
            self::assertContains('clears_hold', $assumes);
            self::assertSame($move['held_by'] !== [] || $move['composer_moves_because'] !== [], \in_array('composer_resolves', $assumes, true));
            if ($ifApplied['verdict'] === 'finished') {
                self::assertNotNull($finding['allowlist'], 'finished only under an allowlist entry');
            }
        }
        $partial = JsonPath::arrayAt($finding, ['security'])['partial'] ?? null;
        if (\is_array($partial)) {
            self::assertSame(JsonPath::arrayAt($partial, ['unverified']) !== [], JsonPath::boolAt($partial, ['if_applied', 'at_most']));
            foreach (self::moves($finding) as $move) {
                self::assertFalse($move['kind'] === 'update' && $move['version'] === $partial['version'], 'no partial beside an update move to the same release');
            }
        }
    }

    /**
     * the shape of each kind, commands as argv lists, no root requirement of a package
     * the project does not require, and `through[]` on the lead move only.
     *
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
    public function testEveryMoveHasTheShapeOfItsKind(array $finding): void
    {
        $graded = \in_array($finding['verdict'], ['critical', 'high', 'medium', 'low'], true);
        self::assertSame($graded, $finding['next_step'] !== null, 'a grade has a move, a score-0 word none');
        foreach (self::moves($finding) as $i => $move) {
            $kind = JsonPath::stringAt($move, ['kind']);
            self::assertSame(\in_array($kind, ['require', 'raise-php'], true), \is_string($move['constraint']), ''.$kind.' and its constraint');
            if (\in_array($kind, self::BRANCH_KINDS, true)) {
                self::assertNotNull($move['php_check'], ''.$kind.' names a branch, so it has php_check');
            }
            self::assertSame($kind === 'no-move', $move['reason'] !== null, 'reason exactly on no-move');
            foreach (JsonPath::arrayAt($move, ['commands']) as $argv) {
                self::assertIsArray($argv);
                self::assertSame('composer', $argv[0] ?? null);
                self::assertSame(array_values($argv), $argv);
                foreach ($argv as $argument) {
                    self::assertIsString($argument);
                }
                if ($finding['reach'] !== 'direct') {
                    $target = $argv[2] ?? '';
                    self::assertIsString($target);
                    self::assertFalse($argv[1] === 'require' && strpos($target, JsonPath::stringAt($finding, ['package']).':') === 0, 'no root requirement of a package the project does not require');
                }
            }
            self::assertSame($i === 0, \array_key_exists('through', $move), 'through[] on the lead move only');
            if ($i === 0) {
                self::assertThrough($finding, $move);
            }
        }
    }

    /**
     * `security.move_in` points at the move that clears the advisories, which agrees with the
     * security fields, with its branch row and with each S9 row at its release.
     *
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
    public function testTheSecurityMoveIsPublishedOnceAndAgreesWithItsRow(array $finding): void
    {
        $security = JsonPath::arrayAt($finding, ['security']);
        if ($security['status'] !== 'vulnerable') {
            self::assertArrayNotHasKey('move_in', $security);

            return;
        }
        $move = null;
        if ($security['move_in'] === 'next_step') {
            $move = JsonPath::arrayAt($finding, ['next_step']);
            $cleared = self::advisoryClears($move);
            self::assertTrue($move['source'] === 'vulnerable' || \in_array('vulnerable', JsonPath::arrayAt($move, ['merged']), true) || array_diff(self::advisoryIds($finding), $cleared) === [], 'move_in names the move that clears the advisories');
        } elseif ($security['move_in'] === 'also') {
            $move = JsonPath::arrayAt($finding, ['next_step', 'also', 0]);
            self::assertSame('vulnerable', $move['source']);
        }
        if ($move === null) {
            return;
        }
        self::assertSame($move['fix_kind'], $security['fix_kind'], 'security.fix_kind is the fix kind of that move');
        foreach (self::s9Advisories($finding) as $row) {
            self::assertIsArray($row);
            $fix = JsonPath::arrayAt($row, ['fix']);
            if ($fix['to_branch'] === $move['to_branch'] && $fix['version'] === $move['version']) {
                self::assertSame(self::holds($move['held_by']), self::holds($fix['held_by']), JsonPath::stringAt($row, ['id']).': an S9 row at the move\'s release has its holders (I19)');
            }
        }
        $gets = $security['gets'];
        if ($move['kind'] === 'update' && \is_array($gets) && $move['source'] === 'vulnerable') {
            self::assertSame(self::advisoryClears($gets), self::advisoryClears($move), 'an update clears what gets clears');
        }
        $installed = $security['installed_branch_fixes'];
        if (\is_array($installed) && $move['source'] === 'vulnerable' && $move['merged'] === [] && $move['to_branch'] === $finding['branch'] && \in_array($move['kind'], ['update', 'require', 'raise-php'], true)) {
            self::assertSame($installed['lowest'], $move['version'], 'the move to the installed branch is its row candidate');
            self::assertSame($installed['fix_kind'], $move['fix_kind']);
            self::assertSame(self::rerunRow($move['if_applied']), $installed['if_applied']);
        }
    }

    /**
     * SPEC §5.3 and I19 on every branch row: `fixes.newest` is the row's newest tag, `if_applied`
     * is null exactly on a row that fixes nothing or is `blocked`, and such a row has no holder.
     * The security move agrees with its row on the release, the class, `held_by` and the score after
     * it. A merged move, or a maintenance move that clears the advisories, agrees only when its
     * release is the row's candidate.
     *
     * @param array<mixed, mixed>       $finding
     * @param list<array<mixed, mixed>> $rows
     *
     * @dataProvider findingsWithRows
     *
     * @coversNothing
     *
     * @group covers-nothing
     */
    #[CoversNothing]
    #[Group('covers-nothing')]
    #[DataProvider('findingsWithRows')]
    public function testEveryBranchRowAgreesWithTheSecurityMove(array $finding, array $rows): void
    {
        $byBranch = [];
        foreach ($rows as $row) {
            $branch = JsonPath::stringAt($row, ['branch']);
            $byBranch[$branch] = $row;
            $fixes = $row['fixes'];
            if (!\is_array($fixes)) {
                continue;
            }
            self::assertSame($row['highest'], $fixes['newest'], $branch.': fixes.newest is the row\'s newest tag');
            self::assertSame($fixes['fixed'] === 0 || $fixes['fix_kind'] === 'blocked', $fixes['if_applied'] === null, $branch.': if_applied is null exactly on a row that fixes nothing or is blocked');
            if ($fixes['fixed'] === 0) {
                self::assertSame([], $fixes['held_by'], $branch.': a row that fixes nothing has no holder');
            }
        }
        self::assertCount(\count($rows), $byBranch, 'one row per branch');
        $security = JsonPath::arrayAt($finding, ['security']);
        $moveIn = $security['move_in'] ?? null;
        $move = $moveIn === 'next_step' ? $finding['next_step'] : ($moveIn === 'also' ? JsonPath::arrayAt($finding, ['next_step', 'also', 0]) : null);
        $what = \is_array($move) && \is_string($move['to_branch']) ? $move['to_branch'] : '';
        $fixes = \is_array($move) && \in_array($move['kind'], ['update', 'require', 'raise-php'], true) ? ($byBranch[$what]['fixes'] ?? null) : null;
        if (\is_array($move) && \is_array($fixes) && $fixes['fixed'] > 0 && (($move['source'] === 'vulnerable' && $move['merged'] === []) || $move['version'] === $fixes['lowest'])) {
            self::assertSame($fixes['lowest'], $move['version'], $what.': the security move is its row\'s candidate');
            self::assertSame($fixes['fix_kind'], $move['fix_kind'], $what.': the move and its row have one class');
            self::assertSame(self::holds($fixes['held_by']), self::holds($move['held_by']), $what.': the move and its row have the same holders');
            self::assertSame($fixes['if_applied'], self::rerunRow($move['if_applied']), $what.': the move and its row have the same score after it');
        }
        $partial = $security['partial'] ?? null;
        $installed = null;
        foreach ($rows as $row) {
            $installed = $row['installed'] === true && \is_array($row['fixes']) ? $row['fixes'] : $installed;
        }
        if (\is_array($partial) && \is_array($installed) && $partial['version'] === $installed['lowest']) {
            self::assertSame($installed['if_applied'], self::rerunRow($partial['if_applied']), 'security.partial and the installed row have the same score after it');
        }
    }

    /**
     * The model reference: the analyzer's move on a case's inputs is the case's own move, but for
     * the fields {@see DIFFERENCES} names with its reason.
     *
     * @dataProvider casesWithInputs
     *
     * @coversNothing
     *
     * @group covers-nothing
     */
    #[CoversNothing]
    #[Group('covers-nothing')]
    #[DataProvider('casesWithInputs')]
    public function testTheAnalyzerWritesTheMoveTheCaseRecords(string $id): void
    {
        $case = CaseHydrator::case($id);
        $golden = JsonPath::arrayAt($case, ['finding']);
        $goldenRows = JsonPath::arrayAt($case, ['details', 'metadata', 'branches']);
        $lockrot = CaseHydrator::findings()[$id];
        $lockrotRows = CaseHydrator::branchRows()[$id];
        $skipped = [];
        foreach (self::DIFFERENCES[$id] ?? [] as [$fields]) {
            foreach ($fields as $field) {
                $skipped[] = $field;
            }
        }
        if (\in_array('next_step', $skipped, true)) {
            self::assertNotSame(JsonPath::stringAt($golden, ['score', 'text']), JsonPath::stringAt($lockrot, ['score', 'text']), $id.': the scores differ');

            return;
        }
        $expected = self::reference($golden, $goldenRows, $skipped);
        $actual = self::reference($lockrot, $lockrotRows, $skipped);

        self::assertSame($expected, $actual, $id);
        if ($skipped !== []) {
            self::assertNotSame(self::reference($golden, $goldenRows, []), self::reference($lockrot, $lockrotRows, []), $id.': a listed field agrees again: take the case off DIFFERENCES');
        }
    }

    /**
     * @param array<mixed, mixed> $finding
     *
     * @return list<array<mixed, mixed>> the lead move, then its also[] moves
     */
    private static function moves(array $finding): array
    {
        if (!\is_array($finding['next_step'])) {
            return [];
        }
        $moves = [JsonPath::arrayAt($finding, ['next_step'])];
        foreach (JsonPath::arrayAt($finding, ['next_step', 'also']) as $also) {
            self::assertIsArray($also);
            $moves[] = $also;
        }

        return $moves;
    }

    /**
     * @param array<mixed, mixed> $finding
     *
     * @return list<string> `kind id` of every counted fact
     */
    private static function countedFacts(array $finding): array
    {
        $facts = [];
        foreach (JsonPath::has($finding, ['score', 'terms']) ? JsonPath::arrayAt($finding, ['score', 'terms']) : [] as $term) {
            self::assertIsArray($term);
            if ($term['part'] === 'maintenance') {
                $facts[] = 'flag '.JsonPath::stringAt($term, ['flag']);
            }
        }
        foreach (self::advisoryIds($finding) as $id) {
            $facts[] = 'advisory '.$id;
        }

        return $facts;
    }

    /**
     * @param array<mixed, mixed> $finding
     *
     * @return list<string>
     */
    private static function advisoryIds(array $finding): array
    {
        return self::ids(self::s9Advisories($finding), static fn (): bool => true);
    }

    /**
     * @param array<mixed, mixed> $finding
     *
     * @return array<mixed, mixed> S9's advisory rows, in S9 order
     */
    private static function s9Advisories(array $finding): array
    {
        foreach (JsonPath::arrayAt($finding, ['signals']) as $signal) {
            self::assertIsArray($signal);
            if ($signal['id'] === 'S9') {
                return JsonPath::arrayAt($signal, ['data', 'advisories']);
            }
        }

        return [];
    }

    /**
     * @param mixed $facts
     *
     * @return list<string> `kind id`
     */
    private static function keys($facts): array
    {
        self::assertIsArray($facts);
        $keys = [];
        foreach ($facts as $fact) {
            self::assertIsArray($fact);
            $keys[] = JsonPath::stringAt($fact, ['kind']).' '.JsonPath::stringAt($fact, ['id']);
        }

        return $keys;
    }

    /**
     * @param array<mixed, mixed> $move a move, or `security.gets`
     *
     * @return list<string> the advisory ids that it clears
     */
    private static function advisoryClears(array $move): array
    {
        return self::ids(JsonPath::arrayAt($move, ['clears']), static fn (array $fact): bool => $fact['kind'] === 'advisory');
    }

    /**
     * @param array<mixed, mixed>               $rows
     * @param callable(array<mixed, mixed>): bool $keep
     *
     * @return list<string> the `id` of each row that $keep keeps
     */
    private static function ids(array $rows, callable $keep): array
    {
        $ids = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            if ($keep($row)) {
                $ids[] = JsonPath::stringAt($row, ['id']);
            }
        }

        return $ids;
    }

    /**
     * one entry per direct dependent of a transitive package, `[]` otherwise, and a `why-not`
     * command for each dependent whose newest release no longer requires the package.
     *
     * @param array<mixed, mixed> $finding
     * @param array<mixed, mixed> $move
     */
    private static function assertThrough(array $finding, array $move): void
    {
        $through = JsonPath::arrayAt($move, ['through']);
        if ($finding['reach'] !== 'transitive') {
            self::assertSame([], $through, '[] on a direct or unreached package');

            return;
        }
        self::assertSame($finding['direct_dependents'], array_column($through, 'package'), 'direct_dependents order');
        $whyNot = [];
        foreach ($through as $entry) {
            self::assertIsArray($entry);
            self::assertSame($entry['newest_requires'] === null, $entry['newest_requires_unmeasured'] !== null);
            if ($entry['newest_requires'] !== null) {
                self::assertTrue($entry['installed_requires']);
                self::assertNotNull($entry['newest_stable']);
            }
            self::assertSame($entry['installed_requires'] === false, $entry['newest_requires_unmeasured'] === 'through_other_package');
            if ($entry['newest_requires'] === false) {
                $whyNot[] = ['composer', 'why-not', $entry['package'], JsonPath::stringAt($entry, ['newest_stable', 'version'])];
            }
        }
        self::assertSame($whyNot, array_values(array_filter(JsonPath::arrayAt($move, ['commands']), static fn ($argv): bool => \is_array($argv) && ($argv[1] ?? null) === 'why-not')));
    }

    /**
     * @param mixed $ifApplied
     *
     * @return array<mixed, mixed>|null a move's `if_applied` as a branch row writes it
     */
    private static function rerunRow($ifApplied): ?array
    {
        if (!\is_array($ifApplied)) {
            return null;
        }
        unset($ifApplied['lead'], $ifApplied['deciding_advisory']);

        return $ifApplied;
    }

    /**
     * @param mixed $heldBy
     *
     * @return list<array<mixed, mixed>> each holder without its version and its finding
     */
    private static function holds($heldBy): array
    {
        self::assertIsArray($heldBy);
        $holds = [];
        foreach ($heldBy as $holder) {
            self::assertIsArray($holder);
            $holds[] = array_intersect_key($holder, ['source' => true, 'package' => true, 'link' => true, 'constraint' => true]);
        }

        return $holds;
    }

    /**
     * The move, the security fields, each branch row's `fixes` and each S9 `fix` that the reference
     * compares, without $skipped. A `rows.` or `s9.` name skips that field of every row or fix.
     *
     * @param array<mixed, mixed> $finding
     * @param array<mixed, mixed> $rows
     * @param list<string>        $skipped
     *
     * @return array<mixed, mixed>
     */
    private static function reference(array $finding, array $rows, array $skipped): array
    {
        $move = self::comparable($finding['next_step']);
        $security = JsonPath::arrayAt($finding, ['security']);
        $fixes = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            $fixes[JsonPath::stringAt($row, ['branch'])] = \is_array($row['fixes']) ? self::withoutHolders($row['fixes']) : null;
        }
        $s9 = [];
        foreach (JsonPath::arrayAt($finding, ['signals']) as $signal) {
            self::assertIsArray($signal);
            foreach ($signal['id'] === 'S9' ? JsonPath::arrayAt($signal, ['data', 'advisories']) : [] as $advisory) {
                self::assertIsArray($advisory);
                $s9[JsonPath::stringAt($advisory, ['id'])] = self::withoutHolders(JsonPath::arrayAt($advisory, ['fix']));
            }
        }
        $out = ['next_step' => $move, 'security.move_in' => $security['move_in'] ?? null, 'security.fix_kind' => $security['fix_kind'] ?? null, 'rows' => $fixes, 's9' => $s9];
        foreach ($skipped as $field) {
            unset($out[$field]);
            if (\is_array($out['next_step'] ?? null)) {
                unset($out['next_step'][$field]);
            }
            foreach (['rows', 's9'] as $list) {
                if (strpos($field, $list.'.') === 0 && \is_array($out[$list] ?? null)) {
                    foreach ($out[$list] as $key => $each) {
                        if (\is_array($each)) {
                            unset($each[substr($field, \strlen($list) + 1)]);
                            $out[$list][$key] = $each;
                        }
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param array<mixed, mixed> $fix a branch row's `fixes` or an S9 `fix`
     *
     * @return array<mixed, mixed> without each holder's finding
     */
    private static function withoutHolders(array $fix): array
    {
        $holders = [];
        foreach (JsonPath::arrayAt($fix, ['held_by']) as $holder) {
            self::assertIsArray($holder);
            unset($holder['holder']);
            $holders[] = $holder;
        }
        $fix['held_by'] = $holders;

        return $fix;
    }

    /**
     * @param mixed $move
     *
     * @return array<mixed, mixed>|null
     */
    private static function comparable($move): ?array
    {
        if (!\is_array($move)) {
            return null;
        }
        unset($move['text']);
        if (\is_array($move['replacement'])) {
            unset($move['replacement']['url'], $move['replacement']['finding']);
        }
        $holders = [];
        foreach (JsonPath::arrayAt($move, ['held_by']) as $holder) {
            self::assertIsArray($holder);
            unset($holder['holder']);
            $holders[] = $holder;
        }
        $move['held_by'] = $holders;
        foreach (['clears', 'unverified', 'leaves', 'adds'] as $list) {
            $keys = [];
            foreach (JsonPath::arrayAt($move, [$list]) as $fact) {
                $keys[] = (string) json_encode($fact);
            }
            sort($keys);
            $move[$list] = $keys;
        }
        if (\array_key_exists('also', $move)) {
            $also = [];
            foreach (JsonPath::arrayAt($move, ['also']) as $each) {
                $also[] = self::comparable($each);
            }
            $move['also'] = $also;
        }

        return $move;
    }
}
