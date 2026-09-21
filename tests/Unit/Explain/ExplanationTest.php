<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Explain;

use Composer\Package\Loader\ArrayLoader;
use Lockrot\Analyzer\Report;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Explain\Explanation;
use Lockrot\Signal\Signal;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\TestCase;

final class ExplanationTest extends TestCase
{
    private function report(): Report
    {
        return new Report([], ['a note'], new \DateTimeImmutable(F::NOW), 1, 0, false);
    }

    /** @param list<Signal> $signals */
    private function finding(string $version, string $verdict, array $signals = []): Finding
    {
        return new Finding('vendor/pkg', $version, $verdict, $signals, ['root/app', 'vendor/pkg'], null, new \DateTimeImmutable(F::NOW));
    }

    public function testTheExplanationCarriesTheFindingsLibyears(): void
    {
        $finding = new Finding('vendor/pkg', '1.4.0', Verdict::LEFT_BEHIND, [], ['root/app', 'vendor/pkg'], null, new \DateTimeImmutable(F::NOW), null, false, [], 2.345);
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
            ['branch' => '10.x', 'installed' => true, 'highest' => '10.49.0', 'highest_released' => null, 'highest_commit_date' => '2023-06-05T12:46:42+00:00', 'newest_dated' => '10.49.0', 'newest_dated_released' => '2023-06-05T12:46:42+00:00', 'dated_by' => null],
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

    public function testToArrayCarriesTheFindingTheFactsAndTheRun(): void
    {
        $signal = new Signal(Signal::S8, Signal::LEVEL_WARN, 'branch 1.x last released 2021-06-01 (5.3 years ago); 2.x released 2.1.0 (2026-01-01)', ['branch' => '1.x']);
        $metadata = F::metadata([['2.1.0', '2026-01-01T00:00:00+00:00'], ['1.5.0', '2021-06-01T00:00:00+00:00']]);
        $package = F::package(['version' => '1.5.0', 'php' => '>=7.1', 'time' => '2021-06-01T00:00:00+00:00']);
        $explanation = new Explanation($this->finding('1.5.0', Verdict::LEFT_BEHIND, [$signal]), F::facts($package, $metadata, F::activity(false, '2026-02-01T00:00:00+00:00')), new Thresholds(2, 4, 3, 5), '8.3', $this->report());

        $array = $explanation->toArray();

        self::assertSame(['package', 'version', 'finding', 'lock', 'metadata', 'activity', 'thresholds', 'target_php', 'generated_at', 'notes'], array_keys($array));
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
            'repository' => 'https://github.com/vendor/pkg.git',
            'type' => 'library',
            'data_date' => F::NOW,
            'branches' => [
                ['branch' => '2.x', 'installed' => false, 'highest' => '2.1.0', 'highest_released' => '2026-01-01T00:00:00+00:00', 'highest_commit_date' => null, 'newest_dated' => '2.1.0', 'newest_dated_released' => '2026-01-01T00:00:00+00:00', 'dated_by' => null],
                ['branch' => '1.x', 'installed' => true, 'highest' => '1.5.0', 'highest_released' => '2021-06-01T00:00:00+00:00', 'highest_commit_date' => null, 'newest_dated' => '1.5.0', 'newest_dated_released' => '2021-06-01T00:00:00+00:00', 'dated_by' => null],
            ],
        ], $array['metadata']);
        self::assertSame(['forge' => 'GitHub', 'repository' => 'vendor/pkg', 'archived' => false, 'pushed_at' => '2026-02-01T00:00:00+00:00', 'fetched_at' => F::NOW, 'from_cache' => false], $array['activity']);
        self::assertSame(['release-warn-years' => 2, 'release-high-years' => 4, 'push-warn-years' => 3, 'push-high-years' => 5], $array['thresholds']);
        self::assertSame('8.3', $array['target_php']);
        self::assertSame(F::NOW, $array['generated_at']);
        self::assertSame(['a note'], $array['notes']);
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
            $loader->load($on('laravel/framework', 'v9.52.22', 'f9', '2026-08-12T03:46:05+00:00')),
        ], new \DateTimeImmutable(F::NOW));
        $explanation = new Explanation($this->finding('v10.48.28', Verdict::OK), F::facts(F::package(['name' => 'illuminate/contracts', 'version' => 'v10.48.28']), $child->datedBy($parent)), new Thresholds(), '8.4', $this->report());

        [$ten, $nine] = $explanation->branches();

        self::assertSame('laravel/framework', $ten['dated_by']);
        self::assertEquals(new \DateTimeImmutable('2026-08-12T03:46:26+00:00'), $ten['highest_released']);
        self::assertNull($ten['highest_commit_date']);
        self::assertSame('v10.50.3', $ten['highest']);
        self::assertNull($nine['dated_by'], 'a branch the package dated itself');
        self::assertFalse($explanation->installedBranchIsUndated());
        self::assertSame(['laravel/framework', ['10.x']], $explanation->branchesDatedBy());
        $meta = $explanation->toArray()['metadata'];
        self::assertIsArray($meta);
        self::assertSame('laravel/framework', $meta['last_stable_dated_by']);
        self::assertIsArray($meta['branches']);
        self::assertSame(
            ['branch' => '10.x', 'installed' => true, 'highest' => 'v10.50.3', 'highest_released' => '2026-08-12T03:46:26+00:00', 'highest_commit_date' => null, 'newest_dated' => 'v10.50.3', 'newest_dated_released' => '2026-08-12T03:46:26+00:00', 'dated_by' => 'laravel/framework'],
            $meta['branches'][0]
        );
    }

    public function testNoBranchDatedByAParentIsNull(): void
    {
        $explanation = new Explanation($this->finding('1.0.0', Verdict::OK), F::facts(F::package(), F::metadata([['1.0.0', '2026-01-01T00:00:00+00:00']])), new Thresholds(), '8.4', $this->report());

        self::assertNull($explanation->branchesDatedBy());
    }
}
