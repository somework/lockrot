<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Repository;

use Composer\Package\CompletePackage;
use Composer\Package\Loader\ArrayLoader;
use Lockrot\Data\Repository\PackageMetadata;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A split package dated by its monorepo parent: illuminate/contracts by laravel/framework. The
 * child's tags pile up on one commit and are handed over undated by fromPackages(). The parent's
 * tag for the same version is dated by its release, and `replace` says the versions are the same.
 */
final class PackageMetadataDatedByTest extends TestCase
{
    private const NOW = '2026-09-14T00:00:00+00:00';

    /** @param array<string, mixed> $config */
    private function load(array $config): CompletePackage
    {
        $package = (new ArrayLoader())->load($config);
        self::assertInstanceOf(CompletePackage::class, $package);

        return $package;
    }

    /**
     * @param array<string, string> $replace
     *
     * @return array<string, mixed>
     */
    private static function tag(string $name, string $version, string $commit, string $time, array $replace = []): array
    {
        $config = ['name' => $name, 'version' => $version, 'time' => $time, 'source' => ['type' => 'git', 'url' => 'https://github.com/'.$name.'.git', 'reference' => $commit]];
        if ($replace !== []) {
            $config['replace'] = $replace;
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function withPhp(array $config, string $php): array
    {
        $config['require'] = ['php' => $php];

        return $config;
    }

    /** contracts: 8.x highest on a commit shared by three tags (undated), 7.x on its own commit, 13.x highest of all, also shared. */
    private function child(): PackageMetadata
    {
        return PackageMetadata::fromPackages('illuminate/contracts', [
            $this->load(self::tag('illuminate/contracts', 'v13.24.0', 'c13-shared', '2026-06-01T00:00:00+00:00')),
            $this->load(self::tag('illuminate/contracts', 'v13.23.0', 'c13-shared', '2026-06-01T00:00:00+00:00')),
            $this->load(self::tag('illuminate/contracts', 'v13.22.0', 'c13-shared', '2026-06-01T00:00:00+00:00')),
            $this->load(self::tag('illuminate/contracts', 'v8.83.27', 'c8-shared', '2022-01-13T14:47:47+00:00')),
            $this->load(self::tag('illuminate/contracts', 'v8.83.26', 'c8-shared', '2022-01-13T14:47:47+00:00')),
            $this->load(self::tag('illuminate/contracts', 'v8.80.0', 'c8-shared', '2022-01-13T14:47:47+00:00')),
            $this->load(self::tag('illuminate/contracts', 'v7.30.6', 'c7-own', '2021-11-17T15:00:14+00:00')),
        ], new \DateTimeImmutable(self::NOW));
    }

    /**
     * framework: every branch dated by its own release, 8.x a step above the child's highest 8.x tag.
     *
     * @param array<string, string> $replace
     */
    private function parent(array $replace = ['illuminate/contracts' => 'self.version']): PackageMetadata
    {
        return PackageMetadata::fromPackages('laravel/framework', [
            $this->load(self::tag('laravel/framework', 'v13.24.0', 'f13-24', '2026-09-01T00:00:00+00:00', $replace)),
            $this->load(self::tag('laravel/framework', 'v8.83.29', 'f8-29', '2024-11-20T15:55:41+00:00')),
            $this->load(self::tag('laravel/framework', 'v8.83.27', 'f8-27', '2022-01-13T14:47:47+00:00')),
            $this->load(self::tag('laravel/framework', 'v7.30.7', 'f7-7', '2024-11-12T15:42:13+00:00')),
        ], new \DateTimeImmutable(self::NOW));
    }

    public function testFromPackagesCollectsTheReplacedNamesOverEveryVersion(): void
    {
        $parent = PackageMetadata::fromPackages('laravel/framework', [
            $this->load(self::tag('laravel/framework', 'v13.0.0', 'a', '2026-01-01T00:00:00+00:00', ['illuminate/contracts' => 'self.version', 'illuminate/support' => 'self.version'])),
            $this->load(self::tag('laravel/framework', 'v5.0.0', 'b', '2015-01-01T00:00:00+00:00', ['illuminate/html' => 'self.version'])),
        ], new \DateTimeImmutable(self::NOW));

        self::assertSame(['illuminate/contracts', 'illuminate/support', 'illuminate/html'], $parent->replaces(), 'a component that left the monorepo is still one it once carried');
        self::assertSame([], $this->child()->replaces());
    }

    public function testTheChildStartsUndatedWhereItsTagsShareACommit(): void
    {
        $child = $this->child();
        $byBranch = $child->latestStableByBranch();

        self::assertNull($byBranch['8']['highest']['at']);
        self::assertNull($byBranch['13']['highest']['at']);
        self::assertNotNull($byBranch['7']['highest']['at']);
        self::assertNull($child->lastStableReleaseAt(), 'the highest tag of all is shared, so the package\'s own age is unknown');
        self::assertTrue($child->needsParentDates('8'));
        self::assertTrue($child->needsParentDates('7'), 'the installed branch is dated, but the package\'s last release is not');
        self::assertTrue($child->needsParentDates(null));
    }

    public function testAnUndatedBranchTakesTheParentsEntryAndIsMarked(): void
    {
        $dated = $this->child()->datedBy($this->parent());
        $byBranch = $dated->latestStableByBranch();

        self::assertSame('v8.83.29', $byBranch['8']['version'], 'the branch\'s newest release is the parent\'s, a step above the child\'s own highest tag');
        self::assertEquals(new \DateTimeImmutable('2024-11-20T15:55:41+00:00'), $byBranch['8']['at']);
        self::assertSame('8.83.29.0', $byBranch['8']['highest']['normalized']);
        self::assertEquals(new \DateTimeImmutable('2024-11-20T15:55:41+00:00'), $byBranch['8']['highest']['at']);
        self::assertSame('laravel/framework', $byBranch['8']['dated_by'] ?? null);

        self::assertSame('v7.30.6', $byBranch['7']['version'], 'a branch the child dates itself is left alone, even where the parent has it');
        self::assertEquals(new \DateTimeImmutable('2021-11-17T15:00:14+00:00'), $byBranch['7']['at']);
        self::assertArrayNotHasKey('dated_by', $byBranch['7']);
    }

    /**
     * The parent dates the branch. What the branch requires stays the child's own. illuminate/contracts
     * 8.x asks for `^7.3|^8.0` and laravel/framework 8.x for the same plus extensions, but a
     * split is not obliged to mirror its parent, and the requirement S8 reads is the split's — the
     * package the lock installs.
     */
    public function testADatedBranchKeepsTheChildsOwnPhpRequirement(): void
    {
        $child = PackageMetadata::fromPackages('illuminate/contracts', [
            $this->load(self::withPhp(self::tag('illuminate/contracts', 'v8.83.27', 'c8-shared', '2022-01-13T14:47:47+00:00'), '^7.3|^8.0')),
            $this->load(self::withPhp(self::tag('illuminate/contracts', 'v8.83.26', 'c8-shared', '2022-01-13T14:47:47+00:00'), '^7.3|^8.0')),
            $this->load(self::withPhp(self::tag('illuminate/contracts', 'v8.80.0', 'c8-shared', '2022-01-13T14:47:47+00:00'), '^7.3|^8.0')),
        ], new \DateTimeImmutable(self::NOW));
        $parent = PackageMetadata::fromPackages('laravel/framework', [
            $this->load(self::withPhp(self::tag('laravel/framework', 'v8.83.29', 'f8-29', '2024-11-20T15:55:41+00:00', ['illuminate/contracts' => 'self.version']), '^7.3|^8.0.2')),
        ], new \DateTimeImmutable(self::NOW));

        $branch = $child->datedBy($parent)->latestStableByBranch()['8'];

        self::assertSame('laravel/framework', $branch['dated_by'] ?? null);
        self::assertSame('^7.3|^8.0', $branch['php']);
        self::assertSame('^7.3|^8.0', $branch['highest']['php'], 'the highest tag keeps the child\'s requirement too');
    }

    public function testThePackagesOwnLastReleaseFollowsWhenTheParentDatesItsHighestBranch(): void
    {
        $dated = $this->child()->datedBy($this->parent());

        self::assertEquals(new \DateTimeImmutable('2026-09-01T00:00:00+00:00'), $dated->lastStableReleaseAt());
        self::assertSame('v13.24.0', $dated->lastStableVersion());
        self::assertSame('laravel/framework', $dated->lastStableDatedBy());
        self::assertFalse($dated->needsParentDates('8'));
        self::assertFalse($dated->needsParentDates('13'));
    }

    public function testTheOriginalIsUntouched(): void
    {
        $child = $this->child();
        $child->datedBy($this->parent());

        self::assertNull($child->latestStableByBranch()['8']['highest']['at']);
        self::assertNull($child->lastStableReleaseAt());
        self::assertNull($child->lastStableDatedBy());
    }

    public function testAPackageTheParentDoesNotReplaceIsReturnedAsIs(): void
    {
        $child = $this->child();

        self::assertSame($child, $child->datedBy($this->parent(['symfony/yaml' => 'self.version'])));
        self::assertSame($child, $child->datedBy($this->parent([])));
    }

    public function testABranchTheParentLacksOrLeavesUndatedStaysAsItWas(): void
    {
        $parent = PackageMetadata::fromPackages('laravel/framework', [
            $this->load(self::tag('laravel/framework', 'v13.24.0', 'f13-24', '2026-09-01T00:00:00+00:00', ['illuminate/contracts' => 'self.version'])),
            // 8.x in the parent is itself on a commit three tags share: undated there too.
            $this->load(self::tag('laravel/framework', 'v8.83.29', 'f8-shared', '2024-11-20T15:55:41+00:00')),
            $this->load(self::tag('laravel/framework', 'v8.83.28', 'f8-shared', '2024-11-20T15:55:41+00:00')),
            $this->load(self::tag('laravel/framework', 'v8.83.27', 'f8-shared', '2024-11-20T15:55:41+00:00')),
        ], new \DateTimeImmutable(self::NOW));

        $dated = $this->child()->datedBy($parent);
        $byBranch = $dated->latestStableByBranch();

        self::assertNull($byBranch['8']['highest']['at'], 'the parent cannot date 8.x either');
        self::assertArrayNotHasKey('dated_by', $byBranch['8']);
        self::assertSame('laravel/framework', $byBranch['13']['dated_by'] ?? null, '13.x it can');
        self::assertSame('laravel/framework', $dated->lastStableDatedBy());
    }

    public function testAParentThatDatesNoBranchStillHandsOverItsReleaseDates(): void
    {
        // No branch in common, so the branch view and the package's own age stay as they were —
        // but the installed version can be one of the parent's releases all the same (a tag on a
        // shared commit under a branch whose highest tag is not), and its date travels.
        $child = $this->child();
        $parent = PackageMetadata::fromPackages('laravel/framework', [
            $this->load(self::tag('laravel/framework', 'v5.8.0', 'f5', '2019-01-01T00:00:00+00:00', ['illuminate/contracts' => 'self.version'])),
        ], new \DateTimeImmutable(self::NOW));

        $dated = $child->datedBy($parent);

        self::assertNotSame($child, $dated);
        self::assertSame($child->latestStableByBranch(), $dated->latestStableByBranch(), 'no branch dated');
        self::assertNull($dated->lastStableDatedBy());
        self::assertNull($dated->lastStableReleaseAt());
        self::assertEquals(new \DateTimeImmutable('2019-01-01T00:00:00+00:00'), $dated->releaseDateOf('5.8.0.0'));
        self::assertSame('laravel/framework', $dated->releaseDatesBy());
    }

    public function testAParentWithNoDatedReleaseReturnsTheSameObject(): void
    {
        $child = $this->child();
        $parent = PackageMetadata::fromPackages('laravel/framework', [
            $this->load(['name' => 'laravel/framework', 'version' => 'v5.8.0', 'replace' => ['illuminate/contracts' => 'self.version'], 'source' => ['type' => 'git', 'url' => 'https://github.com/laravel/framework.git', 'reference' => 'f5']]),
        ], new \DateTimeImmutable(self::NOW));

        self::assertSame($child, $child->datedBy($parent), 'no branch in common and no date to hand over: nothing to do');
    }

    public function testAnOwnDatedPackageNeedsNoParent(): void
    {
        $own = PackageMetadata::fromPackages('vendor/pkg', [
            $this->load(self::tag('vendor/pkg', '2.0.0', 'a', '2026-01-01T00:00:00+00:00')),
            $this->load(self::tag('vendor/pkg', '1.0.0', 'b', '2020-01-01T00:00:00+00:00')),
        ], new \DateTimeImmutable(self::NOW));

        self::assertFalse($own->needsParentDates('1'));
        self::assertFalse($own->needsParentDates('2'));
        self::assertFalse($own->needsParentDates('3'), 'a branch the repository does not list is not one a parent could date');
        self::assertFalse($own->needsParentDates(null));
    }

    public function testADevOnlyPackageNeedsNoParent(): void
    {
        $dev = PackageMetadata::fromPackages('vendor/pkg', [
            $this->load(['name' => 'vendor/pkg', 'version' => 'dev-main']),
        ], new \DateTimeImmutable(self::NOW));

        self::assertFalse($dev->hasStableRelease());
        self::assertFalse($dev->needsParentDates(null));
    }

    /**
     * A tool that replaces a package to keep it from being installed alongside its own prefixed
     * copy — symplify/easy-coding-standard replaces symfony/polyfill-ctype at `*` — is not the
     * package's monorepo, and its release line says nothing about when the polyfill released.
     */
    public function testOnlyASelfVersionReplaceMakesAParent(): void
    {
        $tool = PackageMetadata::fromPackages('symplify/easy-coding-standard', [
            $this->load(['name' => 'symplify/easy-coding-standard', 'version' => '13.3.2', 'time' => '2026-09-01T00:00:00+00:00', 'replace' => ['symfony/polyfill-ctype' => '*', 'symfony/process' => '7.*']]),
        ], new \DateTimeImmutable(self::NOW));

        self::assertSame([], $tool->replaces());

        $polyfill = PackageMetadata::fromPackages('symfony/polyfill-ctype', [
            $this->load(self::tag('symfony/polyfill-ctype', 'v1.37.0', 'polyfill-cut', '2026-04-10T16:19:22+00:00')),
            $this->load(self::tag('symfony/polyfill-ctype', 'v1.36.0', 'polyfill-cut', '2026-04-10T16:19:22+00:00')),
            $this->load(self::tag('symfony/polyfill-ctype', 'v1.35.0', 'polyfill-cut', '2026-04-10T16:19:22+00:00')),
        ], new \DateTimeImmutable(self::NOW));

        self::assertTrue($polyfill->needsParentDates('1'));
        self::assertSame($polyfill, $polyfill->datedBy($tool));
    }

    public function testAParentKeepsADatePerStableReleaseAndAnOrdinaryPackageKeepsNone(): void
    {
        $parent = PackageMetadata::fromPackages('laravel/framework', [
            $this->load(self::tag('laravel/framework', 'v13.24.0', 'f13-24', '2026-09-01T00:00:00+00:00', ['illuminate/contracts' => 'self.version'])),
            $this->load(self::tag('laravel/framework', 'v14.0.0-beta.1', 'f14-b1', '2026-09-10T00:00:00+00:00')),
            $this->load(self::tag('laravel/framework', 'v8.83.29', 'f8-29', '2024-11-20T15:55:41+00:00')),
            $this->load(['name' => 'laravel/framework', 'version' => 'v8.83.28', 'source' => ['type' => 'git', 'url' => 'https://github.com/laravel/framework.git', 'reference' => 'f8-28']]),
            $this->load(['name' => 'laravel/framework', 'version' => '13.x-dev', 'time' => '2026-09-13T00:00:00+00:00', 'source' => ['type' => 'git', 'url' => 'https://github.com/laravel/framework.git', 'reference' => 'f13-dev']]),
        ], new \DateTimeImmutable(self::NOW));

        self::assertEquals(new \DateTimeImmutable('2024-11-20T15:55:41+00:00'), $parent->releaseDateOf('8.83.29.0'));
        self::assertEquals(new \DateTimeImmutable('2026-09-01T00:00:00+00:00'), $parent->releaseDateOf('13.24.0.0'));
        self::assertNull($parent->releaseDateOf('8.83.28.0'), 'a tag the repository does not date');
        self::assertNull($parent->releaseDateOf('14.0.0.0-beta1'), 'a pre-release is not a release');
        self::assertNull($parent->releaseDateOf('13.9999999.9999999.9999999-dev'), 'nor is a branch');
        self::assertNull($parent->releaseDateOf('9.9.9.0'), 'a version the parent never had');
        self::assertNull($parent->releaseDatesBy(), 'its own dates');

        self::assertNull($this->child()->releaseDateOf('7.30.6.0'), 'an ordinary package keeps no dates: its releases are the lock\'s business, and it has no children');
        self::assertNull($this->child()->releaseDatesBy());
    }

    public function testAParentLeavesOutTheReleasesItDatesByASharedCommit(): void
    {
        $parent = PackageMetadata::fromPackages('laravel/framework', [
            $this->load(self::tag('laravel/framework', 'v13.24.0', 'f13-24', '2026-09-01T00:00:00+00:00', ['illuminate/contracts' => 'self.version'])),
            $this->load(self::tag('laravel/framework', 'v8.83.29', 'f8-shared', '2024-11-20T15:55:41+00:00')),
            $this->load(self::tag('laravel/framework', 'v8.83.28', 'f8-shared', '2024-11-20T15:55:41+00:00')),
            $this->load(self::tag('laravel/framework', 'v8.83.27', 'f8-shared', '2024-11-20T15:55:41+00:00')),
            $this->load(self::tag('laravel/framework', 'v7.30.7', 'f7-two', '2024-11-12T15:42:13+00:00')),
            $this->load(self::tag('laravel/framework', 'v7.30.6', 'f7-two', '2024-11-12T15:42:13+00:00')),
        ], new \DateTimeImmutable(self::NOW));

        self::assertNull($parent->releaseDateOf('8.83.29.0'), 'three tags on one commit: the date is the commit\'s, not a release\'s');
        self::assertNull($parent->releaseDateOf('8.83.27.0'));
        self::assertEquals(new \DateTimeImmutable('2024-11-12T15:42:13+00:00'), $parent->releaseDateOf('7.30.6.0'), 'two on one commit keep their date, as the branch view does');
        self::assertEquals(new \DateTimeImmutable('2026-09-01T00:00:00+00:00'), $parent->releaseDateOf('13.24.0.0'));
    }

    public function testTheDatedChildReadsTheParentsDatePerReleaseAndSaysWhose(): void
    {
        $child = $this->child();
        $dated = $child->datedBy($this->parent());

        self::assertEquals(new \DateTimeImmutable('2022-01-13T14:47:47+00:00'), $dated->releaseDateOf('8.83.27.0'), 'the parent\'s v8.83.27 dates the child\'s');
        self::assertEquals(new \DateTimeImmutable('2024-11-12T15:42:13+00:00'), $dated->releaseDateOf('7.30.7.0'));
        self::assertNull($dated->releaseDateOf('8.83.26.0'), 'a version the parent does not list');
        self::assertSame('laravel/framework', $dated->releaseDatesBy());
        self::assertNull($child->releaseDateOf('8.83.27.0'), 'the original is untouched');
        self::assertNull($child->releaseDatesBy());
    }

    /**
     * The installed version on a shared commit under a branch whose highest tag has one of its
     * own — the directory changed after a run of releases cut without a change, so only the newest
     * tags are dated. The branch and the package's age read fine, and the lock's date for the
     * installed version is still the shared commit's: the parent is what dates it.
     */
    public function testAnInstalledVersionOnASharedCommitNeedsTheParentWhenItsBranchIsDated(): void
    {
        $child = PackageMetadata::fromPackages('illuminate/macroable', [
            $this->load(self::tag('illuminate/macroable', 'v10.49.0', 'c10-own', '2024-11-21T00:00:00+00:00')),
            $this->load(self::tag('illuminate/macroable', 'v10.48.28', 'c10-shared', '2023-06-05T00:00:00+00:00')),
            $this->load(self::tag('illuminate/macroable', 'v10.48.27', 'c10-shared', '2023-06-05T00:00:00+00:00')),
            $this->load(self::tag('illuminate/macroable', 'v10.48.26', 'c10-shared', '2023-06-05T00:00:00+00:00')),
            $this->load(self::tag('illuminate/macroable', 'v10.48.25', 'c10-two', '2023-05-01T00:00:00+00:00')),
            $this->load(self::tag('illuminate/macroable', 'v10.48.24', 'c10-two', '2023-05-01T00:00:00+00:00')),
        ], new \DateTimeImmutable(self::NOW));

        self::assertNotNull($child->latestStableByBranch()['10']['highest']['at'], 'the branch\'s highest tag is dated by its own commit');
        self::assertNotNull($child->lastStableReleaseAt());
        self::assertTrue($child->needsParentDates('10', 'v10.48.28'), 'the installed tag shares its commit with two others');
        self::assertTrue($child->needsParentDates('10', '10.48.26'), 'read in either form the lock prints');
        self::assertFalse($child->needsParentDates('10', 'v10.49.0'), 'a tag on its own commit is dated by it');
        self::assertFalse($child->needsParentDates('10', 'v10.48.24'), 'two on one commit keep their date, as the branch view does');
        self::assertFalse($child->needsParentDates('10', 'v9.0.0'), 'a version the repository does not list');
        self::assertFalse($child->needsParentDates('10', 'not a version'));
        self::assertFalse($child->needsParentDates('10'), 'without the installed version only the branch decides');

        $parent = PackageMetadata::fromPackages('laravel/framework', [
            $this->load(self::tag('laravel/framework', 'v10.49.0', 'f10-49', '2024-11-21T00:00:00+00:00', ['illuminate/macroable' => 'self.version'])),
            $this->load(self::tag('laravel/framework', 'v10.48.28', 'f10-28', '2025-01-20T00:00:00+00:00')),
        ], new \DateTimeImmutable(self::NOW));
        $dated = $child->datedBy($parent);

        self::assertEquals(new \DateTimeImmutable('2025-01-20T00:00:00+00:00'), $dated->releaseDateOf('10.48.28.0'));
        self::assertFalse($dated->needsParentDates('10', 'v10.48.28'), 'once dated by the parent it needs nothing more');
    }

    /**
     * Built by hand rather than from packages, so the branch order and each branch's dates are
     * exactly what the case under test needs.
     *
     * @param array<array-key, array{version: string, at: ?\DateTimeImmutable, highest: array{normalized: string, pretty: string, at: ?\DateTimeImmutable, php: ?string}, dated_by?: string, php: ?string}> $byBranch
     * @param list<string>                                                                                                                                                      $replaces
     */
    private static function metadata(string $name, array $byBranch, array $replaces = [], ?string $lastStable = null): PackageMetadata
    {
        return new PackageMetadata($name, false, null, true, $lastStable === null ? null : new \DateTimeImmutable($lastStable), $lastStable === null ? null : 'x', \count($byBranch), null, 'library', new \DateTimeImmutable(self::NOW), $byBranch, $replaces);
    }

    /** @return array{version: string, at: ?\DateTimeImmutable, highest: array{normalized: string, pretty: string, at: ?\DateTimeImmutable, php: ?string}, php: ?string} */
    private static function branch(string $version, ?string $newestDated, ?string $highestAt): array
    {
        return [
            'version' => 'v'.$version,
            'at' => $newestDated === null ? null : new \DateTimeImmutable($newestDated),
            'highest' => ['normalized' => $version.'.0', 'pretty' => 'v'.$version, 'at' => $highestAt === null ? null : new \DateTimeImmutable($highestAt), 'php' => null],
            'php' => null,
        ];
    }

    public function testABranchTheParentCannotDateDoesNotEndTheWalk(): void
    {
        $child = self::metadata('illuminate/contracts', [
            '13' => self::branch('13.24.0', '2026-06-01T00:00:00+00:00', null),
            '8' => self::branch('8.83.27', '2022-01-13T00:00:00+00:00', null),
        ]);
        $parent = self::metadata('laravel/framework', [
            // 13.x undated in the parent too, so the walk reaches 8.x only by continuing.
            '13' => self::branch('13.24.0', '2026-09-01T00:00:00+00:00', null),
            '8' => self::branch('8.83.29', '2024-11-20T15:55:41+00:00', '2024-11-20T15:55:41+00:00'),
        ], ['illuminate/contracts']);

        $byBranch = $child->datedBy($parent)->latestStableByBranch();

        self::assertArrayNotHasKey('dated_by', $byBranch['13']);
        self::assertSame('laravel/framework', $byBranch['8']['dated_by'] ?? null);
        self::assertSame('v8.83.29', $byBranch['8']['version']);
    }

    /**
     * The package's own last release follows the parent only when the parent dated the branch that
     * release is on — the highest. A lower branch dated by the parent says nothing about the age of
     * the package as a whole, which is its highest tag's.
     */
    public function testALowerBranchDatedByTheParentLeavesThePackagesOwnAgeUnknown(): void
    {
        $child = self::metadata('illuminate/contracts', [
            '13' => self::branch('13.24.0', '2026-06-01T00:00:00+00:00', null),
            '8' => self::branch('8.83.27', '2022-01-13T00:00:00+00:00', null),
        ]);
        $parent = self::metadata('laravel/framework', [
            '13' => self::branch('13.24.0', '2026-09-01T00:00:00+00:00', null),
            '8' => self::branch('8.83.29', '2024-11-20T15:55:41+00:00', '2024-11-20T15:55:41+00:00'),
        ], ['illuminate/contracts']);

        $dated = $child->datedBy($parent);

        self::assertNull($dated->lastStableReleaseAt(), 'only 8.x was dated; 13.x carries the package\'s age');
        self::assertNull($dated->lastStableVersion());
        self::assertNull($dated->lastStableDatedBy());
    }

    public function testAPackageThatKnowsItsOwnAgeKeepsIt(): void
    {
        $child = self::metadata('illuminate/contracts', [
            '13' => self::branch('13.24.0', '2026-06-01T00:00:00+00:00', '2026-06-01T00:00:00+00:00'),
            '8' => self::branch('8.83.27', '2022-01-13T00:00:00+00:00', null),
        ], [], '2026-06-01T00:00:00+00:00');
        $parent = self::metadata('laravel/framework', [
            '13' => self::branch('13.24.0', '2026-09-01T00:00:00+00:00', '2026-09-01T00:00:00+00:00'),
            '8' => self::branch('8.83.29', '2024-11-20T15:55:41+00:00', '2024-11-20T15:55:41+00:00'),
        ], ['illuminate/contracts']);

        $dated = $child->datedBy($parent);

        self::assertEquals(new \DateTimeImmutable('2026-06-01T00:00:00+00:00'), $dated->lastStableReleaseAt(), 'its own, not the parent\'s 2026-09-01');
        self::assertSame('x', $dated->lastStableVersion());
        self::assertNull($dated->lastStableDatedBy());
        self::assertArrayNotHasKey('dated_by', $dated->latestStableByBranch()['13'], 'a dated branch is left alone');
        self::assertSame('laravel/framework', $dated->latestStableByBranch()['8']['dated_by'] ?? null);
    }

    /** A parent that dates only releases keeps the package's own reading of why its age is unknown. */
    public function testDatingByAParentKeepsWhetherTheLastStableVersionSharesItsCommit(): void
    {
        $now = new \DateTimeImmutable('2026-09-14T00:00:00+00:00');
        $child = new PackageMetadata('a/child', false, null, true, null, null, 3, null, 'library', $now, [], [], null, [], null, [], null, true);
        $parent = new PackageMetadata('a/parent', false, null, true, $now, '9.9.9', 1, null, 'library', $now, [], ['a/child'], null, ['9.9.9.0' => $now]);

        self::assertTrue($child->datedBy($parent)->lastStableSharesItsCommit());
    }

    /** debugbar-collector: 2.1.1 on its own commit, three 1.x tags on one commit dated after 2.1.1. */
    private function splitWithASharedLowerLine(string $sharedAt): PackageMetadata
    {
        return PackageMetadata::fromPackages('akaunting/laravel-debugbar-collector', [
            $this->load(self::tag('akaunting/laravel-debugbar-collector', '2.1.1', 'c2-own', '2023-06-02T07:22:01+00:00')),
            $this->load(self::tag('akaunting/laravel-debugbar-collector', '1.0.2', 'c1-shared', $sharedAt)),
            $this->load(self::tag('akaunting/laravel-debugbar-collector', '1.0.1', 'c1-shared', $sharedAt)),
            $this->load(self::tag('akaunting/laravel-debugbar-collector', '1.0.0', 'c1-shared', $sharedAt)),
        ], new \DateTimeImmutable(self::NOW));
    }

    /** A parent date resolves a shared-commit date that blocks the S2 reading. */
    public function testASharedLowerLineNewerThanTheReadingAsksTheParentAndTakesItsDate(): void
    {
        $child = $this->splitWithASharedLowerLine('2024-03-01T00:00:00+00:00');

        self::assertNull($child->releaseReadingAt(), 'an unresolved shared date newer than 2.1.1 keeps the reading unknown');
        self::assertNotNull($child->lastStableReleaseAt());
        self::assertTrue($child->needsParentDates('2', '2.1.1'));

        $parent = PackageMetadata::fromPackages('akaunting/monorepo', [
            $this->load(self::tag('akaunting/monorepo', '1.0.2', 'm1-02', '2026-09-01T00:00:00+00:00', ['akaunting/laravel-debugbar-collector' => 'self.version'])),
        ], new \DateTimeImmutable(self::NOW));
        $dated = $child->datedBy($parent);

        self::assertEquals(new \DateTimeImmutable('2026-09-01T00:00:00+00:00'), $dated->releaseReadingAt());
        self::assertSame('1.0.2', $dated->releaseReadingVersion());
        self::assertSame('akaunting/monorepo', $dated->releaseReadingDatedBy());
        self::assertSame('2.1.1', $dated->lastStableVersion(), 'the metadata keep the highest line');
        self::assertFalse($dated->needsParentDates('2', '2.1.1'));
    }

    public function testASharedLowerLineOlderThanTheReadingAsksNoParent(): void
    {
        $child = $this->splitWithASharedLowerLine('2022-01-11T10:26:31+00:00');

        self::assertEquals(new \DateTimeImmutable('2023-06-02T07:22:01+00:00'), $child->releaseReadingAt());
        self::assertFalse($child->needsParentDates('2', '2.1.1'));
    }

    /** The parent dates the undated highest tag, and a newer shared lower-line date still blocks S2. */
    public function testASharedLowerLineThatBlocksTheReadingAfterTheParentNamesTheSharedCommit(): void
    {
        $undated = self::tag('acme/split', 'v2.1.0', 'c21-own', '2000-01-01T00:00:00+00:00');
        unset($undated['time']);
        $child = PackageMetadata::fromPackages('acme/split', [
            $this->load($undated),
            $this->load(self::tag('acme/split', 'v2.0.0', 'c20-own', '2023-01-01T00:00:00+00:00')),
            $this->load(self::tag('acme/split', 'v1.9.2', 'c1-shared', '2026-01-01T00:00:00+00:00')),
            $this->load(self::tag('acme/split', 'v1.9.1', 'c1-shared', '2026-01-01T00:00:00+00:00')),
            $this->load(self::tag('acme/split', 'v1.9.0', 'c1-shared', '2026-01-01T00:00:00+00:00')),
        ], new \DateTimeImmutable(self::NOW));
        $parent = PackageMetadata::fromPackages('acme/monorepo', [
            $this->load(self::tag('acme/monorepo', 'v2.1.0', 'm21', '2025-01-01T00:00:00+00:00', ['acme/split' => 'self.version'])),
        ], new \DateTimeImmutable(self::NOW));

        $dated = $child->datedBy($parent);

        self::assertNull($dated->releaseReadingAt(), 'the shared 1.x date 2026-01-01 is newer than the parent date 2025-01-01');
        self::assertTrue($dated->lastStableSharesItsCommit(), 'S10 names the shared commit, not an undated tag');
    }

    /** SPEC §8.4.1: `age.release` reads the newest tag by date, pre-releases counted, also after a parent date. */
    public function testADatedPreReleaseNewerThanTheParentDatedLowerLineStaysTheReading(): void
    {
        $child = PackageMetadata::fromPackages('acme/split', [
            $this->load(self::tag('acme/split', 'v3.0.0-beta1', 'c3-beta', '2025-09-01T00:00:00+00:00')),
            $this->load(self::tag('acme/split', 'v2.0.0', 'c20-own', '2024-01-01T00:00:00+00:00')),
            $this->load(self::tag('acme/split', 'v1.9.2', 'c1-shared', '2026-01-01T00:00:00+00:00')),
            $this->load(self::tag('acme/split', 'v1.9.1', 'c1-shared', '2026-01-01T00:00:00+00:00')),
            $this->load(self::tag('acme/split', 'v1.9.0', 'c1-shared', '2026-01-01T00:00:00+00:00')),
        ], new \DateTimeImmutable(self::NOW));
        $parent = PackageMetadata::fromPackages('acme/monorepo', [
            $this->load(self::tag('acme/monorepo', 'v1.9.2', 'm192', '2025-06-01T00:00:00+00:00', ['acme/split' => 'self.version'])),
        ], new \DateTimeImmutable(self::NOW));

        $dated = $child->datedBy($parent);

        self::assertSame('v3.0.0-beta1', $dated->releaseReadingVersion(), 'the beta 2025-09-01 is newer than the parent\'s 1.x date 2025-06-01');
        self::assertEquals(new \DateTimeImmutable('2025-09-01T00:00:00+00:00'), $dated->releaseReadingAt());
        self::assertNull($dated->releaseReadingDatedBy());
    }

    public function testADatedPreReleaseStaysTheReadingWhenTheParentDatesTheUndatedHighestLine(): void
    {
        $undated = self::tag('acme/split', 'v2.1.0', 'c21-own', '2000-01-01T00:00:00+00:00');
        unset($undated['time']);
        $child = PackageMetadata::fromPackages('acme/split', [
            $this->load(self::tag('acme/split', 'v3.0.0-beta1', 'c3-beta', '2026-06-01T00:00:00+00:00')),
            $this->load($undated),
        ], new \DateTimeImmutable(self::NOW));
        $parent = PackageMetadata::fromPackages('acme/monorepo', [
            $this->load(self::tag('acme/monorepo', 'v2.1.0', 'm21', '2022-01-01T00:00:00+00:00', ['acme/split' => 'self.version'])),
        ], new \DateTimeImmutable(self::NOW));
        self::assertSame('v3.0.0-beta1', $child->releaseReadingVersion());

        $dated = $child->datedBy($parent);

        self::assertSame('v3.0.0-beta1', $dated->releaseReadingVersion(), 'the parent date 2022-01-01 of 2.x is older than the beta 2026-06-01');
        self::assertEquals(new \DateTimeImmutable('2026-06-01T00:00:00+00:00'), $dated->releaseReadingAt());
        self::assertSame('v2.1.0', $dated->lastStableVersion(), 'the metadata keep the highest stable line');
    }

    /**
     * @param list<array{0: string, 1: string, 2: ?string}> $tags version, commit, time (null: no time)
     */
    private function split(array $tags): PackageMetadata
    {
        $packages = [];
        foreach ($tags as [$version, $commit, $time]) {
            $config = self::tag('acme/split', $version, $commit, $time ?? '2000-01-01T00:00:00+00:00');
            if ($time === null) {
                unset($config['time']);
            }
            $packages[] = $this->load($config);
        }

        return PackageMetadata::fromPackages('acme/split', $packages, new \DateTimeImmutable(self::NOW));
    }

    /** A parent that replaces the split and dates only v2.0.0, so datedBy() reads the split's own rows. */
    private function parentOfV2(): PackageMetadata
    {
        return PackageMetadata::fromPackages('acme/monorepo', [
            $this->load(self::tag('acme/monorepo', 'v2.0.0', 'm20', '2024-06-01T00:00:00+00:00', ['acme/split' => 'self.version'])),
        ], new \DateTimeImmutable(self::NOW));
    }

    /** A parent that dates the split's undated v2.1.0, so datedBy() replaces the split's own reading. */
    private function parentOfUndatedV21(string $at): PackageMetadata
    {
        return PackageMetadata::fromPackages('acme/monorepo', [
            $this->load(self::tag('acme/monorepo', 'v2.1.0', 'm21', $at, ['acme/split' => 'self.version'])),
        ], new \DateTimeImmutable(self::NOW));
    }

    /** @return iterable<string, array{list<array{0: string, 1: string, 2: ?string}>}> */
    public static function providePreReleasesThatShareTheNewestDate(): iterable
    {
        $b1 = ['v3.0.0-beta1', 'c-b1', '2025-09-01T00:00:00+00:00'];
        $b2 = ['v3.0.0-beta2', 'c-b2', '2025-10-01T00:00:00+00:00'];
        $b3 = ['v3.0.0-beta3', 'c-b3', '2025-10-01T00:00:00+00:00'];
        $v21 = ['v2.1.0', 'c21', null];

        yield 'beta2 listed before beta3' => [[$b1, $b2, $b3, $v21]];
        yield 'beta3 listed before beta2' => [[$b1, $b3, $b2, $v21]];
    }

    /**
     * @dataProvider providePreReleasesThatShareTheNewestDate
     *
     * @param list<array{0: string, 1: string, 2: ?string}> $tags
     */
    #[DataProvider('providePreReleasesThatShareTheNewestDate')]
    public function testTheNewestPreReleaseIsTheNewestByDateAndThenByVersionWhateverTheListingOrder(array $tags): void
    {
        $dated = $this->split($tags)->datedBy($this->parentOfUndatedV21('2024-01-01T00:00:00+00:00'));

        self::assertSame('v3.0.0-beta3', $dated->releaseReadingVersion(), 'beta3 and beta2 share the newest date, and beta3 is the higher version');
    }

    public function testAnUndatedHighestPreReleaseKeepsTheReadingUnknownAfterAParentDatesALowerLine(): void
    {
        $child = $this->split([
            ['v3.0.0-beta1', 'c-b1', null],
            ['v2.0.0', 'c20', '2020-01-01T00:00:00+00:00'],
            ['v1.0.0', 'c10', null],
        ]);
        $parent = PackageMetadata::fromPackages('acme/monorepo', [
            $this->load(self::tag('acme/monorepo', 'v1.0.0', 'm10', '2019-01-01T00:00:00+00:00', ['acme/split' => 'self.version'])),
        ], new \DateTimeImmutable(self::NOW));

        $dated = $child->datedBy($parent);

        self::assertNull($child->releaseReadingAt(), 'the highest tag v3.0.0-beta1 has no time');
        self::assertNull($dated->releaseReadingAt(), 'the parent dates only v1.0.0, so the undated v3.0.0-beta1 keeps the age unknown');
    }

    public function testAPreReleaseOnTheParentDatesDayWinsByVersion(): void
    {
        $child = $this->split([
            ['v3.0.0-beta1', 'c-b1', '2024-01-01T00:00:00+00:00'],
            ['v2.1.0', 'c21', null],
        ]);

        $dated = $child->datedBy($this->parentOfUndatedV21('2024-01-01T00:00:00+00:00'));

        self::assertSame('v3.0.0-beta1', $dated->releaseReadingVersion());
    }

    public function testAPreReleaseWhosePrettyVersionDoesNotParseWinsOnlyByALaterDate(): void
    {
        $preRelease = self::tag('acme/split', 'release-3-beta', 'c-b1', '2024-01-01T00:00:00+00:00');
        $preRelease['version_normalized'] = '3.0.0.0-beta1';
        $undated = self::tag('acme/split', 'v2.1.0', 'c21', '2000-01-01T00:00:00+00:00');
        unset($undated['time']);
        $child = PackageMetadata::fromPackages('acme/split', [$this->load($preRelease), $this->load($undated)], new \DateTimeImmutable(self::NOW));

        $dated = $child->datedBy($this->parentOfUndatedV21('2024-01-01T00:00:00+00:00'));

        self::assertSame('v2.1.0', $dated->releaseReadingVersion(), 'release-3-beta does not parse, and its date equals the parent date of v2.1.0');
    }

    public function testAnUndatedMiddleLineDoesNotEndTheWalkOverTheRows(): void
    {
        $child = $this->split([
            ['v2.1.0', 'c21', null],
            ['v1.0.0', 'c10', null],
            ['v0.9.0', 'c09', '2025-01-01T00:00:00+00:00'],
        ]);

        $dated = $child->datedBy($this->parentOfUndatedV21('2024-01-01T00:00:00+00:00'));

        self::assertSame('v0.9.0', $dated->releaseReadingVersion(), 'v0.9.0 on 2025-01-01 is newer than the parent date of v2.1.0');
    }

    public function testAnUndatedHighestTagKeepsItsOwnReadingBesideANewerLowerLine(): void
    {
        $child = $this->split([
            ['v2.1.0', 'c21', null],
            ['v2.0.0', 'c20', '2023-01-01T00:00:00+00:00'],
            ['v1.5.0', 'c15', '2025-01-01T00:00:00+00:00'],
        ]);

        self::assertSame('v2.1.0', $child->lastStableVersion());
        self::assertNull($child->lastStableReleaseAt(), 'the highest tag has no date, so the package\'s age is unknown');
    }

    public function testAHighestLineWhoseNewestDateIsSharedLeavesTheLastReleaseUndated(): void
    {
        $child = $this->split([
            ['v2.2.0', 'c22', '2024-01-01T00:00:00+00:00'],
            ['v2.1.2', 'c21-shared', '2025-01-01T00:00:00+00:00'],
            ['v2.1.1', 'c21-shared', '2025-01-01T00:00:00+00:00'],
            ['v2.1.0', 'c21-shared', '2025-01-01T00:00:00+00:00'],
            ['v1.5.0', 'c15', '2026-01-01T00:00:00+00:00'],
        ]);

        self::assertSame('v2.1.2', $child->lastStableVersion(), 'the highest line\'s newest dated tag');
        self::assertNull($child->lastStableReleaseAt(), 'that tag shares its commit, so its date is no release date');
    }

    public function testAnUndatedLowerLineLeavesTheReadingAsItIs(): void
    {
        $child = $this->split([
            ['v2.0.0', 'c20', '2024-01-01T00:00:00+00:00'],
            ['v1.0.0', 'c10', null],
        ]);

        $dated = $child->datedBy($this->parentOfV2());

        self::assertSame('v2.0.0', $dated->releaseReadingVersion());
        self::assertEquals(new \DateTimeImmutable('2024-01-01T00:00:00+00:00'), $dated->releaseReadingAt());
    }

    /** @return iterable<string, array{list<array{0: string, 1: string, 2: ?string}>}> */
    public static function provideSharedLowerLinesWithOneNewerThanTheReading(): iterable
    {
        $shared = static fn (string $line, string $commit, string $at): array => [["v$line.2", $commit, $at], ["v$line.1", $commit, $at], ["v$line.0", $commit, $at]];

        yield 'the newer shared line first' => [array_merge([['v2.0.0', 'c20', '2024-06-01T00:00:00+00:00']], $shared('1.9', 'c19', '2025-01-01T00:00:00+00:00'), $shared('0.9', 'c09', '2023-01-01T00:00:00+00:00'))];
        yield 'the older shared line first' => [array_merge([['v2.0.0', 'c20', '2024-06-01T00:00:00+00:00']], $shared('1.9', 'c19', '2023-01-01T00:00:00+00:00'), $shared('0.9', 'c09', '2025-01-01T00:00:00+00:00'))];
    }

    /**
     * @dataProvider provideSharedLowerLinesWithOneNewerThanTheReading
     *
     * @param list<array{0: string, 1: string, 2: ?string}> $tags
     */
    #[DataProvider('provideSharedLowerLinesWithOneNewerThanTheReading')]
    public function testTheNewestUnresolvedSharedDateBlocksTheReadingWhateverTheLineOrder(array $tags): void
    {
        $dated = $this->split($tags)->datedBy($this->parentOfV2());

        self::assertNull($dated->releaseReadingAt(), 'one shared line is dated 2025-01-01, after v2.0.0 on 2024-06-01');
    }

    public function testASharedLowerDateOnTheReadingsDateDoesNotBlockIt(): void
    {
        $child = $this->split([
            ['v2.0.0', 'c20', '2024-06-01T00:00:00+00:00'],
            ['v1.9.2', 'c19', '2024-06-01T00:00:00+00:00'],
            ['v1.9.1', 'c19', '2024-06-01T00:00:00+00:00'],
            ['v1.9.0', 'c19', '2024-06-01T00:00:00+00:00'],
        ]);

        $dated = $child->datedBy($this->parentOfV2());

        self::assertSame('v2.0.0', $dated->releaseReadingVersion());
        self::assertEquals(new \DateTimeImmutable('2024-06-01T00:00:00+00:00'), $dated->releaseReadingAt());
    }
}
