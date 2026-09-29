<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Explain;

use Composer\Package\Loader\ArrayLoader;
use Lockrot\Analyzer\LibyearsMeasurement;
use Lockrot\Analyzer\Report;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Explain\Explanation;
use Lockrot\Signal\PhpFloor;
use Lockrot\Signal\Rule\PinnedRule;
use Lockrot\Signal\Signal;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\Notes;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\TestCase;

final class ExplanationTest extends TestCase
{
    private function report(): Report
    {
        return new Report([], Notes::texts(['a note']), new \DateTimeImmutable(F::NOW), 1, 0);
    }

    /** @param list<Signal> $signals */
    private function finding(string $version, string $verdict, array $signals = []): Finding
    {
        return new Finding('vendor/pkg', $version, $verdict, $signals, ['root/app', 'vendor/pkg'], null, new \DateTimeImmutable(F::NOW));
    }

    public function testTheExplanationCarriesTheFindingsLibyears(): void
    {
        $finding = new Finding('vendor/pkg', '1.4.0', Verdict::LEFT_BEHIND, [], ['root/app', 'vendor/pkg'], null, new \DateTimeImmutable(F::NOW), null, false, [], LibyearsMeasurement::of(2.345));
        $metadata = F::metadata([['1.5.0', '2021-06-01T00:00:00+00:00'], ['1.4.0', '2020-01-01T00:00:00+00:00']]);
        $explanation = new Explanation($finding, F::facts(F::package(['version' => '1.4.0']), $metadata), new Thresholds(), '8.4', $this->report());

        self::assertSame(2.35, JsonPath::arrayAt($explanation->toArray(), ['finding'])['libyears']);
    }

    public function testBranchesAreListedHighestFirstWithTheInstalledOneMarked(): void
    {
        // Listed out of order and with a two-digit major: the rows come out as versions order them, not as strings do.
        $metadata = F::metadata([['1.5.0', '2021-06-01T00:00:00+00:00'], ['0.3.1', '2018-01-01T00:00:00+00:00'], ['10.0.0', '2026-01-01T00:00:00+00:00'], ['2.1.0', '2026-01-01T00:00:00+00:00'], ['1.4.0', '2020-01-01T00:00:00+00:00']]);
        $explanation = new Explanation($this->finding('1.4.0', Verdict::LEFT_BEHIND), F::facts(F::package(['version' => '1.4.0']), $metadata), new Thresholds(), '8.4', $this->report());

        $branches = $explanation->branches();

        self::assertSame(['10.x', '2.x', '1.x', '0.3.x'], array_column($branches, 'branch'));
        self::assertSame([false, false, true, false], array_column($branches, 'installed'));
        self::assertSame('1.5.0', $branches[2]['highest']);
        self::assertSame('1', $explanation->installedBranch());
        self::assertFalse($explanation->installedBranchIsUndated());
    }

    /** illuminate/macroable: the installed branch's highest tag has no usable date, which is why S8 is quiet. */
    public function testAnUndatedHighestTagOnTheInstalledBranchIsReportedAsSuch(): void
    {
        $metadata = F::metadata([['13.0.0', '2026-01-01T00:00:00+00:00'], ['10.49.0', null], ['10.13.1', '2023-03-17T00:00:00+00:00']]);
        $explanation = new Explanation($this->finding('10.48.28', Verdict::OK), F::facts(F::package(['version' => '10.48.28']), $metadata), new Thresholds(), '8.4', $this->report());

        self::assertTrue($explanation->installedBranchIsUndated());
        $installed = $explanation->branches()[1];
        self::assertSame('10.x', $installed['branch']);
        self::assertNull($installed['highest_released']);
        self::assertSame('10.13.1', $installed['newest_dated']);
    }

