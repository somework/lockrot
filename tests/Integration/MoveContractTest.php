<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `next_step` against docs/verdicts.md#move on every fixture document: each committed case, the composite
 * report, and each case with an `inputs` block as the real analyzer writes it. The invariants are
 * I14 to I14f, I18 and I19. The model reference compares the analyzer's move with the case's own
 * where the inputs can say it.
 */
final class MoveContractTest extends TestCase
{
    private const VERSION = 'the model leaves `version` null on a move whose constraint comes from S8 (SPEC §7.6.1, ‡): lockrot names the lowest admitted release';
    private const DATED = 'the model dates a branch by its newest release only (‡): a lower release leaves old-promise unverified, where lockrot dates it';
    private const BY_POSITION = 'the model classes a higher branch `upgrade` by its position (SPEC §5.3, ‡): no link of the inputs excludes the release, so lockrot classes it `update`';
    private const CLOCK = 'S2 reads the case run date, not the corpus clock: its years, and `quiet_years` with them, move by a tenth';
    private const SCORE = 'the inputs carry no allowlist entry and no date that S8 took from another branch: the hydrated score differs, so the move does too';

    /**
     * The fields of a hydrated move that differ from the case's, with the reason. Every other field
     * of `next_step`, `also[]`, `security.move_in` and `security.fix_kind` must be equal. Each
     * reference also leaves out the text, the replacement's url and finding, and each holder's
     * finding: they read packages that the inputs do not analyse.
     */
    private const DIFFERENCES = [
        'wp-config' => [['latest', 'leaves'], self::SCORE],
        'psr7' => [['version'], self::VERSION],
        'flysystem' => [['fix_kind', 'security.fix_kind'], self::BY_POSITION],
        'unreached-move' => [['version', 'clears', 'unverified', 'clears_all', 'if_applied'], self::DATED],
        'many-dependents' => [['version'], self::VERSION],
        'silent' => [['quiet_years'], self::CLOCK],
        'pagerfanta' => [['next_step'], self::SCORE],
        'guzzle-5' => [['fix_kind', 'security.fix_kind'], self::BY_POSITION],
        'routing' => [['fix_kind', 'security.fix_kind'], self::BY_POSITION],
        'B-also‡' => [['also'], self::DATED],
        'A-php-star‡' => [['fix_kind', 'security.fix_kind'], self::BY_POSITION],
        'B-baselined‡' => [['fix_kind', 'security.fix_kind'], self::BY_POSITION],
        'tie‡' => [['version', 'clears', 'also'], self::SCORE],
        'E-ignored-whole‡' => [['if_applied'], self::SCORE],
        'B-dev‡' => [['fix_kind', 'security.fix_kind'], self::BY_POSITION],
        'D-partial‡' => [['leaves'], self::SCORE],
        'B-offline‡' => [['version', 'clears', 'unverified', 'clears_all', 'if_applied'], self::DATED],
        'holder-vuln‡' => [['version'], self::VERSION],
        'stale-accepted‡' => [['next_step'], self::SCORE],
        'partial-op‡' => [['version'], self::VERSION],
        'B-accepted‡' => [['source', 'merged', 'fix_kind', 'clears', 'security.fix_kind'], self::SCORE],
        'unreached-dev‡' => [['version'], self::VERSION],
        'either-critical‡' => [['also', 'security.fix_kind'], self::BY_POSITION],
        'ignored-marking-partial‡' => [['next_step'], self::SCORE],
        'B-offline-high‡' => [['version', 'clears', 'unverified', 'clears_all', 'if_applied'], self::DATED],
        'fail-on-config‡' => [['fix_kind', 'security.fix_kind'], self::BY_POSITION],
        'fail-on-default‡' => [['fix_kind', 'security.fix_kind'], self::BY_POSITION],
        'require-dev-name‡' => [['version'], self::VERSION],
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

    /** @return iterable<string, array{string}> */
    public static function casesWithInputs(): iterable
    {
        foreach (array_keys(CaseHydrator::findings()) as $id) {
            yield $id => [$id];
        }
    }

    /**
     * I14, I14b, I14c, I14e: what a move clears, leaves and cannot check partition the counted facts,
     * and `if_applied` is a rerun only where the move clears something.
     *
     * @param array<mixed, mixed> $finding
     *
     * @dataProvider findings
     */
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
            self::assertSame([], array_intersect($clears, $unverified), 'I14b: clears and unverified are disjoint');
            self::assertSame([], array_intersect($leaves, array_merge($clears, $unverified)), 'I14b: leaves is disjoint from both');
            self::assertSame([], array_diff($counted, array_merge($clears, $unverified, $leaves)), 'I14b: together they hold every counted fact');
            self::assertSame([], array_diff(array_merge($clears, $leaves), $counted), 'I14b: clears and leaves hold counted facts only');
            foreach (array_diff($unverified, $counted) as $extra) {
                self::assertStringStartsWith('flag ', $extra, 'I14b: an unverified fact outside them is a flag the move may raise');
            }
            self::assertSame([], array_intersect($adds, array_merge($clears, $unverified, $leaves)), 'I14b: adds is disjoint from all three');
            foreach ($move['adds'] as $add) {
                self::assertNotContains($add['id'], $fired, 'I14b: adds names no fact that fired');
            }
            self::assertSame($clears !== [] && $leaves === [] && $unverified === [], $move['clears_all']);
            $ifApplied = $move['if_applied'];
            if (\in_array($move['kind'], self::NO_SCORE_AFTER, true)) {
                self::assertNull($ifApplied, 'I14: '.$move['kind'].' has no score after it');
            }
            if ($ifApplied === null) {
                continue;
            }
            self::assertNotSame([], $clears, 'I14: a rerun only with a non-empty clears');
            self::assertSame($unverified !== [], $ifApplied['at_most'], 'I14: at_most exactly when a fact is unverified');
            self::assertContains('clears_hold', $ifApplied['assumes']);
            self::assertSame($move['held_by'] !== [] || $move['composer_moves_because'] !== [], \in_array('composer_resolves', $ifApplied['assumes'], true));
            if ($ifApplied['verdict'] === 'finished') {
                self::assertNotNull($finding['allowlist'], 'I14e: finished only under an allowlist entry');
            }
        }
        $partial = JsonPath::has($finding, ['security', 'partial']) ? $finding['security']['partial'] : null;
        if (\is_array($partial)) {
            self::assertSame($partial['unverified'] !== [], $partial['if_applied']['at_most'], 'I14c');
            foreach (self::moves($finding) as $move) {
                self::assertFalse($move['kind'] === 'update' && $move['version'] === $partial['version'], 'I14c: no partial beside an update move to the same release');
            }
        }
    }

    /**
     * I18 and C08: the shape of each kind, commands as argv lists, no root requirement of a package
     * the project does not require, and `through[]` on the lead move only.
     *
     * @param array<mixed, mixed> $finding
     *
     * @dataProvider findings
     */
    #[DataProvider('findings')]
    public function testEveryMoveHasTheShapeOfItsKind(array $finding): void
    {
        $graded = \in_array($finding['verdict'], ['critical', 'high', 'medium', 'low'], true);
        self::assertSame($graded, $finding['next_step'] !== null, 'a grade has a move, a score-0 word none');
        foreach (self::moves($finding) as $i => $move) {
            $kind = $move['kind'];
            self::assertSame(\in_array($kind, ['require', 'raise-php'], true), \is_string($move['constraint']), 'I18: '.$kind.' and its constraint');
            if (\in_array($kind, self::BRANCH_KINDS, true)) {
                self::assertNotNull($move['php_check'], 'I18: '.$kind.' names a branch, so it has php_check');
            }
            self::assertSame($kind === 'no-move', $move['reason'] !== null, 'I18: reason exactly on no-move');
            foreach ($move['commands'] as $argv) {
                self::assertIsArray($argv);
                self::assertSame('composer', $argv[0] ?? null);
                self::assertSame(array_values($argv), $argv);
                foreach ($argv as $argument) {
                    self::assertIsString($argument);
                }
                if ($finding['reach'] !== 'direct') {
                    self::assertFalse($argv[1] === 'require' && strpos($argv[2], $finding['package'].':') === 0, 'I18: no root requirement of a package the project does not require');
                }
            }
            self::assertSame($i === 0, \array_key_exists('through', $move), 'I14f: through[] on the lead move only');
            if ($i === 0) {
                self::assertThrough($finding, $move);
            }
        }
    }

    /**
     * I19: `security.move_in` points at the move that clears the advisories, which agrees with the
     * security fields and with its branch row.
     *
     * @param array<mixed, mixed> $finding
     *
     * @dataProvider findings
     */
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
            $cleared = array_column(array_filter($move['clears'], static fn (array $fact): bool => $fact['kind'] === 'advisory'), 'id');
            self::assertTrue($move['source'] === 'vulnerable' || \in_array('vulnerable', $move['merged'], true) || array_diff(self::advisoryIds($finding), $cleared) === [], 'I19: move_in names the move that clears the advisories');
        } elseif ($security['move_in'] === 'also') {
            $move = JsonPath::arrayAt($finding, ['next_step', 'also', 0]);
            self::assertSame('vulnerable', $move['source']);
        }
        if ($move === null) {
            return;
        }
        self::assertSame($move['fix_kind'], $security['fix_kind'], 'I19: security.fix_kind is the fix kind of that move');
        $gets = $security['gets'];
        if ($move['kind'] === 'update' && \is_array($gets) && $move['source'] === 'vulnerable') {
            self::assertSame(array_column($gets['clears'], 'id'), array_column(array_filter($move['clears'], static fn (array $fact): bool => $fact['kind'] === 'advisory'), 'id'), 'I19: an update clears what gets clears');
        }
        $installed = $security['installed_branch_fixes'];
        if (\is_array($installed) && $move['source'] === 'vulnerable' && $move['merged'] === [] && $move['to_branch'] === $finding['branch'] && \in_array($move['kind'], ['update', 'require', 'raise-php'], true)) {
            self::assertSame($installed['lowest'], $move['version'], 'P2b: the move to the installed branch is its row candidate');
            self::assertSame($installed['fix_kind'], $move['fix_kind']);
            self::assertSame(self::rerunRow($move['if_applied']), $installed['if_applied']);
        }
    }

    /**
     * The model reference: the analyzer's move on a case's inputs is the case's own move, but for
     * the fields {@see DIFFERENCES} names with its reason.
     *
     * @dataProvider casesWithInputs
     */
    #[DataProvider('casesWithInputs')]
    public function testTheAnalyzerWritesTheMoveTheCaseRecords(string $id): void
    {
        $golden = JsonPath::arrayAt(CaseHydrator::case($id), ['finding']);
        $lockrot = CaseHydrator::findings()[$id];
        [$skipped] = self::DIFFERENCES[$id] ?? [[], ''];
        if (\in_array('next_step', $skipped, true)) {
            self::assertNotSame(JsonPath::stringAt($golden, ['score', 'text']), JsonPath::stringAt($lockrot, ['score', 'text']), $id.': the scores differ');

            return;
        }
        $expected = self::reference($golden, $skipped);
        $actual = self::reference($lockrot, $skipped);

        self::assertSame($expected, $actual, $id);
        if ($skipped !== []) {
            self::assertNotSame(self::reference($golden, []), self::reference($lockrot, []), $id.': a listed field agrees again: take the case off DIFFERENCES');
        }
    }

    /**
     * @param array<mixed, mixed> $finding
     *
     * @return list<array<mixed, mixed>> the lead move, then its also[] moves
     */
    private static function moves(array $finding): array
    {
        $lead = $finding['next_step'];
        if (!\is_array($lead)) {
            return [];
        }

        return array_merge([$lead], array_values(JsonPath::arrayAt($lead, ['also'])));
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
                $facts[] = 'flag '.$term['flag'];
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
        foreach (JsonPath::arrayAt($finding, ['signals']) as $signal) {
            self::assertIsArray($signal);
            if ($signal['id'] === 'S9') {
                return array_column(JsonPath::arrayAt($signal, ['data', 'advisories']), 'id');
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

        return array_map(static fn (array $fact): string => $fact['kind'].' '.$fact['id'], $facts);
    }

    /**
     * I14f: one entry per direct dependent of a transitive package, `[]` otherwise, and a `why-not`
     * command for each dependent whose newest release no longer requires the package.
     *
     * @param array<mixed, mixed> $finding
     * @param array<mixed, mixed> $move
     */
    private static function assertThrough(array $finding, array $move): void
    {
        $through = JsonPath::arrayAt($move, ['through']);
        if ($finding['reach'] !== 'transitive') {
            self::assertSame([], $through, 'I14f: [] on a direct or unreached package');

            return;
        }
        self::assertSame($finding['direct_dependents'], array_column($through, 'package'), 'I14f: direct_dependents order');
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
                $whyNot[] = ['composer', 'why-not', $entry['package'], $entry['newest_stable']['version']];
            }
        }
        self::assertSame($whyNot, array_values(array_filter($move['commands'], static fn (array $argv): bool => $argv[1] === 'why-not')));
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
     * The move and the security fields that the reference compares, without $skipped.
     *
     * @param array<mixed, mixed> $finding
     * @param list<string>        $skipped
     *
     * @return array<mixed, mixed>
     */
    private static function reference(array $finding, array $skipped): array
    {
        $move = self::comparable($finding['next_step']);
        $security = JsonPath::arrayAt($finding, ['security']);
        $out = ['next_step' => $move, 'security.move_in' => $security['move_in'] ?? null, 'security.fix_kind' => $security['fix_kind'] ?? null];
        foreach ($skipped as $field) {
            unset($out[$field]);
            if (\is_array($out['next_step'] ?? null)) {
                unset($out['next_step'][$field]);
            }
        }

        return $out;
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
        $move['held_by'] = array_map(static function (array $holder): array {
            unset($holder['holder']);

            return $holder;
        }, $move['held_by']);
        foreach (['clears', 'unverified', 'leaves', 'adds'] as $list) {
            $keys = array_map(static fn (array $fact): string => (string) json_encode($fact), $move[$list]);
            sort($keys);
            $move[$list] = $keys;
        }
        if (\array_key_exists('also', $move)) {
            $move['also'] = array_map([self::class, 'comparable'], $move['also']);
        }

        return $move;
    }
}
