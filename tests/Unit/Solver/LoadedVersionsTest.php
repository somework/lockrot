<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Solver;

use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Package\CompletePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Repository\ArrayRepository;
use Composer\Semver\VersionParser;
use Lockrot\Solver\LoadedVersions;
use Lockrot\Solver\VersionSource;
use Lockrot\Tests\Support\ArrayVersionSource;
use PHPUnit\Framework\TestCase;

final class LoadedVersionsTest extends TestCase
{
    public function testEachNameThatThePoolBuilderAsksIsLoadedOnceAndAnAskedNameStopsNoOtherName(): void
    {
        $source = new ArrayVersionSource(['acme/a' => [['version' => '1.0.0']], 'acme/b' => [['version' => '2.0.0']]]);
        $repository = new LoadedVersions($source);

        $repository->loadPackages(['acme/a' => null], ['stable' => BasePackage::STABILITY_STABLE], []);
        $loaded = $repository->loadPackages(['acme/a' => null, 'acme/b' => null], ['stable' => BasePackage::STABILITY_STABLE], []);

        self::assertSame(['acme/a 1.0.0', 'acme/b 2.0.0'], array_map(static fn (BasePackage $package): string => $package->getName().' '.$package->getPrettyVersion(), array_values($loaded['packages'])));
        self::assertSame(['acme/a 1.0.0', 'acme/b 2.0.0'], array_map(static fn (BasePackage $package): string => $package->getName().' '.$package->getPrettyVersion(), $repository->getPackages()));
    }

    public function testALaterAskAnswersOnlyTheNamesThatItAsks(): void
    {
        $repository = new LoadedVersions(new ArrayVersionSource(['acme/a' => [['version' => '1.0.0']], 'acme/b' => [['version' => '2.0.0']]]));

        $repository->loadPackages(['acme/a' => null], ['stable' => BasePackage::STABILITY_STABLE], []);
        $loaded = $repository->loadPackages(['acme/b' => null], ['stable' => BasePackage::STABILITY_STABLE], []);

        self::assertSame(['acme/b'], $loaded['namesFound']);
        self::assertSame(['acme/b 2.0.0'], array_map(static fn (BasePackage $package): string => $package->getName().' '.$package->getPrettyVersion(), array_values($loaded['packages'])));
    }

    /**
     * @dataProvider asks
     *
     * @param array<string, ?string>                                              $asked         name => constraint
     * @param array<'stable'|'RC'|'beta'|'alpha'|'dev', 0|5|10|15|20>             $stabilities
     * @param array<string, 0|5|10|15|20>                                         $flags
     * @param array<string, array<string, \Composer\Package\PackageInterface>> $alreadyLoaded
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('asks')]
    public function testTheAnswerIsThatOfAnArrayRepositoryOfTheSameVersions(array $asked, array $stabilities, array $flags, array $alreadyLoaded): void
    {
        $served = [
            'acme/a' => self::served([
                ['name' => 'acme/a', 'version' => '1.0.0'],
                ['name' => 'acme/a', 'version' => '1.1.0'],
                ['name' => 'acme/a', 'version' => '2.0.0-beta1'],
                ['name' => 'acme/a', 'version' => 'dev-main', 'extra' => ['branch-alias' => ['dev-main' => '2.x-dev']]],
            ]),
            'acme/b' => self::served([['name' => 'acme/b', 'version' => '1.0.0'], ['name' => 'acme/b', 'version' => 'dev-next', 'extra' => ['branch-alias' => ['dev-next' => '1.x-dev']]]]),
        ];
        $source = new class ($served) implements VersionSource {
            /** @var array<string, list<BasePackage>> */
            private array $served;

            /** @param array<string, list<BasePackage>> $served */
            public function __construct(array $served)
            {
                $this->served = $served;
            }

            public function versions(string $name): array
            {
                return $this->served[$name] ?? [];
            }

            public function versionsOf(array $names): array
            {
                $versions = [];
                foreach ($names as $name) {
                    $versions[$name] = $this->versions($name);
                }

                return $versions;
            }

            public function failure(string $name): ?string
            {
                return null;
            }
        };
        $map = [];
        $inLoadOrder = [];
        foreach ($asked as $name => $constraint) {
            $map[$name] = $constraint === null ? null : (new VersionParser())->parseConstraints($constraint);
            array_push($inLoadOrder, ...($served[$name] ?? []));
        }

        $expected = (new ArrayRepository($inLoadOrder))->loadPackages($map, $stabilities, $flags, $alreadyLoaded);
        $actual = (new LoadedVersions($source))->loadPackages($map, $stabilities, $flags, $alreadyLoaded);

        // The keys of `packages` are object ids or object hashes, by Composer version: the pool builder reads the values.
        self::assertSame($expected['namesFound'], $actual['namesFound']);
        self::assertSame(array_values($expected['packages']), array_values($actual['packages']));
    }

    /** @return iterable<string, array{array<string, ?string>, array<'stable'|'RC'|'beta'|'alpha'|'dev', 0|5|10|15|20>, array<string, 0|5|10|15|20>, array<string, array<string, \Composer\Package\PackageInterface>>}> */
    public static function asks(): iterable
    {
        $stable = ['stable' => BasePackage::STABILITY_STABLE];
        $all = ['stable' => BasePackage::STABILITY_STABLE, 'RC' => BasePackage::STABILITY_RC, 'beta' => BasePackage::STABILITY_BETA, 'alpha' => BasePackage::STABILITY_ALPHA, 'dev' => BasePackage::STABILITY_DEV];

        yield 'a constraint on one name and none on another' => [['acme/b' => null, 'acme/a' => '^1.0'], $stable, [], []];
        yield 'every stability' => [['acme/a' => null, 'acme/b' => null], $all, [], []];
        yield 'a stability flag of one name' => [['acme/a' => null, 'acme/b' => null], $stable, ['acme/a' => BasePackage::STABILITY_BETA], []];
        yield 'a version already loaded' => [['acme/a' => '^1.0'], $stable, [], ['acme/a' => ['1.1.0.0' => new CompletePackage('acme/a', '1.1.0.0', '1.1.0')]]];
        yield 'a constraint that only the alias of a branch meets' => [['acme/a' => '^2.0@dev'], $all, [], []];
        yield 'a constraint that the branch meets and its alias does not' => [['acme/b' => 'dev-next'], $all, [], []];
        yield 'a name that the source does not serve' => [['acme/none' => null, 'acme/a' => '1.0.0'], $all, [], []];
    }

    /**
     * A Composer repository serves a branch and then its alias, and ArrayLoader gives the alias only.
     *
     * @param list<array<string, mixed>> $versions
     *
     * @return list<BasePackage>
     */
    private static function served(array $versions): array
    {
        $packages = [];
        foreach ((new ArrayLoader())->loadPackages($versions) as $package) {
            if ($package instanceof AliasPackage) {
                $packages[] = $package->getAliasOf();
            }
            $packages[] = $package;
        }

        return $packages;
    }
}
