<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Graph;

use Lockrot\Graph\DependencyGraph;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use PHPUnit\Framework\TestCase;

final class DependencyGraphTest extends TestCase
{
    private function graph(bool $dev): DependencyGraph
    {
        return DependencyGraph::fromLock(
            LockFile::fromFile(__DIR__.'/../../fixtures/mini/composer.lock'),
            ProjectConfig::fromFile(__DIR__.'/../../fixtures/mini/composer.json'),
            $dev
        );
    }

    public function testDirectPackageChainIsItself(): void
    {
        self::assertSame(['vendor/direct'], $this->graph(false)->shortestChain('vendor/direct'));
    }

    public function testATransitivePackageIsReachedThroughItsRoot(): void
    {
        self::assertSame(['vendor/direct', 'vendor/transitive'], $this->graph(false)->shortestChain('vendor/transitive'));
    }

    public function testUnreachableIsEmpty(): void
    {
        self::assertSame([], $this->graph(false)->shortestChain('vendor/snapshot'));
    }

    public function testADevRootCountsOnlyWhenDevIsIncluded(): void
    {
        self::assertSame([], $this->graph(false)->shortestChain('vendor/devtool'));
        self::assertSame(['vendor/devtool'], $this->graph(true)->shortestChain('vendor/devtool'));
    }

    public function testAChainOverWallabagEndsWithThePackage(): void
    {
        $graph = $this->wallabag();
        $chain = $graph->shortestChain('phpzip/phpzip');
        self::assertNotSame([], $chain);
        self::assertSame('phpzip/phpzip', end($chain));
    }

    public function testChainsToDirectPackageIsItself(): void
    {
        self::assertSame(['vendor/direct' => ['vendor/direct']], $this->graph(false)->chainsTo('vendor/direct'));
    }

    public function testChainsToTransitiveNamesEveryRootThatReachesIt(): void
    {
        self::assertSame(
            ['vendor/direct' => ['vendor/direct', 'vendor/transitive']],
            $this->graph(false)->chainsTo('vendor/transitive')
        );
        // With dev roots in, the dev tool reaches the same package through the direct one — two
        // roots, sorted by name, each with its own chain.
        self::assertSame(
            [
                'vendor/devtool' => ['vendor/devtool', 'vendor/direct', 'vendor/transitive'],
                'vendor/direct' => ['vendor/direct', 'vendor/transitive'],
            ],
            $this->graph(true)->chainsTo('vendor/transitive')
        );
    }

    public function testChainsToDirectPackageAlsoReachedThroughAnotherRoot(): void
    {
        self::assertSame(
            ['vendor/devtool' => ['vendor/devtool', 'vendor/direct'], 'vendor/direct' => ['vendor/direct']],
            $this->graph(true)->chainsTo('vendor/direct')
        );
    }

    public function testChainsToUnreachableIsEmpty(): void
    {
        self::assertSame([], $this->graph(false)->chainsTo('vendor/snapshot'));
        self::assertSame([], $this->graph(false)->chainsTo('vendor/not-in-lock'));
    }

    public function testChainsToIsStableAcrossCalls(): void
    {
        $graph = $this->graph(true);
        self::assertSame($graph->chainsTo('vendor/transitive'), $graph->chainsTo('vendor/transitive'));
        self::assertSame(['vendor/direct', 'vendor/transitive'], $graph->shortestChain('vendor/transitive'));
    }

    public function testWallabagChainsToAgreeWithShortestChain(): void
    {
        $graph = $this->wallabag();
        foreach (['phpzip/phpzip', 'hoa/ruler', 'hoa/event', 'symfony/security-guard'] as $target) {
            $shortest = $graph->shortestChain($target);
            $chains = $graph->chainsTo($target);
            self::assertArrayHasKey($shortest[0], $chains, $target);
            self::assertCount(\count($shortest), $chains[$shortest[0]], $target);
            foreach ($chains as $root => $chain) {
                self::assertSame($root, $chain[0], $target);
                self::assertSame($target, end($chain), $target);
                self::assertGreaterThanOrEqual(\count($shortest), \count($chain), $target);
            }
        }
        // hoa/ruler is reached from two root requires: the shortest chain starts at wallabag/rulerz,
        // and wallabag/rulerz-bundle reaches it too — the second parent is what chainsTo() adds.
        self::assertSame(['wallabag/rulerz', 'wallabag/rulerz-bundle'], array_keys($graph->chainsTo('hoa/ruler')));
    }

    private function wallabag(): DependencyGraph
    {
        return DependencyGraph::fromLock(
            LockFile::fromFile(__DIR__.'/../../fixtures/apps/wallabag_wallabag/composer.lock'),
            ProjectConfig::fromFile(__DIR__.'/../../fixtures/apps/wallabag_wallabag/composer.json'),
            false
        );
    }

