<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\MultiConstraint;
use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;
use Lockrot\Security\Holder;
use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\Golden;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\GradedPackage;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * `next_step` as the real analyzer writes it (docs/verdicts.md#move), on the committed cases and on small locks
 * that each test writes. The base lock: a/b 1.0.0 on 1.x, last released 2020, while 2.x needs
 * php >=8.1 above the project's >=7.4 and released in 2026.
 */
final class MoveTest extends TestCase
{
    /** The day that the scenarios' release dates are set against: "the warn-years edge" is three years before it. */
    private const CLOCK = '2026-10-14T00:00:00+00:00';
    private const ADVISORY = ['id' => 'PKSA-1', 'severity' => 'high', 'affected_versions' => '<1.9.0'];

    /** @return iterable<string, array{string}> */
    public static function casesWithInputs(): iterable
    {
        $cases = JsonPath::arrayAt(JsonPath::decodeFile(CaseHydrator::CASES), ['cases']);
        self::assertNotSame([], $cases);
        foreach ($cases as $case) {
            self::assertIsArray($case);
            if (\is_array($case['inputs'] ?? null)) {
                yield JsonPath::stringAt($case, ['id']) => [JsonPath::stringAt($case, ['id'])];
            }
        }
    }

    /**
     * Every advisory that a move clears lies outside its range for every release the move admits:
     * from its `version` up to its `newest`.
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
    public function testEveryClearedAdvisoryLiesOutsideItsRangeForEveryAdmittedRelease(string $id): void
    {
        $case = CaseHydrator::case($id);
        $finding = self::hydrated($case);
        $ranges = [];
        foreach (JsonPath::arrayAt($case, ['inputs', 'advisories']) as $advisory) {
            self::assertIsArray($advisory);
            $ranges[JsonPath::stringAt($advisory, ['id'])] = $advisory['affected_versions'];
        }
        $parser = new VersionParser();
        $moves = $finding['next_step'] === null ? [] : array_merge([$finding['next_step']], JsonPath::arrayAt($finding, ['next_step', 'also']));
        foreach ($moves as $move) {
            self::assertIsArray($move);
            foreach (JsonPath::arrayAt($move, ['clears']) as $fact) {
                self::assertIsArray($fact);
                if ($fact['kind'] !== 'advisory') {
                    continue;
                }
                $span = new MultiConstraint([new Constraint('>=', $parser->normalize(JsonPath::stringAt($move, ['version']))), new Constraint('<=', $parser->normalize(JsonPath::stringAt($move, ['newest'])))]);
                $range = $ranges[JsonPath::stringAt($fact, ['id'])];
                self::assertIsString($range);
                self::assertFalse(Intervals::haveIntersections($parser->parseConstraints($range), $span), $id.': '.JsonPath::stringAt($fact, ['id']).' in '.JsonPath::stringAt($move, ['version']).' to '.JsonPath::stringAt($move, ['newest']));
            }
        }
    }

    /**
     * The move and the security fields that it sets, for every case with inputs, held in a golden file.
     * A failure names the case whose output moved.
     */
    public function testEveryCaseWritesTheMoveInItsGoldenFile(): void
    {
        // Run the analyzer here, not through CaseHydrator::findings(): code that a data provider runs
        // covers no test, so mutation testing skips a test that reads it.
        $golden = [];
        foreach (self::casesWithInputs() as $id => [$case]) {
            $finding = self::hydrated(CaseHydrator::case($case));
            $security = JsonPath::arrayAt($finding, ['security']);
            $golden[$id] = ['next_step' => $finding['next_step']] + array_intersect_key($security, array_flip(['fix_kind', 'installed_branch_fixes', 'move_in', 'partial']));
        }
        self::assertNotSame([], $golden);
        ksort($golden);

        Golden::assertMatches('moves.json', $golden, 'testEveryCaseWritesTheMoveInItsGoldenFile');
    }

    /** The same fields for each lock of {@see scenarios()}, each built to reach one branch of the move rules. */
    public function testEveryScenarioWritesTheMoveInItsGoldenFile(): void
    {
        $golden = [];
        foreach (self::scenarios() as $name => $over) {
            $finding = self::analyse($over);
            $security = JsonPath::arrayAt($finding, ['security']);
            $golden[$name] = ['next_step' => $finding['next_step']] + array_intersect_key($security, array_flip(['fix_kind', 'installed_branch_fixes', 'move_in', 'partial']));
        }

        Golden::assertMatches('move-scenarios.json', $golden, 'testEveryScenarioWritesTheMoveInItsGoldenFile');
    }

    /**
     * Filling the holders and the replacement writes each one's own finding into a copy, and the
     * finding that the copy came from keeps what it had. h/old holds the security move, and the
     * registry names it as the replacement.
     */
    public function testFillingTheHoldersLeavesTheOriginalFindingAsItWas(): void
    {
        $inputs = self::inputs([
            'lock' => [[], ['name' => 'h/old', 'version' => '1.0.0', 'require' => ['a/b' => '<1.5']]],
            'abandoned' => ['by' => 'repository', 'replacement' => 'h/old'],
            'advisories' => [self::advisory('PKSA-1', 'critical', '<1.9.0')],
        ]);
        $finding = null;
        foreach (CaseHydrator::report(['id' => 'move', 'clock' => self::CLOCK, 'inputs' => $inputs])->findings() as $each) {
            $finding = $each->package() === 'a/b' ? $each : $finding;
        }
        self::assertInstanceOf(Finding::class, $finding);
        $before = JsonPath::decoded($finding->toArray());
        $bare = (new FindingBuilder())->withPackage('a/b')->build();

        $copy = $finding->withHolders(['h/old' => new GradedPackage('critical', null, ['vulnerable'], null, null)]);
        $filled = JsonPath::decoded($copy->toArray());
        $moved = $bare->withMoves($finding->details()->moves());
        $lead = $finding->nextStep();
        self::assertNotNull($lead);
        $fixKind = $lead->fixKind();
        $lead->withFixKind('blocked');
        $also = $lead->also();
        self::assertNotSame([], $also);
        $lead->asLead([], []);

        self::assertSame($before, JsonPath::decoded($finding->toArray()));
        self::assertSame($fixKind, $lead->fixKind());
        self::assertSame($also, $lead->also());
        self::assertNotSame('blocked', $fixKind);
        self::assertNull($bare->nextStep());
        self::assertSame($finding->nextStep(), $moved->nextStep());
        $moves = array_merge([JsonPath::arrayAt($filled, ['next_step'])], JsonPath::arrayAt($filled, ['next_step', 'also']));
        $replacements = [];
        $holders = ['the moves' => [], 'the installed branch' => JsonPath::arrayAt($filled, ['security', 'installed_branch_fixes', 'held_by']), 'S9' => []];
        foreach ($moves as $move) {
            self::assertIsArray($move);
            $holders['the moves'] = array_merge($holders['the moves'], JsonPath::arrayAt($move, ['held_by']));
            $replacements[] = \is_array($move['replacement']) ? $move['replacement']['finding'] : null;
        }
        self::assertContains(['verdict' => 'critical', 'lead' => null, 'flag_ids' => ['vulnerable']], $replacements);
        foreach (JsonPath::arrayAt($filled, ['signals']) as $signal) {
            self::assertIsArray($signal);
            foreach ($signal['id'] === 'S9' ? JsonPath::arrayAt($signal, ['data', 'advisories']) : [] as $row) {
                self::assertIsArray($row);
                $holders['S9'] = array_merge($holders['S9'], JsonPath::arrayAt($row, ['fix', 'held_by']));
            }
        }
        foreach ($holders as $where => $list) {
            $own = array_values(array_filter($list, static fn ($holder): bool => \is_array($holder) && $holder['source'] === 'package'));
            self::assertSame([['verdict' => 'critical', 'lead' => null, 'flag_ids' => ['vulnerable'], 'replacement' => null, 'next_step' => null]], array_column($own, 'holder'), $where);
        }
        self::assertStringContainsString('h/old 1.0.0 (requires <1.5; itself critical: vulnerable)', implode("\n", array_map(static fn ($move): string => \is_array($move) ? JsonPath::stringAt($move, ['text']) : '', $moves)));
        $fixes = $copy->details()->fixes();
        self::assertNotNull($fixes);
        $verdicts = [];
        foreach ($fixes->forAdvisory('PKSA-1')->heldBy() as $each) {
            $graded = $each->finding();
            $verdicts[] = $each->source() === Holder::PACKAGE && $graded !== null ? $graded->verdict() : null;
        }
        self::assertContains('critical', $verdicts, 'the advisory\'s own fix');
    }

    /** monolog-bridge: S8 suggests ^5.4, the advisory fixes 5.x from 5.4.52, so the merged move is ^5.4.52. */
    public function testMonologBridgeMergesIntoTheTightestConstraint(): void
    {
        $move = JsonPath::arrayAt(self::hydrated(CaseHydrator::case('B')), ['next_step']);

        self::assertSame(['require', '^5.4.52', 'v5.4.52'], [$move['kind'], $move['constraint'], $move['version']]);
        self::assertSame([['composer', 'require', 'symfony/monolog-bridge:^5.4.52']], $move['commands']);
        self::assertContains('vulnerable', array_merge([$move['source']], JsonPath::arrayAt($move, ['merged'])));
    }

    /** twig: PKSA-8zx5 `<=3.26.0` names no exclusive bound, and v3.27.0 clears every advisory. */
    public function testTwigRaisesPhpThenRequiresTheFirstReleaseThatClearsEveryAdvisory(): void
    {
        $finding = self::hydrated(CaseHydrator::case('A'));
        $move = JsonPath::arrayAt($finding, ['next_step']);

        self::assertSame(['raise-php', '^3.27.0', 'raise-php'], [$move['kind'], $move['constraint'], $move['fix_kind']]);
        self::assertSame([['composer', 'require', 'php:>=8.1', '--no-update'], ['composer', 'require', 'twig/twig:^3.27.0']], $move['commands']);
        self::assertSame(['next_step', 'raise-php'], [JsonPath::stringAt($finding, ['security', 'move_in']), JsonPath::stringAt($finding, ['security', 'fix_kind'])]);
        self::assertSame(['holders', 'crosses_major'], $move['composer_moves_because']);
        self::assertSame(['clears_hold', 'composer_resolves'], JsonPath::arrayAt($move, ['if_applied', 'assumes']));
        self::assertSame('symfony/twig-bridge', JsonPath::stringAt($move, ['held_by', 1, 'package']));
        self::assertTrue(JsonPath::has($move, ['held_by', 1, 'holder', 'verdict']), 'the second pass names the holder\'s own finding');
        foreach (JsonPath::arrayAt($finding, ['signals']) as $signal) {
            self::assertIsArray($signal);
            if ($signal['id'] === 'S9') {
                self::assertTrue(JsonPath::has($signal, ['data', 'advisories', 0, 'fix', 'held_by', 1, 'holder', 'verdict']), 'and so does each S9 row');
            }
        }
    }

    /**
     * prestashop-v1's symfony/http-client 4.4.26: `^5.4.47` admits 5.4.47 to 5.4.52, inside
     * PKSA-35by, which 4.4.26 does not count. The lower bound passes it.
     */
    public function testTheLowerBoundPassesAnAdvisoryOnTheMovesOwnVersions(): void
    {
        $finding = self::analyse([
            'root' => ['require' => ['symfony/http-client' => '*', 'a/b' => null]],
            'lock' => [['name' => 'symfony/http-client', 'version' => 'v4.4.26', 'php' => '>=7.1.3']],
            'releases' => [self::release('v4.4.26', '2021-06-01', '>=7.1.3'), self::release('v5.4.47', '2024-11-01', '>=7.2.5'), self::release('v5.4.52', '2025-05-01', '>=7.2.5'), self::release('v5.4.53', '2025-06-01', '>=7.2.5')],
            'advisories' => [['id' => 'PKSA-a', 'severity' => 'medium', 'affected_versions' => '<5.4.47']],
            'other_advisories' => [['id' => 'PKSA-35by-yxtt-jc85', 'severity' => 'medium', 'affected_versions' => '>=5.4.0,<5.4.53']],
        ]);

        self::assertSame(['require', '^5.4.53', 'v5.4.53'], [JsonPath::stringAt($finding, ['next_step', 'kind']), JsonPath::stringAt($finding, ['next_step', 'constraint']), JsonPath::stringAt($finding, ['next_step', 'version'])]);
    }

    /** A package the project does not require gets no root `composer require`, and names what it comes in through. */
    public function testATransitiveMoveWritesNoRequireCommand(): void
    {
        $move = JsonPath::arrayAt(self::hydrated(CaseHydrator::case('routing')), ['next_step']);

        self::assertSame('require', $move['kind']);
        self::assertSame([], $move['commands']);
        self::assertSame(['crosses_major', 'transitive'], $move['composer_moves_because']);
        self::assertStringStartsWith('move symfony/routing to ^5.4.53 through mautic/core-lib', JsonPath::stringAt($move, ['text']));
    }

    /** Offline, the move's own versions were never asked about: every rerun says so. */
    public function testAnOfflineMoveAssumesNoNewAdvisories(): void
    {
        $move = JsonPath::arrayAt(self::hydrated(CaseHydrator::case('B-offline‡')), ['next_step']);
        $moves = array_merge([$move], JsonPath::arrayAt($move, ['also']));

        self::assertNotSame([], $moves);
        foreach ($moves as $each) {
            self::assertIsArray($each);
            self::assertContains('no_new_advisories', JsonPath::arrayAt($each, ['if_applied', 'assumes']));
        }
    }

    /** Composer's list ignores the marking, so the archived repository alone leads, and the ignored replacement is never the move. */
    public function testAnIgnoredMarkingNeverNamesItsReplacement(): void
    {
        $move = JsonPath::arrayAt(self::hydrated(CaseHydrator::case('ignored-marking-archived‡')), ['next_step']);

        self::assertSame(['find-alternative', 'abandoned', null], [$move['kind'], $move['source'], $move['replacement']]);
    }

    /** With no branch within reach, the lowest higher branch that releases and runs on the target, from its newest minor. */
    public function testALeftBehindPackageWithNoBranchInReachRaisesPhpToTheNextReleasingBranch(): void
    {
        $move = JsonPath::arrayAt(self::analyse([]), ['next_step']);

        self::assertSame(['raise-php', 'left-behind', '2.x', '^2.3', '2.3.0', '2.3.0'], [$move['kind'], $move['source'], $move['to_branch'], $move['constraint'], $move['version'], $move['newest']]);
        self::assertSame([['composer', 'require', 'php:>=8.1', '--no-update'], ['composer', 'require', 'a/b:^2.3']], $move['commands']);
        self::assertSame([['source' => 'root', 'package' => null, 'version' => null, 'link' => 'require', 'constraint' => '^1.0', 'holder' => null]], $move['held_by']);
        self::assertSame(['crosses_major'], $move['composer_moves_because']);
        self::assertSame(['left-behind', 'old-promise'], array_column(JsonPath::arrayAt($move, ['clears']), 'id'));
        self::assertSame(['total' => 0, 'verdict' => 'ok', 'lead' => null, 'deciding_advisory' => null, 'at_most' => false, 'assumes' => ['clears_hold', 'composer_resolves']], $move['if_applied']);
        self::assertSame('raise require.php to >=8.1, then require a/b ^2.3 — clears left-behind and old-promise; 2.x needs php >=8.1, require.php allows 7.4 (a major raise); the target php 8.4 runs it; held by composer.json (requires ^1.0); a new major brings its own requirements, which lockrot does not solve', $move['text']);
    }

    /** SPEC §5.3: the constraint rises past the other advisories while one advisory holds the branch's newest release. */
    public function testALeftBehindConstraintRisesPastAnAdvisoryWhileAnotherHoldsTheNewestRelease(): void
    {
        $move = JsonPath::arrayAt(self::analyse([
            'releases' => [self::release('1.0.0', '2019-01-01', '>=7.2'), self::release('1.9.0', '2020-01-01', '>=7.2'), self::release('2.3.0', '2026-03-01', '>=8.1'), self::release('2.3.2', '2026-04-01', '>=8.1'), self::release('2.3.5', '2026-05-01', '>=8.1')],
            'other_advisories' => [self::advisory('PKSA-low', 'high', '>=2.3.0,<2.3.2'), self::advisory('PKSA-top', 'high', '>=2.3.5')],
        ]), ['next_step']);

        self::assertSame(['raise-php', '2.x', '^2.3.2', '2.3.2'], [$move['kind'], $move['to_branch'], $move['constraint'], $move['version']]);
    }

    public function testALeftBehindPackageWhoseHigherBranchTheTargetCannotRunIsBlocked(): void
    {
        $move = JsonPath::arrayAt(self::analyse(['releases' => [self::release('1.0.0', '2019-01-01', '>=7.2'), self::release('1.9.0', '2020-01-01', '>=7.2'), self::release('2.3.0', '2026-06-01', '>=9.0')]]), ['next_step']);

        self::assertSame(['blocked', '2.x', null, null, []], [$move['kind'], $move['to_branch'], $move['constraint'], $move['if_applied'], $move['commands']]);
        self::assertSame('no move runs on the target: 2.x needs php >=9.0 and the target php 8.4 does not run it', $move['text']);
    }

    /** A security move of class `update` leads, whatever the part sizes, and the left-behind move follows in also[]. */
    public function testAnUpdateLeadsAndTheBranchMoveFollows(): void
    {
        $finding = self::analyse(['advisories' => [self::ADVISORY]]);
        $move = JsonPath::arrayAt($finding, ['next_step']);

        self::assertSame(['update', 'vulnerable', '1.9.0', '1.9.0', null], [$move['kind'], $move['source'], $move['version'], $move['newest'], $move['constraint']]);
        self::assertSame([['composer', 'update', 'a/b']], $move['commands']);
        self::assertSame(['raise-php'], array_column(JsonPath::arrayAt($move, ['also']), 'kind'));
        self::assertArrayNotHasKey('through', JsonPath::arrayAt($move, ['also', 0]));
        self::assertSame('composer update a/b (gets 1.9.0) — clears the advisory; left-behind, old-promise stay', $move['text']);
        self::assertSame(['next_step', 'update', null], [JsonPath::stringAt($finding, ['security', 'move_in']), JsonPath::stringAt($finding, ['security', 'fix_kind']), JsonPath::arrayAt($finding, ['security'])['partial']]);
    }

    /** Release data that never came names no move: lockrot claims nothing about releases it did not read. */
    public function testAnAdvisoryWithoutReleaseDataNamesNoMove(): void
    {
        $move = JsonPath::arrayAt(self::analyse(['releases' => null, 'advisories' => [self::ADVISORY]]), ['next_step']);

        self::assertSame(['no-move', 'vulnerable', 'releases_unknown', 'unknown'], [$move['kind'], $move['source'], $move['reason'], $move['fix_kind']]);
        self::assertSame('no move: lockrot could not read its releases, so it names no fix', $move['text']);
    }

    /** The releases were read, but no counted advisory names the versions it affects: no release data is missing. */
    public function testAnAdvisoryWithoutAnAffectedRangeNamesNoMoveForThatCause(): void
    {
        $advisories = [['id' => 'PKSA-1', 'severity' => 'critical', 'affected_versions' => null], ['id' => 'PKSA-2', 'severity' => 'high', 'affected_versions' => null]];
        $move = JsonPath::arrayAt(self::analyse(['advisories' => $advisories]), ['next_step']);

        self::assertSame(['no-move', 'vulnerable', 'affected_range_unknown', 'unknown'], [$move['kind'], $move['source'], $move['reason'], $move['fix_kind']]);
        self::assertSame('no move: no advisory names the versions it affects, so lockrot names no fix', $move['text']);
    }

    /** The feed gives the high advisory first. S9 puts the critical one first, and so does every fact list. */
    public function testAnUpdateAndItsGetsListTheAdvisoriesInS9OrderWhateverTheFeedOrder(): void
    {
        $finding = self::analyse(['advisories' => [self::advisory('PKSA-a', 'high', '<1.9.0'), self::advisory('PKSA-b', 'critical', '<1.9.0')]]);

        self::assertSame('update', JsonPath::stringAt($finding, ['next_step', 'kind']));
        self::assertSame(['PKSA-b', 'PKSA-a'], array_column(JsonPath::arrayAt($finding, ['next_step', 'clears']), 'id'));
        self::assertSame(['PKSA-b', 'PKSA-a'], array_column(JsonPath::arrayAt($finding, ['security', 'gets', 'clears']), 'id'));
    }

    /** S9 puts the unfixed critical advisory first, then the high one, then the medium one. */
    public function testAPartialFixListsTheAdvisoriesItClearsInS9OrderWhateverTheFeedOrder(): void
    {
        $finding = self::analyse(['advisories' => [self::advisory('PKSA-a', 'medium', '<1.9.0'), self::advisory('PKSA-b', 'high', '<1.9.0'), self::advisory('PKSA-c', 'critical', '>=1.0.0')]]);
        $cleared = [['kind' => 'advisory', 'id' => 'PKSA-b', 'basis' => 'outside_range'], ['kind' => 'advisory', 'id' => 'PKSA-a', 'basis' => 'outside_range']];

        self::assertSame(['1.9.0', $cleared], [JsonPath::stringAt($finding, ['security', 'partial', 'version']), JsonPath::arrayAt($finding, ['security', 'partial', 'clears'])]);
        self::assertSame($cleared, JsonPath::arrayAt($finding, ['security', 'gets', 'clears']));
    }

    public function testAnAdvisoryOnAVcsPackageNamesNoMoveBecauseItsReleasesWereNotRead(): void
    {
        $move = JsonPath::arrayAt(self::analyse(['releases' => null, 'lock' => [['origin' => 'vcs']], 'advisories' => [self::ADVISORY]]), ['next_step']);

        self::assertSame(['no-move', 'metadata_not_read'], [$move['kind'], $move['reason']]);
        self::assertSame('no move: lockrot did not read its releases, so it names none', $move['text']);
    }

    public function testAStalePackageHasNoMove(): void
    {
        $move = JsonPath::arrayAt(self::analyse(['lock' => [['php' => '^8.0']], 'releases' => [self::release('1.0.0', '2019-01-01', '^8.0')]]), ['next_step']);

        self::assertSame(['no-move', 'stale', 'package_quiet', '—'], [$move['kind'], $move['source'], $move['reason'], $move['text']]);
    }

    /** old-promise: `composer update` when the installed branch's newest release came after the target major's GA. */
    public function testAnOldPromiseUpdatesToAReleaseAfterTheGa(): void
    {
        $move = JsonPath::arrayAt(self::analyse(['releases' => [self::release('1.0.0', '2019-01-01', '>=7.2'), self::release('1.5.0', '2021-06-01', '>=7.2')]]), ['next_step']);

        self::assertSame(['update', 'old-promise', '1.5.0', null], [$move['kind'], $move['source'], $move['version'], $move['fix_kind']]);
        self::assertSame([['kind' => 'flag', 'id' => 'old-promise', 'basis' => 'released_after_ga']], $move['clears']);
        self::assertSame([['kind' => 'flag', 'id' => 'stale']], $move['leaves']);
        self::assertSame('composer update a/b (gets 1.5.0) — clears old-promise; stale stays', $move['text']);
    }

    public function testAnOldPromiseWithNoLaterReleaseIsTested(): void
    {
        $move = JsonPath::arrayAt(self::analyse(['releases' => [self::release('1.0.0', '2019-01-01', '>=7.2')]]), ['next_step']);

        self::assertSame(['test', null], [$move['kind'], $move['if_applied']]);
        self::assertSame(['version' => '1.0.0', 'released' => '2019-01-01T00:00:00+00:00', 'is_installed' => true, 'relation' => null, 'snapshot_time' => null], $move['latest']);
        self::assertSame('test it on PHP 8.4 — 1.0.0 is its newest release', $move['text']);
    }

    /** `composer update` installs the highest release the lock's links allow: a conflict holds 1.9.5, so it gets 1.9.0. */
    public function testAnUpdateGetsTheReleaseTheLinksAllow(): void
    {
        $move = JsonPath::arrayAt(self::analyse([
            'lock' => [[], ['name' => 'c/c', 'version' => '1.0.0', 'dev' => false, 'conflict' => ['a/b' => '>=1.9.5']]],
            'releases' => [self::release('1.0.0', '2019-01-01', '>=7.2'), self::release('1.9.0', '2020-01-01', '>=7.2'), self::release('1.9.5', '2020-02-01', '>=7.2'), self::release('2.3.0', '2026-06-01', '>=8.1')],
            'advisories' => [self::ADVISORY],
        ]), ['next_step']);

        self::assertSame(['update', '1.9.0', '1.9.0'], [$move['kind'], $move['version'], $move['newest']]);
        self::assertStringStartsWith('composer update a/b (gets 1.9.0)', JsonPath::stringAt($move, ['text']));
    }

    /** `security.partial` is null beside a move that is `composer update` to the same release. */
    public function testNoPartialStandsBesideAnUpdateToTheSameRelease(): void
    {
        $finding = self::analyse([
            'releases' => [self::release('1.0.0', '2019-01-01', '>=7.2'), self::release('1.5.0', '2021-06-01', '>=7.2')],
            'advisories' => [['id' => 'PKSA-a', 'severity' => 'high', 'affected_versions' => '<1.5.0'], ['id' => 'PKSA-b', 'severity' => 'high', 'affected_versions' => '<9.0']],
        ]);
        $moves = array_merge([$finding['next_step']], JsonPath::arrayAt($finding, ['next_step', 'also']));

        self::assertContains(['update', '1.5.0'], array_map(static fn ($move): array => \is_array($move) ? [$move['kind'], $move['version']] : [], $moves));
        self::assertNull(JsonPath::arrayAt($finding, ['security'])['partial']);
    }

    /** With no release read, the test move names the installed release, dated as S5 reads it. */
    public function testAnOldPromiseWithoutReleaseDataTestsTheInstalledOne(): void
    {
        $move = JsonPath::arrayAt(self::analyse(['releases' => null]), ['next_step']);

        self::assertSame('test', $move['kind']);
        self::assertSame(['version' => '1.0.0', 'released' => '2019-01-01T00:00:00+00:00', 'is_installed' => true, 'relation' => null, 'snapshot_time' => null], $move['latest']);
    }

    /**
     * @param array<mixed, mixed> $case
     *
     * @return array<mixed, mixed> the case package's finding as report-2 writes it
     */
    private static function hydrated(array $case): array
    {
        $package = JsonPath::stringAt($case, ['inputs', 'lock', 0, 'name']);
        foreach (JsonPath::arrayAt(JsonPath::decoded(CaseHydrator::report($case)->toArray()), ['findings']) as $finding) {
            self::assertIsArray($finding);
            if ($finding['package'] === $package) {
                return $finding;
            }
        }
        self::fail('no finding for '.$package);
    }

    /**
     * The base lock with $over replacing its parts: the first `lock` entry merges into the base entry
     * and the others follow it, and a null root requirement removes it.
     *
     * @param array<string, mixed> $over
     *
     * @return array<mixed, mixed>
     */
    private static function analyse(array $over): array
    {
        return self::hydrated(['id' => 'move', 'clock' => self::CLOCK, 'inputs' => self::inputs($over)]);
    }

    /**
     * @param array<string, mixed> $over
     *
     * @return array<string, mixed> the `inputs` block of the base lock with $over
     */
    private static function inputs(array $over): array
    {
        $entry = ['name' => 'a/b', 'version' => '1.0.0', 'time' => '2019-01-01T00:00:00+00:00', 'php' => '>=7.2', 'dev' => false, 'origin' => 'packagist'];
        $others = [];
        $require = ['php' => '>=7.4', 'a/b' => '^1.0'];
        $rest = [
            'releases' => [self::release('1.0.0', '2019-01-01', '>=7.2'), self::release('1.9.0', '2020-01-01', '>=7.2'), self::release('2.0.0', '2026-05-01', '>=8.1'), self::release('2.3.0', '2026-06-01', '>=8.1')],
            'advisories' => [],
            'activity' => null,
            'run' => ['target_php' => '8.4', 'project_php' => '>=7.4', 'fail_on' => 'high', 'include_dev' => false],
        ];
        foreach ($over as $key => $value) {
            if ($key === 'lock') {
                self::assertIsArray($value);
                self::assertIsArray($value[0]);
                $entry = $value[0] + $entry;
                $others = \array_slice($value, 1);
            } elseif ($key === 'root') {
                self::assertIsArray($value);
                self::assertIsArray($value['require']);
                $require = array_filter($value['require'] + $require, static fn ($constraint): bool => $constraint !== null);
            } else {
                $rest[$key] = $value;
            }
        }
        return ['root' => ['name' => 'acme/app', 'require' => $require], 'lock' => array_merge([$entry], $others)] + $rest;
    }

    /** @return array{version: string, time: ?string, php: ?string} */
    private static function release(string $version, ?string $date, ?string $php): array
    {
        return ['version' => $version, 'time' => $date === null ? null : $date.'T00:00:00+00:00', 'php' => $php];
    }

    /** @return array{id: string, severity: string, affected_versions: string} */
    private static function advisory(string $id, string $severity, string $range): array
    {
        return ['id' => $id, 'severity' => $severity, 'affected_versions' => $range];
    }

    /**
     * Locks for {@see analyse()}, by name: each changes the base lock to reach one rule. The
     * target major's GA is 2020-11-26 (PHP 8.0), and a line releases within three years of the run.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function scenarios(): array
    {
        $one = [self::release('1.0.0', '2019-01-01', '>=7.2'), self::release('1.9.0', '2020-01-01', '>=7.2')];
        $dependent = [['version' => '1.0.0', 'time' => '2019-01-01T00:00:00+00:00', 'require' => ['a/b' => '*']], ['version' => '2.0.0', 'time' => '2026-01-01T00:00:00+00:00', 'require' => []]];
        $dependents = static function (int $count) use ($dependent): array {
            $names = \array_slice(['d/one', 'd/two', 'd/three', 'd/four'], 0, $count);
            $lock = array_map(static fn (string $name): array => ['name' => $name, 'version' => '1.0.0', 'require' => ['a/b' => '*']], $names);

            return ['root' => ['require' => ['a/b' => null] + array_fill_keys($names, '^1.0')], 'lock' => array_merge([[]], $lock), 'dependents' => array_fill_keys($names, $dependent)];
        };
        $holder = ['name' => 'h/old', 'version' => '1.0.0', 'php' => '>=5.3,<7.0', 'require' => ['a/b' => '^1.0'], 'time' => '2015-01-01T00:00:00+00:00'];
        $offline = ['target_php' => '8.4', 'project_php' => '>=7.4', 'fail_on' => 'high', 'include_dev' => false, 'advisories_unchecked' => 'offline'];

        return [
            'pinned from a local path' => ['lock' => [['version' => 'dev-master', 'origin' => 'path']], 'releases' => null],
            'pinned with its releases unread' => ['lock' => [['version' => 'dev-master']], 'releases' => null],
            'pinned with no stable release' => ['lock' => [['version' => 'dev-master']], 'releases' => []],
            'pinned on a v-prefixed tag' => ['lock' => [['version' => 'dev-master']], 'releases' => [self::release('v1.0.0', '2019-01-01', '>=7.2'), self::release('v2.3.0', '2026-06-01', '>=7.2')]],
            'pinned on a tag before the ga' => ['lock' => [['version' => 'dev-master']], 'releases' => [self::release('v1.0.0', '2019-01-01', '>=7.2')]],
            'pinned on a tag with no php' => ['lock' => [['version' => 'dev-master']], 'releases' => [self::release('v1.0.0', '2019-01-01', null)]],
            'pinned on a 0.x branch alias' => ['lock' => [['version' => '0.3.x-dev']], 'root' => ['require' => ['a/b' => '0.3.x-dev']], 'releases' => [self::release('0.3.0', '2019-01-01', '>=7.2'), self::release('0.3.5', '2020-01-01', '>=7.2'), self::release('0.4.0', '2026-01-01', '>=7.2')]],
            'three dependents' => $dependents(3),
            'four dependents that dropped it' => $dependents(4),
            'holders that are graded' => ['lock' => [[], $holder, ['name' => 'h/pin', 'version' => 'dev-master', 'require' => ['a/b' => '^1.0']]], 'dependents' => ['h/old' => [['version' => '1.0.0', 'time' => '2015-01-01T00:00:00+00:00', 'require' => ['a/b' => '^1.0'], 'php' => '>=5.3,<7.0']]]],
            'a replacement that the registry names' => ['abandoned' => ['by' => 'repository', 'replacement' => 'acme/new-lib']],
            'a newer release than the installed one' => ['releases' => [self::release('1.0.0', '2019-01-01', '>=7.2'), self::release('1.2.0', '2020-01-01', '>=7.2')]],
            'a line with no php' => ['releases' => array_merge($one, [self::release('2.0.0', '2026-05-01', null), self::release('2.3.0', '2026-06-01', null)])],
            'a lower line that releases' => ['releases' => array_merge([self::release('0.9.0', '2026-07-01', '>=7.2')], $one, [self::release('2.0.0', '2026-05-01', '>=8.1'), self::release('2.3.0', '2026-06-01', '>=8.1')])],
            'a line released at the warn-years edge' => ['releases' => array_merge($one, [self::release('2.3.0', '2023-10-08', '>=8.1'), self::release('3.0.0', '2026-01-01', '>=9.0')])],
            'a line with an undated newest release' => ['releases' => array_merge($one, [self::release('2.0.0', '2026-01-01', '>=8.1'), self::release('2.3.0', null, '>=8.1')])],
            'a lowest release on the ga day' => ['releases' => array_merge($one, [self::release('2.0.0', '2020-11-26', '>=7.2'), self::release('2.0.1', '2026-01-01', '>=7.2')])],
            'old-promise with an update the target cannot run' => ['releases' => [self::release('1.0.0', '2019-01-01', '>=7.2'), self::release('1.5.0', '2025-01-01', '>=8.5')]],
            'old-promise with a lower line after the ga' => ['releases' => [self::release('0.9.0', '2025-03-01', '>=7.2'), self::release('1.0.0', '2019-01-01', '>=7.2')]],
            'old-promise with an undated release' => ['releases' => [self::release('1.0.0', '2019-01-01', '>=7.2'), self::release('1.5.0', null, '>=7.2')]],
            'old-promise with a release on the ga day' => ['releases' => [self::release('1.0.0', '2019-01-01', '>=7.2'), self::release('1.5.0', '2020-11-26', '>=7.2')]],
            'an advisory on the move\'s lower releases' => ['releases' => array_merge($one, [self::release('2.3.0', '2026-03-01', '>=8.1'), self::release('2.3.5', '2026-04-01', '>=8.1')]), 'other_advisories' => [self::advisory('PKSA-own', 'high', '>=2.3.0,<2.3.5')]],
            'an advisory on the move\'s newest release' => ['releases' => array_merge($one, [self::release('2.3.0', '2026-03-01', '>=8.1'), self::release('2.3.5', '2026-04-01', '>=8.1')]), 'other_advisories' => [self::advisory('PKSA-own', 'high', '>=2.3.5')]],
            'a maintenance lead merged with a higher fix' => ['releases' => array_merge($one, [self::release('2.3.0', '2026-01-01', '>=7.2'), self::release('2.3.4', '2026-06-01', '>=8.1')]), 'advisories' => [self::advisory('PKSA-1', 'low', '<2.3.4')]],
            'a security lead merged with a higher maintenance move' => ['releases' => array_merge($one, [self::release('2.0.0', '2026-01-01', '>=7.2'), self::release('2.3.0', '2026-06-01', '>=8.1')]), 'advisories' => [self::advisory('PKSA-1', 'high', '<2.0.0')]],
            'a security move in also' => ['releases' => array_merge($one, [self::release('2.0.0', '2026-01-01', '>=7.2'), self::release('2.3.0', '2026-06-01', '>=7.2'), self::release('3.0.0', '2026-07-01', '>=8.1')]), 'advisories' => [self::advisory('PKSA-1', 'low', '<3.0.0')]],
            'a top line that fixes nothing' => ['releases' => [self::release('1.0.0', '2019-01-01', '>=7.2'), self::release('1.5.0', '2020-01-01', '>=7.2'), self::release('2.0.0', '2026-05-01', '>=8.1'), self::release('2.3.0', '2026-06-01', '>=8.1')], 'advisories' => [self::advisory('PKSA-1', 'high', '<1.5.0 || >=2.0.0')]],
            'a lead update to the partial release' => ['releases' => [self::release('1.0.0', '2019-01-01', '>=7.2'), self::release('1.5.0', '2021-06-01', '>=7.2')], 'advisories' => [self::advisory('PKSA-a', 'low', '<1.5.0'), self::advisory('PKSA-b', 'low', '<9.0')]],
            'a partial fix offline' => ['releases' => [self::release('1.0.0', '2019-01-01', '>=7.2'), self::release('1.5.0', '2020-01-01', '>=7.2')], 'advisories' => [self::advisory('PKSA-a', 'high', '<1.5.0'), self::advisory('PKSA-b', 'high', '<9.0')], 'run' => $offline],
            'two advisories with no release data' => ['releases' => null, 'advisories' => [self::advisory('PKSA-1', 'high', '<1.9.0'), self::advisory('PKSA-2', 'high', '<1.9.0')]],
            'two advisories that stay' => ['advisories' => [self::advisory('PKSA-1', 'low', '<99.0'), self::advisory('PKSA-2', 'low', '<99.0')]],
            'an accepted flag that the move settles' => ['accept' => ['left-behind'], 'releases' => array_merge($one, [self::release('2.0.0', '2026-01-01', '>=7.2')]), 'advisories' => [self::advisory('PKSA-1', 'high', '<2.0.0')]],
            'a target php that does not parse' => ['lock' => [['version' => 'dev-master']], 'releases' => [self::release('v2.3.0', '2026-06-01', '>=7.2')], 'run' => ['target_php' => 'next'] + $offline],
            'a snapshot on the line that the fix needs' => ['lock' => [['version' => '2.x-dev']], 'releases' => array_merge($one, [self::release('2.0.0', '2026-01-01', '>=7.2'), self::release('2.3.0', '2026-06-01', '>=7.2')]), 'advisories' => [self::advisory('PKSA-1', 'high', '<2.3.0')]],
            'a maintenance lead that clears the advisory on another line' => ['releases' => array_merge($one, [self::release('2.0.0', '2021-06-01', '>=7.2'), self::release('3.0.0', '2026-01-01', '>=8.1')]), 'advisories' => [self::advisory('PKSA-1', 'low', '<2.0.0')]],
            'a replacement given as a url' => ['abandoned' => ['by' => 'repository', 'replacement' => 'https://github.com/acme/fork']],
            'pinned on a tag the target cannot run' => ['lock' => [['version' => 'dev-master']], 'releases' => [self::release('v2.3.0', '2026-06-01', '>=9.0')]],
            'pinned on an undated tag' => ['lock' => [['version' => '1.x-dev', 'time' => '2026-01-01T00:00:00+00:00']], 'root' => ['require' => ['a/b' => '1.x-dev']], 'releases' => [self::release('v1.0.0', null, '>=7.2')]],
            'a fix below the newest release of its line' => ['lock' => [['time' => '2026-01-01T00:00:00+00:00']], 'releases' => [self::release('1.0.0', '2026-01-01', '>=7.2'), self::release('2.0.0', '2026-02-01', '>=7.2'), self::release('2.3.0', '2026-06-01', '>=8.1')], 'advisories' => [self::advisory('PKSA-1', 'high', '<2.0.0')]],
            'pinned on a line older than the snapshot' => ['lock' => [['version' => '1.x-dev', 'time' => '2026-01-01T00:00:00+00:00']], 'root' => ['require' => ['a/b' => '1.x-dev']]],
            'pinned on a line released at the snapshot' => ['lock' => [['version' => '1.x-dev', 'time' => '2020-01-01T00:00:00+00:00']], 'root' => ['require' => ['a/b' => '1.x-dev']]],
            'pinned on a line with no release' => ['lock' => [['version' => '1.x-dev', 'time' => '2026-01-01T00:00:00+00:00']], 'root' => ['require' => ['a/b' => '1.x-dev']], 'releases' => [self::release('2.0.0', '2026-05-01', '>=7.2'), self::release('2.3.0', '2026-06-01', '>=7.2')]],
            'pinned on a line below one that releases' => ['lock' => [['version' => '2.x-dev', 'time' => '2026-01-01T00:00:00+00:00']], 'root' => ['require' => ['a/b' => '2.x-dev']], 'releases' => [self::release('1.0.0', '2026-03-01', '>=7.2'), self::release('2.0.0', '2020-01-01', '>=7.2')]],
            'pinned on a line below one that stopped' => ['lock' => [['version' => '1.x-dev', 'time' => '2026-01-01T00:00:00+00:00']], 'root' => ['require' => ['a/b' => '1.x-dev']], 'releases' => [self::release('1.0.0', '2020-01-01', '>=7.2'), self::release('2.0.0', '2021-01-01', '>=7.2')]],
            'pinned on a line below an undated one' => ['lock' => [['version' => '1.x-dev', 'time' => '2026-01-01T00:00:00+00:00']], 'root' => ['require' => ['a/b' => '1.x-dev']], 'releases' => [self::release('1.0.0', '2020-01-01', '>=7.2'), self::release('2.0.0', null, '>=7.2')]],
            'pinned on a tag that adds two flags' => ['lock' => [['version' => '1.x-dev', 'time' => '2026-01-01T00:00:00+00:00']], 'root' => ['require' => ['a/b' => '1.x-dev']], 'releases' => [self::release('1.0.0', '2019-01-01', '>=7.2'), self::release('2.0.0', '2026-01-01', '>=9.0')]],
            'an undated line below the newest one' => ['releases' => array_merge($one, [self::release('2.0.0', null, '>=7.2'), self::release('3.0.0', '2026-01-01', '>=8.1')])],
            'a replacement that is graded itself' => ['lock' => [[], $holder], 'abandoned' => ['by' => 'repository', 'replacement' => 'h/old'], 'dependents' => ['h/old' => [['version' => '1.0.0', 'time' => '2015-01-01T00:00:00+00:00', 'require' => ['a/b' => '^1.0'], 'php' => '>=5.3,<7.0']]]],
            'two advisories that no single release fixes' => ['releases' => [self::release('1.0.0', '2019-01-01', '>=7.2'), self::release('1.5.0', '2020-01-01', '>=7.2'), self::release('2.0.0', '2026-01-01', '>=7.2')], 'advisories' => [self::advisory('PKSA-1', 'high', '<1.5.0 || >=2.0.0'), self::advisory('PKSA-2', 'high', '<2.0.0')]],
            'pinned on a tag from the ga day' => ['lock' => [['version' => '1.x-dev', 'time' => '2026-01-01T00:00:00+00:00']], 'root' => ['require' => ['a/b' => '1.x-dev']], 'releases' => [self::release('1.0.0', '2020-11-26', '>=7.2')]],
            'a security update merged with a later update' => ['releases' => [self::release('1.0.0', '2019-01-01', '>=7.2'), self::release('1.5.0', '2020-01-01', '>=7.2'), self::release('1.9.0', '2021-06-01', '>=7.2')], 'advisories' => [self::advisory('PKSA-1', 'high', '<1.5.0')]],
            'a blocked move beside an advisory' => ['releases' => array_merge($one, [self::release('2.3.0', '2026-06-01', '>=9.0')]), 'advisories' => [self::advisory('PKSA-1', 'high', '<99.0')]],
            'two 0.x lines' => ['lock' => [['version' => '0.3.1']], 'root' => ['require' => ['a/b' => '^0.3']], 'releases' => [self::release('0.3.1', '2019-01-01', '>=7.2'), self::release('0.3.5', '2020-01-01', '>=7.2'), self::release('0.4.0', '2026-01-01', '>=7.2')], 'advisories' => [self::advisory('PKSA-1', 'high', '<0.3.5')]],
        ];
    }
}