    /**
     * A highest tag dated only by a commit other tags share is handed over undated by
     * {@see PackageMetadata::fromPackages()}, but the date it carried is still the branch's newest
     * dated release; the row keeps it as `highest_commit_date` so the reader can tell "no date"
     * from "the commit's date". A tag with no date at all has neither.
     */
    public function testASharedCommitTagKeepsItsCommitDateNextToTheMissingReleaseDate(): void
    {
        $loader = new ArrayLoader();
        $on = static fn (string $version, ?string $commit, ?string $time): array => array_filter(['name' => 'vendor/pkg', 'version' => $version, 'time' => $time, 'source' => $commit === null ? null : ['type' => 'git', 'url' => 'https://github.com/vendor/pkg.git', 'reference' => $commit]]);
        $metadata = PackageMetadata::fromPackages('vendor/pkg', [
            $loader->load($on('10.49.0', 'split', '2023-06-05T12:46:42+00:00')),
            $loader->load($on('10.20.0', 'split', '2023-06-05T12:46:42+00:00')),
            $loader->load($on('10.13.1', 'split', '2023-06-05T12:46:42+00:00')),
            $loader->load($on('9.0.0', null, null)),
        ], new \DateTimeImmutable(F::NOW));
        $explanation = new Explanation($this->finding('10.48.28', Verdict::OK), F::facts(F::package(['version' => '10.48.28']), $metadata), new Thresholds(), '8.4', $this->report());

        [$shared, $undated] = $explanation->branches();

        self::assertNull($shared['highest_released']);
        self::assertEquals(new \DateTimeImmutable('2023-06-05T12:46:42+00:00'), $shared['highest_commit_date']);
        self::assertNull($undated['highest_released']);
        self::assertNull($undated['highest_commit_date']);
        self::assertTrue($explanation->installedBranchIsUndated());
        $meta = $explanation->toArray()['metadata'];
        self::assertIsArray($meta);
        self::assertIsArray($meta['branches']);
        self::assertSame(
            ['branch' => '10.x', 'installed' => true, 'highest' => '10.49.0', 'highest_released' => null, 'highest_commit_date' => '2023-06-05T12:46:42+00:00', 'newest_dated' => '10.49.0', 'newest_dated_released' => '2023-06-05T12:46:42+00:00', 'dated_by' => null, 'php' => null, 'admits_target_php' => null, 'admits_project_php' => null, 'php_blocked_by' => null, 'misses_target_php' => null, 'misses_project_php' => null],
            $meta['branches'][0]
        );
    }

    public function testABranchSnapshotBelongsToNoBranch(): void
    {
        $explanation = new Explanation($this->finding('dev-main', Verdict::PINNED), F::facts(F::package(['version' => 'dev-main']), F::metadata([['1.0.0', '2026-01-01T00:00:00+00:00']])), new Thresholds(), '8.4', $this->report());

        self::assertNull($explanation->installedBranch());
        self::assertFalse($explanation->installedBranchIsUndated());
        self::assertSame([false], array_column($explanation->branches(), 'installed'));
    }

    /**
     * S6 repeats on the finding what the explanation's metadata and lock already say, under the same
     * names: one name means one fact on both surfaces. The signal comes from the rule itself, over
     * the same facts the explanation reads.
     */
    public function testS6AndTheExplanationStateTheSameReleaseFacts(): void
    {
        $cases = [
            'tagged' => F::metadata([['dev-main', '2025-03-04T05:06:07+00:00'], ['1.6.2', '2019-01-23T15:23:04+00:00']]),
            'never tagged' => F::metadata([['dev-main', '2025-03-04T05:06:07+00:00']]),
        ];
        foreach ($cases as $what => $metadata) {
            $facts = F::facts(F::package(['version' => 'dev-main', 'time' => '2025-03-04T05:06:07+00:00']), $metadata);
            $s6 = (new PinnedRule())->evaluate($facts);
            self::assertNotNull($s6, $what);
            $array = (new Explanation($this->finding('dev-main', Verdict::PINNED, [$s6]), $facts, new Thresholds(), '8.4', $this->report()))->toArray();

            $data = JsonPath::arrayAt($array, ['finding', 'signals', 0, 'data']);
            $meta = JsonPath::arrayAt($array, ['metadata']);
            foreach (['has_stable_release', 'last_stable_release', 'last_stable_version', 'last_stable_dated_by'] as $key) {
                self::assertSame($meta[$key], $data[$key], $what.': '.$key);
            }
            self::assertSame(JsonPath::arrayAt($array, ['lock'])['released'], $data['snapshot_time'], $what.': the snapshot is dated by the lock');
        }
    }

