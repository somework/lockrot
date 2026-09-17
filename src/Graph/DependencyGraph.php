<?php

declare(strict_types=1);

namespace Lockrot\Graph;

use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;

final class DependencyGraph
{
    /** @var array<string, list<string>> package => list of packages it requires */
    private array $edges;
    /** @var list<string> */
    private array $roots;
    /**
     * BFS parent maps, one per root, built on first use by {@see chainsTo()}. A memo of a pure
     * function of the immutable edges, so the graph still reads the same however often it is asked.
     *
     * @var array<string, array<string, string|null>>
     */
    private array $trees = [];

    /**
     * @param array<string, list<string>> $edges
     * @param list<string> $roots may name a package the lock does not carry; every walk below
     *                            only ever follows an edge that exists, so such a root leads nowhere
     */
    private function __construct(array $edges, array $roots)
    {
        $this->edges = $edges;
        $this->roots = $roots;
    }

    public static function fromLock(LockFile $lock, ProjectConfig $project, bool $includeDev): self
    {
        $edges = [];
        foreach ($lock->packages($includeDev) as $package) {
            $edges[$package->name()] = $package->requires();
        }
        $roots = $project->directRequires();
        if ($includeDev) {
            $roots = array_merge($roots, $project->directDevRequires());
        }

        return new self($edges, $roots);
    }

    /** @return list<string> */
    public function shortestChain(string $target): array
    {
        if (!isset($this->edges[$target])) {
            return [];
        }
        /** @var array<string, string|null> $parent */
        $parent = [];
        $queue = [];
        foreach ($this->roots as $root) {
            $parent[$root] = null;
            $queue[] = $root;
        }
        while ($queue !== []) {
            $current = array_shift($queue);
            if ($current === $target) {
                return $this->unwind($parent, $target);
            }
            foreach ($this->edges[$current] ?? [] as $next) {
                if (!\array_key_exists($next, $parent) && isset($this->edges[$next])) {
                    $parent[$next] = $current;
                    $queue[] = $next;
                }
            }
        }

        return [];
    }

    /**
     * Every direct requirement of the project from which $target is reachable, each with the
     * shortest chain from it to $target, keyed and sorted by the requirement's name. A direct
     * package maps to itself with a one-element chain; a package nothing reaches maps to nothing.
     *
     * {@see shortestChain()} keeps its own multi-source search: the two agree on chain length for
     * the root the shortest chain starts from, but where a root has several equally short paths
     * the single-source tree here may pick a different one, so the chain a finding carries is
     * never rebuilt from this method.
     *
     * @return array<string, list<string>>
     */
    public function chainsTo(string $target): array
    {
        if (!isset($this->edges[$target])) {
            return [];
        }
        $chains = [];
        foreach ($this->roots as $root) {
            $tree = $this->tree($root);
            if (\array_key_exists($target, $tree)) {
                $chains[$root] = $this->unwind($tree, $target);
            }
        }
        ksort($chains, \SORT_STRING);

        return $chains;
    }

    /**
     * The BFS tree from one root: node => the node it was first reached from, null for the root.
     *
     * @return array<string, string|null>
     */
    private function tree(string $root): array
    {
        if (isset($this->trees[$root])) {
            return $this->trees[$root];
        }
        /** @var array<string, string|null> $parent */
        $parent = [$root => null];
        $queue = [$root];
        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($this->edges[$current] ?? [] as $next) {
                if (!\array_key_exists($next, $parent) && isset($this->edges[$next])) {
                    $parent[$next] = $current;
                    $queue[] = $next;
                }
            }
        }

        return $this->trees[$root] = $parent;
    }

    /**
     * @param array<string, string|null> $parent
     * @return list<string>
     */
    private function unwind(array $parent, string $target): array
    {
        $chain = [];
        for ($node = $target; $node !== null; $node = $parent[$node]) {
            $chain[] = $node;
        }

        return array_reverse($chain);
    }
}