    /**
     * composer.json requires it and composer.lock does not carry it — a lock that is out of date
     * against the manifest. It is a root of the graph with no edges of its own, so no chain leads
     * to it and it is not a chain of length one either.
     */
    public function testAPackageRequiredButAbsentFromTheLockHasNoChain(): void
    {
        $lock = LockFile::fromArray(['packages' => [
            ['name' => 'vendor/present', 'version' => '1.0.0'],
        ]]);
        $graph = DependencyGraph::fromLock(
            $lock,
            ProjectConfig::fromArray(['require' => ['vendor/present' => '^1', 'vendor/ghost' => '^1']]),
            false
        );

        self::assertSame([], $graph->shortestChain('vendor/ghost'));
        self::assertSame([], $graph->chainsTo('vendor/ghost'));
        self::assertSame(['vendor/present'], $graph->shortestChain('vendor/present'), 'the root that is in the lock still works');
    }

    public function testChainsToTerminatesOnCyclesAndIgnoresASelfRequire(): void
    {
        $lock = LockFile::fromArray(['packages' => [
            ['name' => 'root/a', 'version' => '1.0.0', 'require' => ['vendor/x' => '^1']],
            ['name' => 'vendor/x', 'version' => '1.0.0', 'require' => ['vendor/y' => '^1', 'vendor/x' => '^1']],
            ['name' => 'vendor/y', 'version' => '1.0.0', 'require' => ['vendor/x' => '^1', 'root/a' => '^1']],
        ]]);
        $graph = DependencyGraph::fromLock($lock, ProjectConfig::fromArray(['require' => ['root/a' => '^1']]), false);

        self::assertSame(['root/a' => ['root/a', 'vendor/x', 'vendor/y']], $graph->chainsTo('vendor/y'));
        self::assertSame(['root/a' => ['root/a', 'vendor/x']], $graph->chainsTo('vendor/x'));
        self::assertSame(['root/a' => ['root/a']], $graph->chainsTo('root/a'));
    }

    /** new-sylius locks symfony/contracts, which replaces every symfony/*-contracts, and none of them. */
    public function testAPackageThatReplacesARequiredNameIsReachedThroughIt(): void
    {
        $lock = LockFile::fromArray(['packages' => [
            ['name' => 'root/app', 'version' => '1.0.0', 'require' => ['symfony/service-contracts' => '^3.0']],
            ['name' => 'symfony/contracts', 'version' => 'v3.5.0', 'replace' => ['symfony/service-contracts' => 'self.version', 'symfony/cache-contracts' => 'self.version']],
        ]]);
        $graph = DependencyGraph::fromLock($lock, ProjectConfig::fromArray(['require' => ['root/app' => '^1']]), false);

        self::assertSame(['root/app', 'symfony/contracts'], $graph->shortestChain('symfony/contracts'));
        self::assertSame(['root/app' => ['root/app', 'symfony/contracts']], $graph->chainsTo('symfony/contracts'));
        self::assertSame([], $graph->namedIn('symfony/contracts'));
    }

    public function testAPackageThatProvidesARequiredNameIsReachedThroughIt(): void
    {
        $lock = LockFile::fromArray(['packages' => [
            ['name' => 'root/app', 'version' => '1.0.0', 'require' => ['psr/log-implementation' => '^1.0']],
            ['name' => 'monolog/monolog', 'version' => '2.9.0', 'provide' => ['psr/log-implementation' => '1.0.0']],
        ]]);
        $graph = DependencyGraph::fromLock($lock, ProjectConfig::fromArray(['require' => ['root/app' => '^1']]), false);

        self::assertSame(['root/app', 'monolog/monolog'], $graph->shortestChain('monolog/monolog'));
    }

    /**
     * Composer installs one package of a name: when the lock carries the required name itself, that
     * package satisfies the requirement, and a package that only provides the name is not reached
     * through it.
     */
    public function testALockedPackageOfTheRequiredNameWinsOverAProvider(): void
    {
        $lock = LockFile::fromArray(['packages' => [
            ['name' => 'root/app', 'version' => '1.0.0', 'require' => ['psr/log' => '^1.0']],
            ['name' => 'psr/log', 'version' => '1.1.4'],
            ['name' => 'vendor/logger', 'version' => '1.0.0', 'provide' => ['psr/log' => '1.1.4']],
        ]]);
        $graph = DependencyGraph::fromLock($lock, ProjectConfig::fromArray(['require' => ['root/app' => '^1']]), false);

        self::assertSame(['root/app', 'psr/log'], $graph->shortestChain('psr/log'));
        self::assertSame([], $graph->shortestChain('vendor/logger'));
    }

