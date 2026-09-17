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

    public function testTransitiveChain(): void
    {
        self::assertSame(['vendor/direct', 'vendor/transitive'], $this->graph(false)->shortestChain('vendor/transitive'));
    }

    public function testUnreachableIsEmpty(): void
    {
        self::assertSame([], $this->graph(false)->shortestChain('vendor/snapshot'));
    }

    public function testDevRootsOnlyWhenIncluded(): void
    {
        self::assertSame([], $this->graph(false)->shortestChain('vendor/devtool'));
        self::assertSame(['vendor/devtool'], $this->graph(true)->shortestChain('vendor/devtool'));
    }

    public function testWallabagChainEndsWithPackage(): void
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
}
