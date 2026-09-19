<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Repository;

use Lockrot\Data\Repository\MonorepoParents;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Exception\ConfigException;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use PHPUnit\Framework\TestCase;

final class MonorepoParentsTest extends TestCase
{
    private const NOW = '2026-09-14T00:00:00+00:00';

    /**
     * @param array<array-key, array{version: string, at: ?\DateTimeImmutable, highest: array{normalized: string, pretty: string, at: ?\DateTimeImmutable}}> $byBranch
     * @param list<string>                                                                                                                                  $replaces
     */
    private static function metadata(string $name, array $byBranch, array $replaces = [], ?string $lastStable = null): PackageMetadata
    {
        return new PackageMetadata($name, false, null, true, $lastStable === null ? null : new \DateTimeImmutable($lastStable), $lastStable === null ? null : 'x', \count($byBranch), null, 'library', new \DateTimeImmutable(self::NOW), $byBranch, $replaces);
    }

    /** @return array{version: string, at: ?\DateTimeImmutable, highest: array{normalized: string, pretty: string, at: ?\DateTimeImmutable}} */
    private static function branch(string $version, ?string $at): array
    {
        $date = $at === null ? null : new \DateTimeImmutable($at);

        return ['version' => $version, 'at' => $date, 'highest' => ['normalized' => $version.'.0', 'pretty' => 'v'.$version, 'at' => $date]];
    }

    public function testTheBundledFileLoadsAndOnlyTheMonorepoThatCarriesTheChildIsWorthARequest(): void
    {
        $parents = MonorepoParents::load();
        $metadata = ['illuminate/contracts' => self::metadata('illuminate/contracts', ['8' => self::branch('8.83.27', null)])];
        $children = $parents->children([F::package(['name' => 'illuminate/contracts', 'version' => 'v8.83.27'])], $metadata);

        self::assertSame(['laravel/framework', 'symfony/symfony', 'cakephp/cakephp'], $parents->candidates());
        self::assertSame(['illuminate/contracts'], $children);
        self::assertSame(['laravel/framework'], $parents->missingCandidates($children, $metadata), 'symfony and cakephp carry no illuminate/*');
    }

    /**
     * symfony/polyfill-* is cut by a monorepo too — four tags on the commit behind v1.37.0 — so it
     * reads as undated and lands in children(). No Packagist package replaces it, and it must
     * therefore cost no request: it is in almost every lock there is.
     */
    public function testAPackageNoListedMonorepoCarriesCostsNoRequest(): void
    {
        $parents = MonorepoParents::load();
        $metadata = ['symfony/polyfill-ctype' => self::metadata('symfony/polyfill-ctype', ['1' => self::branch('1.37.0', null)])];
        $children = $parents->children([F::package(['name' => 'symfony/polyfill-ctype', 'version' => 'v1.37.0'])], $metadata);

        self::assertSame(['symfony/polyfill-ctype'], $children);
        self::assertSame([], $parents->missingCandidates($children, $metadata));
    }