    /**
     * wallabag requires ocramius/proxy-manager, and its lock holds friendsofphp/proxy-manager-lts,
     * which replaces it.
     */
    public function testARootRequireOfAReplacedNameMakesTheReplacerDirect(): void
    {
        $lock = LockFile::fromArray(['packages' => [
            ['name' => 'friendsofphp/proxy-manager-lts', 'version' => 'v1.0.18', 'replace' => ['ocramius/proxy-manager' => '^2.1']],
            ['name' => 'laminas/laminas-code', 'version' => '4.16.0'],
        ]]);
        $project = ProjectConfig::fromArray(['require' => ['ocramius/proxy-manager' => '^2.1', 'laminas/laminas-code' => '^4.16']]);
        $graph = DependencyGraph::fromLock($lock, $project, false);

        self::assertSame(['friendsofphp/proxy-manager-lts'], $graph->shortestChain('friendsofphp/proxy-manager-lts'));
        self::assertSame(['friendsofphp/proxy-manager-lts' => ['friendsofphp/proxy-manager-lts']], $graph->chainsTo('friendsofphp/proxy-manager-lts'));
        self::assertSame([ProjectConfig::REQUIRE], $graph->namedIn('friendsofphp/proxy-manager-lts'));
        self::assertSame([ProjectConfig::REQUIRE], $graph->namedIn('laminas/laminas-code'));
    }

    /** Composer compares package names case-insensitively, and the lock writes them in lower case. */
    public function testARootNameIsMatchedWhateverItsCase(): void
    {
        $lock = LockFile::fromArray(['packages' => [
            ['name' => 'foo/bar', 'version' => '1.0.0', 'require' => ['vendor/dep' => '^1']],
            ['name' => 'vendor/dep', 'version' => '1.0.0'],
        ]]);
        $graph = DependencyGraph::fromLock($lock, ProjectConfig::fromArray(['require' => ['Foo/Bar' => '^1']]), false);

        self::assertSame(['foo/bar'], $graph->shortestChain('foo/bar'));
        self::assertSame(['foo/bar', 'vendor/dep'], $graph->shortestChain('vendor/dep'));
        self::assertSame([ProjectConfig::REQUIRE], $graph->namedIn('foo/bar'));
    }

    /** A require-dev name that a dev package carries does not move to a prod provider without `--dev`. */
    public function testNamedInDoesNotDependOnDev(): void
    {
        $lock = LockFile::fromArray([
            'packages' => [
                ['name' => 'vendor/impl', 'version' => '1.0.0', 'provide' => ['vendor/iface' => '1.0.0']],
                ['name' => 'app/x', 'version' => '1.0.0', 'require' => ['vendor/impl' => '^1']],
            ],
            'packages-dev' => [['name' => 'vendor/iface', 'version' => '1.0.0']],
        ]);
        $project = ProjectConfig::fromArray(['require' => ['app/x' => '^1'], 'require-dev' => ['vendor/iface' => '^1']]);

        foreach ([true, false] as $includeDev) {
            self::assertSame([], DependencyGraph::fromLock($lock, $project, $includeDev)->namedIn('vendor/impl'), $includeDev ? 'with dev' : 'without dev');
        }
    }

    /** Both sections are read whatever `--dev` says, in the order require, require-dev. */
    public function testNamedInListsEverySectionThatNamesThePackage(): void
    {
        $lock = LockFile::fromArray(['packages' => [
            ['name' => 'vendor/both', 'version' => '1.0.0'],
            ['name' => 'vendor/prod', 'version' => '1.0.0', 'require' => ['vendor/deep' => '^1']],
            ['name' => 'vendor/deep', 'version' => '1.0.0'],
            ['name' => 'vendor/split', 'version' => '1.0.0', 'replace' => ['vendor/old' => 'self.version']],
        ], 'packages-dev' => [
            ['name' => 'vendor/tool', 'version' => '1.0.0'],
        ]]);
        $project = ProjectConfig::fromArray([
            'require' => ['vendor/prod' => '^1', 'vendor/both' => '^1'],
            'require-dev' => ['vendor/both' => '^1', 'Vendor/Tool' => '^1', 'vendor/old' => '^1'],
        ]);

        foreach ([false, true] as $dev) {
            $graph = DependencyGraph::fromLock($lock, $project, $dev);
            self::assertSame([ProjectConfig::REQUIRE, ProjectConfig::REQUIRE_DEV], $graph->namedIn('vendor/both'));
            self::assertSame([ProjectConfig::REQUIRE], $graph->namedIn('vendor/prod'));
            self::assertSame([ProjectConfig::REQUIRE_DEV], $graph->namedIn('vendor/split'));
            self::assertSame([], $graph->namedIn('vendor/deep'));
            self::assertSame([], $graph->namedIn('vendor/not-in-lock'));
        }
        self::assertSame([ProjectConfig::REQUIRE_DEV], DependencyGraph::fromLock($lock, $project, true)->namedIn('vendor/tool'));
        self::assertSame(['vendor/split'], DependencyGraph::fromLock($lock, $project, true)->shortestChain('vendor/split'));
        self::assertSame([], DependencyGraph::fromLock($lock, $project, false)->shortestChain('vendor/split'), 'a require-dev root is walked only with --dev');
    }
}