    public function testToArrayCarriesTheFindingTheFactsAndTheRun(): void
    {
        $signal = new Signal(Signal::S8, Signal::LEVEL_WARN, 'branch 1.x last released 2021-06-01 (5.3 years ago); 2.x released 2.1.0 (2026-01-01)', ['branch' => '1.x']);
        $metadata = F::metadata([['2.1.0', '2026-01-01T00:00:00+00:00'], ['1.5.0', '2021-06-01T00:00:00+00:00']]);
        $package = F::package(['version' => '1.5.0', 'php' => '>=7.1', 'time' => '2021-06-01T00:00:00+00:00']);
        $explanation = new Explanation($this->finding('1.5.0', Verdict::LEFT_BEHIND, [$signal]), F::facts($package, $metadata, F::activity(false, '2026-02-01T00:00:00+00:00')), new Thresholds(2, 4, 3, 5), '8.3', $this->report());

        $array = $explanation->toArray();

        self::assertSame(['package', 'version', 'finding', 'lock', 'metadata', 'activity', 'thresholds', 'target_php', 'project_php', 'generated_at', 'notes', 'note_details'], array_keys($array));
        self::assertSame('vendor/pkg', $array['package']);
        self::assertSame($finding = $explanation->finding()->toArray(), $array['finding'], 'the finding as --format=json carries it');
        self::assertSame(Verdict::LEFT_BEHIND, $finding['verdict']);
        self::assertSame(['php' => '>=7.1', 'released' => '2021-06-01T00:00:00+00:00', 'repository' => 'https://github.com/vendor/pkg.git', 'from_composer_repository' => true, 'dev' => false, 'branch_snapshot' => false, 'type' => 'library'], $array['lock']);
        self::assertSame([
            'abandoned' => false,
            'replacement' => null,
            'releases_listed' => 2,
            'has_stable_release' => true,
            'last_stable_release' => '2026-01-01T00:00:00+00:00',
            'last_stable_version' => '2.1.0',
            'last_stable_dated_by' => null,
            'installed_release' => '2021-06-01T00:00:00+00:00',
            'installed_release_dated_by' => null,
            'repository' => 'https://github.com/vendor/pkg.git',
            'type' => 'library',
            'data_date' => F::NOW,
            'branches' => [
                ['branch' => '2.x', 'installed' => false, 'highest' => '2.1.0', 'highest_released' => '2026-01-01T00:00:00+00:00', 'highest_commit_date' => null, 'newest_dated' => '2.1.0', 'newest_dated_released' => '2026-01-01T00:00:00+00:00', 'dated_by' => null, 'php' => null, 'admits_target_php' => null, 'admits_project_php' => null, 'php_blocked_by' => null, 'misses_target_php' => null, 'misses_project_php' => null],
                ['branch' => '1.x', 'installed' => true, 'highest' => '1.5.0', 'highest_released' => '2021-06-01T00:00:00+00:00', 'highest_commit_date' => null, 'newest_dated' => '1.5.0', 'newest_dated_released' => '2021-06-01T00:00:00+00:00', 'dated_by' => null, 'php' => null, 'admits_target_php' => null, 'admits_project_php' => null, 'php_blocked_by' => null, 'misses_target_php' => null, 'misses_project_php' => null],
            ],
        ], $array['metadata']);
        self::assertSame(['forge' => 'GitHub', 'repository' => 'vendor/pkg', 'archived' => false, 'pushed_at' => '2026-02-01T00:00:00+00:00', 'fetched_at' => F::NOW, 'from_cache' => false], $array['activity']);
        self::assertSame(['release-warn-years' => 2, 'release-high-years' => 4, 'push-warn-years' => 3, 'push-high-years' => 5], $array['thresholds']);
        self::assertSame('8.3', $array['target_php']);
        self::assertNull($array['project_php'], 'no project php was given');
        self::assertSame(F::NOW, $array['generated_at']);
        self::assertSame(['a note'], $array['notes']);
        self::assertSame(['a note'], array_column(JsonPath::arrayAt($array, ['note_details']), 'text'), 'the same notes, typed');
    }

