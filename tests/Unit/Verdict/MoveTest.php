<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\MultiConstraint;
use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;
use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\JsonPath;
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
        $inputs = ['root' => ['name' => 'acme/app', 'require' => $require], 'lock' => array_merge([$entry], $others)] + $rest;

        return self::hydrated(['id' => 'move', 'inputs' => $inputs]);
    }

    /** @return array{version: string, time: string, php: string} */
    private static function release(string $version, string $date, string $php): array
    {
        return ['version' => $version, 'time' => $date.'T00:00:00+00:00', 'php' => $php];
    }
}
