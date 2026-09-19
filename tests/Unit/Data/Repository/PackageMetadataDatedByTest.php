<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Repository;

use Composer\Package\CompletePackage;
use Composer\Package\Loader\ArrayLoader;
use Lockrot\Data\Repository\PackageMetadata;
use PHPUnit\Framework\TestCase;

/**
 * A split package dated by its monorepo parent: illuminate/contracts by laravel/framework. The
 * child's tags pile up on one commit and are handed over undated by fromPackages(); the parent's
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

    public function testAParentThatDatesNothingReturnsTheSameObject(): void
    {
        $child = $this->child();
        $parent = PackageMetadata::fromPackages('laravel/framework', [
            $this->load(self::tag('laravel/framework', 'v5.8.0', 'f5', '2019-01-01T00:00:00+00:00', ['illuminate/contracts' => 'self.version'])),
        ], new \DateTimeImmutable(self::NOW));

        self::assertSame($child, $child->datedBy($parent), 'no branch in common: nothing to date');
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

    /**
     * Built by hand rather than from packages, so the branch order and each branch's dates are
     * exactly what the case under test needs.
     *
     * @param array<array-key, array{version: string, at: ?\DateTimeImmutable, highest: array{normalized: string, pretty: string, at: ?\DateTimeImmutable}, dated_by?: string}> $byBranch
     * @param list<string>                                                                                                                                                      $replaces
     */
    private static function metadata(string $name, array $byBranch, array $replaces = [], ?string $lastStable = null): PackageMetadata
    {
        return new PackageMetadata($name, false, null, true, $lastStable === null ? null : new \DateTimeImmutable($lastStable), $lastStable === null ? null : 'x', \count($byBranch), null, 'library', new \DateTimeImmutable(self::NOW), $byBranch, $replaces);
    }

    /** @return array{version: string, at: ?\DateTimeImmutable, highest: array{normalized: string, pretty: string, at: ?\DateTimeImmutable}} */
    private static function branch(string $version, ?string $newestDated, ?string $highestAt): array
    {
        return [
            'version' => 'v'.$version,
            'at' => $newestDated === null ? null : new \DateTimeImmutable($newestDated),
            'highest' => ['normalized' => $version.'.0', 'pretty' => 'v'.$version, 'at' => $highestAt === null ? null : new \DateTimeImmutable($highestAt)],
        ];
    }

    /** A branch the parent cannot date does not stop the ones below it from being dated. */
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

    /** A package that dates its own highest tag keeps its own age, whatever the parent dated below. */
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
}