    /**
     * A VCS repository on the machine that wrote the lock is a path with the account in it, and a
     * repository's metadata can name one by `file://`: each shows its last segment alone. A remote keeps its host.
     */
    public function testARepositoryIsShownByItsHostAndNeverByAPathOnTheMachine(): void
    {
        $at = new \DateTimeImmutable('2020-01-01T00:00:00+00:00');
        $remote = new PackageMetadata('vendor/pkg', false, null, true, $at, '1.0.0', 1, 'https://ci:t0k3n@git.acme.test/pkg.git?private_token=t0k3n', 'library', new \DateTimeImmutable(F::NOW));
        $local = new PackageMetadata('vendor/pkg', false, null, true, $at, '1.0.0', 1, 'file:///Users/igor/client-x/pkg', 'library', new \DateTimeImmutable(F::NOW));

        $onTheMachine = (new Explanation($this->finding('1.0.0', Verdict::STALE), F::facts(F::package(['source' => '/Users/igor/client-x/pkg']), $local), new Thresholds(), '8.4', $this->report()))->toArray();
        $elsewhere = (new Explanation($this->finding('1.0.0', Verdict::STALE), F::facts(F::package(['source' => 'igor@git.acme.test:pkg.git']), $remote), new Thresholds(), '8.4', $this->report()))->toArray();

        self::assertSame('.../pkg', JsonPath::arrayAt($onTheMachine, ['lock'])['repository']);
        self::assertSame('.../pkg', JsonPath::arrayAt($onTheMachine, ['metadata'])['repository']);
        self::assertStringNotContainsString('igor', (string) json_encode($onTheMachine));
        self::assertSame('git.acme.test:pkg.git', JsonPath::arrayAt($elsewhere, ['lock'])['repository']);
        self::assertSame('https://git.acme.test/pkg.git', JsonPath::arrayAt($elsewhere, ['metadata'])['repository']);
    }

    public function testMissingMetadataAndActivityAreNullNotEmpty(): void
    {
        $explanation = new Explanation($this->finding('1.0.0', Verdict::UNKNOWN), F::facts(F::package(['fromComposerRepository' => false])), new Thresholds(), '8.4', $this->report());

        $array = $explanation->toArray();

        self::assertNull($array['metadata']);
        self::assertNull($array['activity']);
        self::assertSame([], $explanation->branches());
        self::assertIsArray($array['lock']);
        self::assertFalse($array['lock']['from_composer_repository']);
    }