    public function testAMalformedFileIsAConfigurationError(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'lockrot-parents-');
        self::assertIsString($path);
        try {
            // Every message opens with the file it is about: a list of parents is shipped in the
            // package and configured by nobody, so the path is the only thing that makes the error
            // actionable.
            $rejected = static function (string $json) use ($path): string {
                file_put_contents($path, $json);
                try {
                    MonorepoParents::load($path);
                } catch (ConfigException $e) {
                    return $e->getMessage();
                }
                self::fail('accepted '.$json);
            };

            self::assertSame($path.' must carry a "parents" map', $rejected('{"parents": "laravel/framework"}'));
            self::assertSame($path.' must carry a "parents" map', $rejected('{}'));
            $maps = $path.': "parents" maps a package name to the list of packages it replaces';
            self::assertSame($maps, $rejected('{"parents": {"laravel/framework": "illuminate/contracts"}}'));
            self::assertSame($maps, $rejected('{"parents": {"": ["illuminate/contracts"]}}'), 'an empty parent name');
            self::assertSame($maps, $rejected('{"parents": {"7": ["illuminate/contracts"]}}'), 'a numeric key decodes as an int');
            self::assertSame($path.': every package laravel/framework replaces must be a package name', $rejected('{"parents": {"laravel/framework": [7]}}'));
            self::assertSame($path.': every package laravel/framework replaces must be a package name', $rejected('{"parents": {"laravel/framework": [""]}}'));
        } finally {
            unlink($path);
        }
    }

    /** The bundled snapshot is what the parents really replace; bin/refresh-monorepo-parents rewrites it. */
    public function testTheBundledSnapshotCarriesTheComponentsEachMonorepoIsKnownFor(): void
    {
        $parents = MonorepoParents::load();
        $metadata = [];
        $packages = [];
        foreach (['illuminate/contracts' => 'laravel/framework', 'symfony/yaml' => 'symfony/symfony', 'cakephp/orm' => 'cakephp/cakephp'] as $child => $parent) {
            $metadata = [$child => self::metadata($child, ['1' => self::branch('1.0.0', null)])];
            $packages = [F::package(['name' => $child, 'version' => '1.0.0'])];

            self::assertSame([$parent], $parents->missingCandidates($parents->children($packages, $metadata), $metadata), $child);
        }
    }

    public function testChildrenAreThePackagesWhoseInstalledBranchOrOwnAgeIsUndated(): void
    {
        $parents = MonorepoParents::none();
        $packages = [
            F::package(['name' => 'illuminate/contracts', 'version' => 'v8.83.27']),
            F::package(['name' => 'illuminate/support', 'version' => 'v10.48.28']),
            F::package(['name' => 'vendor/own', 'version' => '1.0.0']),
            F::package(['name' => 'vendor/unknown', 'version' => '1.0.0']),
        ];
        $metadata = [
            'illuminate/contracts' => self::metadata('illuminate/contracts', ['8' => self::branch('8.83.27', null), '13' => self::branch('13.0.0', '2026-01-01')], [], '2026-01-01'),
            'illuminate/support' => self::metadata('illuminate/support', ['10' => self::branch('10.48.28', '2025-01-01'), '5' => self::branch('5.8.0', null)], [], '2025-01-01'),
            'vendor/own' => self::metadata('vendor/own', ['1' => self::branch('1.0.0', '2026-01-01')], [], '2026-01-01'),
        ];

        self::assertSame(['illuminate/contracts'], $parents->children($packages, $metadata), 'an undated *installed* branch; support\'s undated 5.x decides nothing, and a package without metadata has nothing to date');
        self::assertSame([], $parents->missingCandidates([], $metadata), 'no children, nothing to fetch');
    }

    public function testAParentAlreadyInTheBatchDatesItsChildAndNothingIsMissing(): void
    {
        $parents = new MonorepoParents(['laravel/framework' => ['illuminate/contracts']]);
        $metadata = [
            'illuminate/contracts' => self::metadata('illuminate/contracts', ['8' => self::branch('8.83.27', null)]),
            'laravel/framework' => self::metadata('laravel/framework', ['8' => self::branch('8.83.29', '2024-11-20')], ['illuminate/contracts'], '2024-11-20'),
        ];

        self::assertSame([], $parents->missingCandidates(['illuminate/contracts'], $metadata));
        $dated = $parents->date(['illuminate/contracts'], $metadata);

        self::assertSame('laravel/framework', $dated['illuminate/contracts']->latestStableByBranch()['8']['dated_by'] ?? null);
        self::assertSame($metadata['laravel/framework'], $dated['laravel/framework'], 'the parent itself is untouched');
    }

    public function testAListedParentNotInTheBatchIsMissingAndDatesOnceLoaded(): void
    {
        $parents = new MonorepoParents(['laravel/framework' => ['illuminate/contracts'], 'symfony/symfony' => ['illuminate/contracts', 'symfony/yaml']]);
        $metadata = ['illuminate/contracts' => self::metadata('illuminate/contracts', ['8' => self::branch('8.83.27', null)])];

        self::assertSame(['laravel/framework', 'symfony/symfony'], $parents->missingCandidates(['illuminate/contracts'], $metadata), 'which one replaces the child is only known once loaded');
        self::assertSame($metadata, $parents->date(['illuminate/contracts'], $metadata), 'without a parent nothing changes');

        $metadata['laravel/framework'] = self::metadata('laravel/framework', ['8' => self::branch('8.83.29', '2024-11-20')], ['illuminate/contracts'], '2024-11-20');
        $metadata['symfony/symfony'] = self::metadata('symfony/symfony', ['8' => self::branch('8.1.0', '2026-01-01')], ['symfony/yaml'], '2026-01-01');
        $dated = $parents->date(['illuminate/contracts'], $metadata);

        self::assertSame('laravel/framework', $dated['illuminate/contracts']->latestStableByBranch()['8']['dated_by'] ?? null, 'the parent whose replace list names the child, not the one that happens to have an 8.x');
    }

    public function testOnlyTheReplaceListMakesAParent(): void
    {
        $parents = new MonorepoParents(['symfony/symfony' => ['symfony/yaml']]);
        $metadata = [
            'symfony/polyfill-mbstring' => self::metadata('symfony/polyfill-mbstring', ['1' => self::branch('1.31.0', null)]),
            'symfony/symfony' => self::metadata('symfony/symfony', ['1' => self::branch('1.0.0', '2010-01-01')], ['symfony/yaml'], '2026-01-01'),
        ];

        $dated = $parents->date(['symfony/polyfill-mbstring'], $metadata);

        self::assertSame($metadata['symfony/polyfill-mbstring'], $dated['symfony/polyfill-mbstring'], 'a vendor prefix is not a parent');
    }

    public function testEveryPackageThatNeedsDatesIsListed(): void
    {
        $parents = MonorepoParents::none();
        $undated = ['8' => self::branch('8.0.0', null)];
        $packages = [
            F::package(['name' => 'illuminate/contracts', 'version' => 'v8.0.0']),
            F::package(['name' => 'illuminate/support', 'version' => 'v8.0.0']),
        ];
        $metadata = [
            'illuminate/contracts' => self::metadata('illuminate/contracts', $undated),
            'illuminate/support' => self::metadata('illuminate/support', $undated),
        ];

        self::assertSame(['illuminate/contracts', 'illuminate/support'], $parents->children($packages, $metadata));
    }

    /**
     * The batch is scanned for a package whose `replace` names the child, not for the first package
     * that is not the child: a tool listed before the monorepo must not be taken for the parent,
     * which would leave the child undated.
     */
    public function testAPackageThatDoesNotReplaceTheChildIsSteppedOverEvenWhenItComesFirst(): void
    {
        $parents = MonorepoParents::none();
        $metadata = [
            'acme/tool' => self::metadata('acme/tool', ['1' => self::branch('1.0.0', '2026-01-01')], ['acme/other'], '2026-01-01'),
            'illuminate/contracts' => self::metadata('illuminate/contracts', ['8' => self::branch('8.83.27', null)]),
            'laravel/framework' => self::metadata('laravel/framework', ['8' => self::branch('8.83.29', '2024-11-20')], ['illuminate/contracts'], '2024-11-20'),
        ];

        $dated = $parents->date(['illuminate/contracts'], $metadata);

        self::assertSame('laravel/framework', $dated['illuminate/contracts']->latestStableByBranch()['8']['dated_by'] ?? null);
    }
}
