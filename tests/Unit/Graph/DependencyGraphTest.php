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
        $graph = DependencyGraph::fromLock(
            LockFile::fromFile(__DIR__.'/../../fixtures/apps/wallabag_wallabag/composer.lock'),
            ProjectConfig::fromFile(__DIR__.'/../../fixtures/apps/wallabag_wallabag/composer.json'),
            false
        );
        $chain = $graph->shortestChain('phpzip/phpzip');
        self::assertNotSame([], $chain);
        self::assertSame('phpzip/phpzip', end($chain));
    }
}