    /** A branch the monorepo parent dated ({@see PackageMetadata::datedBy()}) carries the parent on its row. */
    public function testABranchDatedByTheMonorepoIsMarkedAndNamed(): void
    {
        $loader = new ArrayLoader();
        $on = static fn (string $name, string $version, string $commit, string $time, array $replace = []): array => array_filter(['name' => $name, 'version' => $version, 'time' => $time, 'source' => ['type' => 'git', 'url' => 'https://github.com/'.$name.'.git', 'reference' => $commit], 'replace' => $replace]);
        $child = PackageMetadata::fromPackages('illuminate/contracts', [
            $loader->load($on('illuminate/contracts', 'v10.49.0', 'split', '2023-06-05T12:46:42+00:00')),
            $loader->load($on('illuminate/contracts', 'v10.20.0', 'split', '2023-06-05T12:46:42+00:00')),
            $loader->load($on('illuminate/contracts', 'v10.13.1', 'split', '2023-06-05T12:46:42+00:00')),
            $loader->load($on('illuminate/contracts', 'v9.52.0', 'own', '2023-01-01T00:00:00+00:00')),
        ], new \DateTimeImmutable(F::NOW));
        $parent = PackageMetadata::fromPackages('laravel/framework', [
            $loader->load($on('laravel/framework', 'v10.50.3', 'f10', '2026-08-12T03:46:26+00:00', ['illuminate/contracts' => 'self.version'])),
            $loader->load($on('laravel/framework', 'v10.48.28', 'f10-48', '2024-11-21T14:44:37+00:00')),
            $loader->load($on('laravel/framework', 'v9.52.22', 'f9', '2026-08-12T03:46:05+00:00')),
        ], new \DateTimeImmutable(F::NOW));
        $explanation = new Explanation($this->finding('v10.48.28', Verdict::OK), F::facts(F::package(['name' => 'illuminate/contracts', 'version' => 'v10.48.28', 'time' => '2023-06-05T12:46:42+00:00']), $child->datedBy($parent)), new Thresholds(), '8.4', $this->report());

        [$ten, $nine] = $explanation->branches();

        self::assertSame('laravel/framework', $ten['dated_by']);
        self::assertEquals(new \DateTimeImmutable('2026-08-12T03:46:26+00:00'), $ten['highest_released']);
        self::assertNull($ten['highest_commit_date']);
        self::assertSame('v10.50.3', $ten['highest']);
        self::assertNull($nine['dated_by'], 'a branch the package dated itself');
        self::assertFalse($explanation->installedBranchIsUndated());
        self::assertSame(['laravel/framework', ['10.x']], $explanation->branchesDatedBy());
        $array = $explanation->toArray();
        $meta = $array['metadata'];
        self::assertIsArray($meta);
        self::assertSame('laravel/framework', $meta['last_stable_dated_by']);
        self::assertIsArray($array['lock']);
        self::assertSame('2023-06-05T12:46:42+00:00', $array['lock']['released'], 'the lock\'s date is the shared commit\'s');
        self::assertSame('2024-11-21T14:44:37+00:00', $meta['installed_release'], 'the parent\'s v10.48.28 dates the installed version');
        self::assertSame('laravel/framework', $meta['installed_release_dated_by']);
        self::assertIsArray($meta['branches']);
        self::assertSame(
            ['branch' => '10.x', 'installed' => true, 'highest' => 'v10.50.3', 'highest_released' => '2026-08-12T03:46:26+00:00', 'highest_commit_date' => null, 'newest_dated' => 'v10.50.3', 'newest_dated_released' => '2026-08-12T03:46:26+00:00', 'dated_by' => 'laravel/framework', 'php' => null, 'admits_target_php' => null, 'admits_project_php' => null, 'php_blocked_by' => null, 'misses_target_php' => null, 'misses_project_php' => null],
            $meta['branches'][0]
        );
    }

    public function testNoBranchDatedByAParentIsNull(): void
    {
        $explanation = new Explanation($this->finding('1.0.0', Verdict::OK), F::facts(F::package(), F::metadata([['1.0.0', '2026-01-01T00:00:00+00:00']])), new Thresholds(), '8.4', $this->report());

        self::assertNull($explanation->branchesDatedBy());
    }

    /**
     * Matomo (`require.php >=7.2.5`) on PHP 8.4 locking monolog 1.x: 3.x needs php >=8.1, which
     * PHP 8.4 installs and Matomo's 7.2.5 does not; 2.x admits both; 1.x requires no PHP, so
     * neither floor has anything to say about it.
     */
    public function testEachBranchRowSaysWhichFloorAdmitsIt(): void
    {
        $rows = $this->admission('8.4', '>=7.2.5', [['3.12.0', '2026-09-09T00:00:00+00:00', '>=8.1'], ['2.11.1', '2026-09-02T00:00:00+00:00', '>=7.2'], ['1.27.1', '2022-06-09T00:00:00+00:00', null]]);

        self::assertSame(['3.x' => [true, false, 'project'], '2.x' => [true, true, null], '1.x' => [null, null, null]], $rows);
    }

    /** No composer.json php: the project column has no answer, and PHP 7.4 alone holds 3.x back. */
    public function testWithoutAProjectPhpOnlyTheTargetAnswers(): void
    {
        $rows = $this->admission('7.4', null, [['3.12.0', '2026-09-09T00:00:00+00:00', '>=8.1'], ['2.11.1', '2026-09-02T00:00:00+00:00', '>=7.2']]);

        self::assertSame(['3.x' => [false, null, 'target'], '2.x' => [true, null, null]], $rows);
    }

