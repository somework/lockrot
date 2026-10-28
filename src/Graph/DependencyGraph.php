<?php

declare(strict_types=1);

namespace Lockrot\Graph;

use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;

/** @internal */
final class DependencyGraph
{
    /** @var array<string, list<string>> package => list of packages it requires */
    private array $edges;
    /** @var array<string, list<ProjectConfig::REQUIRE|ProjectConfig::REQUIRE_DEV>> package => the root sections that name it */
    private array $namedIn = [];
    /**
     * The constructor runs every walk, and the edges never change, so a query only reads these maps.
     * Each is a BFS parent map: node => the node it was first reached from, null for a root.
     *
     * @var array<string, string|null> the walk from every root at once
     */
    private array $nearest;
    /** @var array<string, array<string, string|null>> root => the walk from that root alone */
    private array $trees = [];
    /** @var array<string, list<string>> node => the roots whose walk reaches it */
    private array $reachedFrom = [];

    /**
     * @param array<string, list<string>> $edges
     * @param list<string> $roots can name a package that the lock does not carry: a walk follows only
     *                            an existing edge, so such a root leads nowhere
     */
    private function __construct(array $edges, array $roots)
    {
        $this->edges = $edges;
        $this->nearest = self::bfs($edges, $roots);
        foreach ($roots as $root) {
            $this->trees[$root] = self::bfs($edges, [$root]);
        }
        foreach ($this->trees as $root => $tree) {
            foreach ($tree as $node => $_) {
                $this->reachedFrom[$node][] = $root;
            }
        }
    }

    /**
     * A required name resolves to the locked package of that name, else to every locked package that
     * replaces or provides it, as Composer's solver reads the lock. Root names are compared in lower
     * case, as Composer compares them. Names resolve against the packages that the run installs:
     * without $includeDev, a prod provider wins over a dev package of the name. `namedIn()` reads
     * both root sections whatever $includeDev says.
     */
    public static function fromLock(LockFile $lock, ProjectConfig $project, bool $includeDev): self
    {
        $packages = $lock->packages($includeDev);
        $present = [];
        $satisfiedBy = [];
        foreach ($packages as $package) {
            $present[$package->name()] = true;
            foreach (array_keys($package->replaces() + $package->provides()) as $name) {
                $satisfiedBy[$name][] = $package->name();
            }
        }
        $edges = [];
        foreach ($packages as $package) {
            $edges[$package->name()] = self::resolve($package->requires(), $present, $satisfiedBy);
        }
        $sections = [
            ProjectConfig::REQUIRE => self::resolve($project->directRequires(), $present, $satisfiedBy),
            ProjectConfig::REQUIRE_DEV => self::resolve($project->directDevRequires(), $present, $satisfiedBy),
        ];
        $roots = $sections[ProjectConfig::REQUIRE];
        if ($includeDev) {
            $roots = array_merge($roots, $sections[ProjectConfig::REQUIRE_DEV]);
        }
        $graph = new self($edges, $roots);
        foreach ($sections as $section => $names) {
            foreach ($names as $name) {
                $graph->namedIn[$name][] = $section;
            }
        }

        return $graph;
    }

    /**
     * @param list<string>                $names
     * @param array<string, true>         $present
     * @param array<string, list<string>> $satisfiedBy name => the packages that replace or provide it
     *
     * @return list<string> the packages that satisfy the names, a name that nothing satisfies kept as it is
     */
    private static function resolve(array $names, array $present, array $satisfiedBy): array
    {
        $resolved = [];
        foreach ($names as $name) {
            $name = strtolower($name);
            foreach (isset($present[$name]) ? [$name] : ($satisfiedBy[$name] ?? [$name]) as $package) {
                $resolved[$package] = true;
            }
        }

        return array_keys($resolved);
    }

    /**
     * The root sections whose keys name the package, directly or through a name that it replaces or
     * provides, in the order require, require-dev. Empty for a package that no root line names.
     *
     * @return list<ProjectConfig::REQUIRE|ProjectConfig::REQUIRE_DEV>
     */
    public function namedIn(string $package): array
    {
        return isset($this->edges[$package]) ? ($this->namedIn[$package] ?? []) : [];
    }

    /** @return list<string> */
    public function shortestChain(string $target): array
    {
        return isset($this->edges[$target]) && \array_key_exists($target, $this->nearest) ? $this->unwind($this->nearest, $target) : [];
    }

    /**
     * Each direct requirement that reaches $target, with a shortest chain from it to $target, keyed
     * and sorted by name. A $target that is a direct requirement maps to itself with a one-element
     * chain.
     *
     * Do not rebuild a finding's chain from this method: with several equally short paths from one
     * root, its single-source tree can pick a different one than {@see shortestChain()}.
     *
     * @return array<string, list<string>>
     */
    public function chainsTo(string $target): array
    {
        if (!isset($this->edges[$target])) {
            return [];
        }
        $chains = [];
        foreach ($this->reachedFrom[$target] ?? [] as $root) {
            $chains[$root] = $this->unwind($this->trees[$root], $target);
        }
        ksort($chains, \SORT_STRING);

        return $chains;
    }

    /**
     * Every source is seeded in order, duplicates included, and a node is visited at its first
     * discovery through an edge to a locked package. This order decides the tie-breaks between
     * equally short chains: change it and the chains of real locks change.
     *
     * @param array<string, list<string>> $edges
     * @param list<string>                $sources
     *
     * @return array<string, string|null>
     */
    private static function bfs(array $edges, array $sources): array
    {
        $parent = [];
        $queue = [];
        foreach ($sources as $source) {
            $parent[$source] = null;
            $queue[] = $source;
        }
        for ($i = 0; $i < \count($queue); ++$i) {
            foreach ($edges[$queue[$i]] ?? [] as $next) {
                if (!\array_key_exists($next, $parent) && isset($edges[$next])) {
                    $parent[$next] = $queue[$i];
                    $queue[] = $next;
                }
            }
        }

        return $parent;
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
