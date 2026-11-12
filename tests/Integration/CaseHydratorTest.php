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
     * The case with the model's releases of S2-prerelease-shared‡ and S2-prerelease-shared-older‡:
     * 3.0.0-beta1 sits on the commit of the three 1.x tags, above the highest 2.x tag on its own
     * commit. The monorepo parent replaces the split and dates no shared tag.
     *
     * @param array<mixed, mixed> $case
     *
     * @return array<mixed, mixed>
     */
    private static function withSharedPreRelease(array $case, string $date): array
    {
        $shared = static fn (string $version, string $php): array => ['version' => $version, 'time' => $date.'T00:00:00+00:00', 'php' => $php, 'shared_commit' => true];
        $releases = [
            $shared('1.0.0', '>=7.3'), $shared('1.0.1', '>=7.3'), $shared('1.0.2', '>=7.3'),
            ['version' => '2.1.1', 'time' => '2023-06-02T07:22:01+00:00', 'php' => '>=8.0'],
            $shared('3.0.0-beta1', '>=8.1'),
        ];
        $parent = ['name' => 'akaunting/monorepo', 'releases' => [['version' => '2.1.1', 'time' => '2023-06-02T07:22:01+00:00']]];

        return ['inputs' => ['releases' => $releases, 'parent' => $parent] + JsonPath::arrayAt($case, ['inputs'])] + $case;
    }

    /** R16-26: a pre-release on the commit of three stable tags has no trusted date, so S2 reads nothing. Libyears end at the installed highest stable tag (ruling O5, case S2-prerelease-shared‡). */
    public function testAPreReleaseOnASharedCommitDatesNothing(): void
    {
        $case = self::withSharedPreRelease(CaseHydrator::case('S2-prerelease-shared‡'), '2023-08-01');

        $finding = self::hydratedFinding($case);

        self::assertSame([0, null, false], [$finding['libyears'], $finding['libyears_unmeasured'], $finding['libyears_at_least']]);
        $signals = array_column(JsonPath::arrayAt($finding, ['signals']), 'summary', 'id');
        self::assertArrayNotHasKey('S2', $signals);
        self::assertSame('the age of the package was not read (a release it reads is dated by a commit its tags share), so S2 could not measure it', $signals['S10'] ?? null);
    }

    /** R15-28d: an older shared commit leaves S2 to the highest stable tag's own date. Libyears end at the installed highest stable tag (ruling O5, case S2-prerelease-shared-older‡). */
    public function testAnOlderSharedPreReleaseLeavesS2ToTheOwnDate(): void
    {
        $case = self::withSharedPreRelease(CaseHydrator::case('S2-prerelease-shared-older‡'), '2023-01-01');

        $finding = self::hydratedFinding($case);

        self::assertSame([0, null, false], [$finding['libyears'], $finding['libyears_unmeasured'], $finding['libyears_at_least']]);
        self::assertSame(['version' => '2.1.1', 'at' => '2023-06-02T07:22:01+00:00', 'dated_by' => null], array_intersect_key(JsonPath::arrayAt($finding, ['age', 'release']), ['version' => 0, 'at' => 0, 'dated_by' => 0]));
        self::assertContains('S2', array_column(JsonPath::arrayAt($finding, ['signals']), 'id'));
        self::assertSame([], $finding['checks_missing']);
    }

    /** Case feeds-drupal‡: the root's composer repository answers before packagist, with its own records. */
    public function testAnAnsweredFeedBesidePackagistHydratesFirstWithItsRecords(): void
    {
        $case = CaseHydrator::case('feeds-drupal‡');
        $inputs = JsonPath::arrayAt($case, ['inputs']);
        $root = ['repositories' => [['type' => 'composer', 'url' => 'https://packages.drupal.org/8']]] + JsonPath::arrayAt($inputs, ['root']);
        $run = ['advisory_answers' => [['composer_repository' => 'composer repo (https://packages.drupal.org/8)', 'records' => 0]]] + JsonPath::arrayAt($inputs, ['run']);
        $case = ['inputs' => ['root' => $root, 'run' => $run] + $inputs] + $case;

        $report = JsonPath::decoded(CaseHydrator::report($case)->toArray());
        $finding = self::hydratedFinding($case);

        self::assertSame(JsonPath::arrayAt($case, ['finding', 'security', 'feeds']), JsonPath::arrayAt($finding, ['security', 'feeds']));
        self::assertSame(JsonPath::arrayAt($case, ['run', 'advisory_lookup', 'repositories']), JsonPath::arrayAt($report, ['run', 'advisory_lookup', 'repositories']));
    }

    /**
     * @param array<mixed, mixed> $case
     *
     * @return array<mixed, mixed> the hydrated finding of the case's package
     */
    private static function hydratedFinding(array $case): array
    {
        foreach (JsonPath::arrayAt(JsonPath::decoded(CaseHydrator::report($case)->toArray()), ['findings']) as $row) {
            self::assertIsArray($row);
            if (JsonPath::stringAt($row, ['package']) === JsonPath::stringAt($case, ['finding', 'package'])) {
                return $row;
            }
        }
        self::fail('the case package has no finding');
    }

    /** Case not-found-cached‡ gives its 404 a cache time: lockrot serves that 404 from the cache and reports the time. */
    public function testACached404HydratesFromTheCache(): void
    {
        $case = CaseHydrator::case('not-found-cached‡');
        $cachedAt = JsonPath::stringAt($case, ['inputs', 'activity', 'cached_at']);

        $document = JsonPath::decoded(CaseHydrator::report($case)->toArray());

        $activity = null;
        foreach (JsonPath::arrayAt($document, ['findings']) as $row) {
            self::assertIsArray($row);
            if (JsonPath::stringAt($row, ['package']) === JsonPath::stringAt($case, ['finding', 'package'])) {
                $activity = JsonPath::arrayAt($row, ['activity']);
            }
        }
        self::assertNotNull($activity, 'the case package has a finding');
        self::assertSame(['not_found', $cachedAt], [$activity['status'] ?? null, $activity['cached_at'] ?? null]);
    }

    /** Case github-archived-unread‡ reads a GitHub answer without a boolean `archived`: lockrot skips S3 as `fetch_failed`. */
    public function testAGitHubAnswerWithoutArchivedHydratesAsAFailedArchivedCheck(): void
    {
        $case = CaseHydrator::case('github-archived-unread‡');
        self::assertNull(JsonPath::arrayAt($case, ['inputs', 'activity'])['archived'] ?? null, 'the case activity has no archived flag');

        $finding = null;
        foreach (JsonPath::arrayAt(JsonPath::decoded(CaseHydrator::report($case)->toArray()), ['findings']) as $row) {
            self::assertIsArray($row);
            if (JsonPath::stringAt($row, ['package']) === JsonPath::stringAt($case, ['finding', 'package'])) {
                $finding = $row;
            }
        }
        self::assertNotNull($finding, 'the case package has a finding');

        self::assertSame(
            [['check' => 'repository_archived', 'reason' => 'fetch_failed', 'blocks' => ['S3']]],
            JsonPath::arrayAt($finding, ['checks_skipped'])
        );
    }

    /** Case partial-lookup‡ hydrates the advisory repositories and the feeds that the model's run and finding hold, failed ones included. */
    public function testALookupFailedRunHydratesTheModelsRepositoriesAndFeeds(): void
    {
        $case = CaseHydrator::case('partial-lookup‡');
        self::assertSame('lookup_failed', JsonPath::stringAt($case, ['finding', 'security', 'unchecked_reason']));

        $report = JsonPath::decoded(CaseHydrator::report($case)->toArray());
        $finding = null;
        foreach (JsonPath::arrayAt($report, ['findings']) as $row) {
            self::assertIsArray($row);
            if (JsonPath::stringAt($row, ['package']) === JsonPath::stringAt($case, ['finding', 'package'])) {
                $finding = $row;
            }
        }
        self::assertNotNull($finding, 'the case package has a finding');

        self::assertSame(
            self::lookupOf(JsonPath::arrayAt($case, ['run']), JsonPath::arrayAt($case, ['finding'])),
            self::lookupOf(JsonPath::arrayAt($report, ['run']), $finding)
        );
        $model = JsonPath::decodeFile(__DIR__.'/../fixtures/schema/documents/cases/partial-lookup-dagger.json');
        self::assertSame(
            [JsonPath::arrayAt($model, ['notes']), $model['network_failures'] ?? null],
            [JsonPath::arrayAt($report, ['notes']), $report['network_failures'] ?? null],
            'a failed repository writes the run note of the model document'
        );
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

    /**
     * Case lock-out-of-sync‡: a root `require` that the lock does not hold came after the lock. The
     * lock's content-hash is the one of the root without it, so lockrot gives the run note.
     */
    public function testARootRequireThatTheLockDoesNotHoldGivesTheLockOutOfSyncNote(): void
    {
        $case = CaseHydrator::case('A');
        $inputs = JsonPath::arrayAt($case, ['inputs']);
        $root = JsonPath::arrayAt($inputs, ['root']);
        $root['require'] = JsonPath::arrayAt($root, ['require']) + ['acme/feature-flags' => '^1.0'];
        $inputs['root'] = $root;
        $unlocked = ['inputs' => $inputs] + $case;

        $notes = static fn (array $case): array => array_values(array_filter(JsonPath::arrayAt(JsonPath::decoded(CaseHydrator::report($case)->toArray()), ['notes']), static fn ($note): bool => \is_string($note) && strpos($note, 'lock: ') === 0));

        self::assertSame([[], ['lock: the lock file does not match composer.json (acme/feature-flags is not locked at a version that composer.json allows): run composer update']], [$notes($case), $notes($unlocked)]);
    }

    /**
     * A solver world states the lock's hash in `lock_hash`: `stale` writes a hash that differs from
     * composer.json's, and an absent key writes none, so no lock_out_of_sync note appears.
     */
    public function testASolverWorldLockHasAContentHashOnlyWhenTheWorldSetsLockHash(): void
    {
        $case = CaseHydrator::case('A');
        $inputs = JsonPath::arrayAt($case, ['inputs']);
        $root = JsonPath::arrayAt($inputs, ['root']);
        $root['require'] = JsonPath::arrayAt($root, ['require']) + ['acme/feature-flags' => '^1.0'];
        $inputs['root'] = $root;
        $hashless = $inputs + ['solver_world' => ['world' => ['root' => $root]]];
        $stale = $inputs + ['solver_world' => ['world' => ['root' => $root, 'lock_hash' => 'stale']]];

        $notes = static fn (array $inputs): array => array_values(array_filter(JsonPath::arrayAt(JsonPath::decoded(CaseHydrator::report(['inputs' => $inputs] + $case)->toArray()), ['notes']), static fn ($note): bool => \is_string($note) && strpos($note, 'lock: ') === 0));

        self::assertSame([[], ['lock: the lock file does not match composer.json (acme/feature-flags is not locked at a version that composer.json allows): run composer update']], [$notes($hashless), $notes($stale)]);
    }

    /** A stale world whose lock holds every root name, one at a version that the root does not admit. */
    public function testAStaleSolverWorldNamesALockedNameAtAVersionThatTheRootDoesNotAdmit(): void
    {
        $case = CaseHydrator::case('A');
        $inputs = JsonPath::arrayAt($case, ['inputs']);
        $root = JsonPath::arrayAt($inputs, ['root']);
        $root['require'] = ['twig/twig' => '^99.0'] + JsonPath::arrayAt($root, ['require']);
        $inputs['root'] = $root;
        $inputs['solver_world'] = ['world' => ['root' => $root, 'lock_hash' => 'stale']];

        $notes = array_values(array_filter(JsonPath::arrayAt(JsonPath::decoded(CaseHydrator::report(['inputs' => $inputs] + $case)->toArray()), ['notes']), static fn ($note): bool => \is_string($note) && strpos($note, 'lock: ') === 0));

        self::assertSame(['lock: the lock file does not match composer.json (twig/twig is not locked at a version that composer.json allows): run composer update'], $notes);
    }

    /**
     * The oracle answers Composer's refusal, and lockrot reads a refused baseline as not checked when
     * a name stays unloaded and the world's lock does not hold a root requirement.
     *
     * @dataProvider baselinesOfTheSolverWorlds
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('baselinesOfTheSolverWorlds')]
    public function testARefusedBaselineReadsAsLockrotReadsTheWorldsLock(string $id, array $expected): void
    {
        $run = JsonPath::arrayAt(JsonPath::decoded(CaseHydrator::report(CaseHydrator::case($id))->toArray()), ['run', 'move_check']);

        self::assertSame($expected, [$run['baseline'] ?? null, $run['reason'] ?? null]);
    }

    /** @return iterable<string, array{string, array{string, ?string}}> */
    public static function baselinesOfTheSolverWorlds(): iterable
    {
        yield 'a lock that omits a root requirement' => ['mc-baseline-unsynced‡', ['not_checked', 'unloaded_name']];
        yield 'the same lock with no hash' => ['mc-baseline-unsynced-hashless‡', ['not_checked', 'unloaded_name']];
        yield 'a provide below the link' => ['mc-baseline-provide-unmatched‡', ['not_checked', 'unloaded_name']];
        yield 'a lock that holds every root requirement' => ['mc-baseline-synced-non-dev‡', ['refused', null]];
    }

    public function testACaseWithoutInputsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CaseHydrator::report(['id' => 'X', 'inputs' => null]);
    }

    /**
     * @param array<mixed, mixed> $run
     * @param array<mixed, mixed> $finding
     *
     * @return array<string, mixed> the security fields that the line reads, each repository's outcome and each feed's answer
     */
    private static function lookupOf(array $run, array $finding): array
    {
        $security = JsonPath::arrayAt($finding, ['security']);
        $repositories = JsonPath::arrayAt($run, ['advisory_lookup', 'repositories']);
        $feeds = JsonPath::arrayAt($security, ['feeds']);

        return [
            'check' => $security['check'] ?? null,
            'unchecked_reason' => $security['unchecked_reason'] ?? null,
            'records' => $security['records'] ?? null,
            'repositories' => array_map(null, array_column($repositories, 'composer_repository'), array_column($repositories, 'outcome'), array_column($repositories, 'reason'), array_column($repositories, 'message')),
            'feeds' => array_map(null, array_column($feeds, 'composer_repository'), array_column($feeds, 'answer'), array_column($feeds, 'reason')),
        ];
    }
}