    /** A requirement lockrot cannot read is no answer from either floor: null, not true. */
    public function testAnUnreadableBranchRequirementGivesNoAnswer(): void
    {
        $rows = $this->admission('8.4', '>=7.2.5', [['3.12.0', '2026-09-09T00:00:00+00:00', 'not a constraint']]);

        self::assertSame(['3.x' => [null, null, null]], $rows);
    }

    /**
     * The installed row gets the same test as any other: a project whose require.php promises
     * 7.2.5, on a branch whose newest dated release needs 8.1, shows `project` on its own row.
     */
    public function testTheInstalledRowIsTestedLikeAnyOther(): void
    {
        $metadata = F::metadata([['3.12.0', '2026-09-09T00:00:00+00:00', '>=8.1']]);
        $explanation = new Explanation($this->finding('3.12.0', Verdict::OK), F::facts(F::package(['version' => '3.12.0']), $metadata), new Thresholds(), '8.4', $this->report(), '>=7.2.5');

        $meta = $explanation->toArray()['metadata'];
        self::assertIsArray($meta);
        self::assertIsArray($meta['branches']);
        self::assertIsArray($meta['branches'][0]);
        self::assertTrue($meta['branches'][0]['installed']);
        self::assertSame('project', $meta['branches'][0]['php_blocked_by']);
        self::assertSame(PhpFloor::NEEDS_NEWER, $meta['branches'][0]['misses_project_php'], 'it needs a newer PHP than 7.2.5');
        self::assertNull($meta['branches'][0]['misses_target_php'], '8.4 is admitted');
    }

    /**
     * The installed branch's newest release stops before the target: phpstan-src locks nette/utils
     * 3.x, whose newest release asks for `>=7.2 <8.4`. Moving to 8.4 means moving to another branch,
     * which is not what a branch needing a newer PHP asks for.
     */
    public function testTheInstalledRowSaysItsBranchStopsBeforeTheTarget(): void
    {
        $metadata = F::metadata([['3.2.10', '2024-08-07T00:00:00+00:00', '>=7.2 <8.4']]);
        $explanation = new Explanation($this->finding('3.2.10', Verdict::OK), F::facts(F::package(['version' => '3.2.10']), $metadata), new Thresholds(), '8.4', $this->report(), '^7.4 || ^8.0');

        $row = JsonPath::arrayAt($explanation->toArray(), ['metadata', 'branches', 0]);
        self::assertTrue($row['installed']);
        self::assertSame([false, true, PhpFloor::TARGET, PhpFloor::STOPS_BEFORE, null], [$row['admits_target_php'], $row['admits_project_php'], $row['php_blocked_by'], $row['misses_target_php'], $row['misses_project_php']]);
    }

    /**
     * A branch can miss both floors, each its own way: `~8.3.0` needs a newer PHP than wallabag's
     * `>=8.2` and stops before 8.4. php_blocked_by names the project only; the target's side is
     * still there.
     */
    public function testARowMissingBothFloorsSaysHowItMissesEach(): void
    {
        $rows = $this->sides('8.4', '>=8.2', [['2.0.0', '2026-01-01T00:00:00+00:00', '~8.3.0'], ['1.0.0', '2021-01-01T00:00:00+00:00', '>=7.4']]);

        self::assertSame([PhpFloor::PROJECT, PhpFloor::STOPS_BEFORE, PhpFloor::NEEDS_NEWER], $rows['2.x']);
        self::assertSame([null, null, null], $rows['1.x'], 'admits both');
    }

    /** No project floor: the project's side has no answer, and the target's is still given. */
    public function testWithoutAProjectFloorOnlyTheTargetHasASide(): void
    {
        $rows = $this->sides('8.4', null, [['2.0.0', '2026-01-01T00:00:00+00:00', '^7.4 || ^8.5'], ['1.0.0', '2021-01-01T00:00:00+00:00', '>=7.4']]);

        self::assertSame([PhpFloor::TARGET, PhpFloor::SKIPS, null], $rows['2.x']);
    }

    /**
     * What `project` on the installed row does not mean: that the locked version needs more PHP.
     * A row's `php` is its branch's newest dated release's requirement, so a lock on 3.10.0, which
     * asks for `^7.2|^8.0`, still reads `project` once 3.12.0 on the same branch asks for 8.1 — as
     * laravel/socialite does in koel's lock. The locked version's own requirement is `lock.php`.
     */
    public function testTheInstalledRowIsHeldToItsBranchsNewestReleaseNotTheLockedVersion(): void
    {
        $metadata = F::metadata([
            ['3.12.0', '2026-09-09T00:00:00+00:00', '>=8.1'],
            ['3.10.0', '2025-01-01T00:00:00+00:00', '^7.2|^8.0'],
        ]);
        $package = F::package(['version' => '3.10.0', 'php' => '^7.2|^8.0', 'time' => '2025-01-01T00:00:00+00:00']);
        $explanation = new Explanation($this->finding('3.10.0', Verdict::OK), F::facts($package, $metadata), new Thresholds(), '8.4', $this->report(), '>=7.2.5');

        $document = $explanation->toArray();
        self::assertSame('^7.2|^8.0', JsonPath::stringAt($document, ['lock', 'php']), 'the locked version admits 7.2.5');
        self::assertSame('3.12.0', JsonPath::stringAt($document, ['metadata', 'branches', 0, 'newest_dated']));
        self::assertSame('>=8.1', JsonPath::stringAt($document, ['metadata', 'branches', 0, 'php']));
        self::assertTrue(JsonPath::arrayAt($document, ['metadata', 'branches', 0])['installed']);
        self::assertSame('project', JsonPath::stringAt($document, ['metadata', 'branches', 0, 'php_blocked_by']));
    }

    /** The project php the rows were held against is in the document, as composer.json writes it. */
    public function testTheProjectPhpIsCarriedAsWritten(): void
    {
        $explanation = new Explanation($this->finding('1.0.0', Verdict::OK), F::facts(F::package()), new Thresholds(), '8.4', $this->report(), '^7.2.5 || ^8.0');

        self::assertSame('^7.2.5 || ^8.0', $explanation->projectPhp());
        self::assertSame('^7.2.5 || ^8.0', $explanation->toArray()['project_php']);
        self::assertNull((new Explanation($this->finding('1.0.0', Verdict::OK), F::facts(F::package()), new Thresholds(), '8.4', $this->report()))->projectPhp());
    }

    /**
     * @param list<array{0: string, 1: ?string, 2?: ?string}> $releases
     *
     * @return array<string, array{0: mixed, 1: mixed, 2: mixed}> php_blocked_by and the two sides, by branch
     */
    private function sides(string $target, ?string $project, array $releases): array
    {
        $explanation = new Explanation($this->finding('1.0.0', Verdict::OK), F::facts(F::package(['version' => '1.0.0']), F::metadata($releases)), new Thresholds(), $target, $this->report(), $project);
        $rows = [];
        foreach (JsonPath::arrayAt($explanation->toArray(), ['metadata', 'branches']) as $row) {
            self::assertIsArray($row);
            self::assertIsString($row['branch']);
            $rows[$row['branch']] = [$row['php_blocked_by'], $row['misses_target_php'], $row['misses_project_php']];
        }

        return $rows;
    }

    /**
     * @param list<array{0: string, 1: ?string, 2?: ?string}> $releases
     *
     * @return array<string, array{0: mixed, 1: mixed, 2: mixed}>
     */
    private function admission(string $target, ?string $project, array $releases): array
    {
        $explanation = new Explanation($this->finding('1.0.0', Verdict::OK), F::facts(F::package(['version' => '1.0.0']), F::metadata($releases)), new Thresholds(), $target, $this->report(), $project);
        $meta = $explanation->toArray()['metadata'];
        self::assertIsArray($meta);
        self::assertIsArray($meta['branches']);
        $rows = [];
        foreach ($meta['branches'] as $row) {
            self::assertIsArray($row);
            self::assertIsString($row['branch']);
            $rows[$row['branch']] = [$row['admits_target_php'], $row['admits_project_php'], $row['php_blocked_by']];
        }

        return $rows;
    }
}
