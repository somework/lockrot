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
    /** @var list<string> */
    private array $roots;
    /** @var array<string, list<ProjectConfig::REQUIRE|ProjectConfig::REQUIRE_DEV>> package => the root sections that name it */
    private array $namedIn = [];
    /**
     * BFS parent maps, one per root, memoised by {@see tree()}. The edges are immutable, so a map
     * never goes stale.
     *
     * @var array<string, array<string, string|null>>
     */
    private array $trees = [];

    /**
     * @param array<string, list<string>> $edges
     * @param list<string> $roots can name a package that the lock does not carry: a walk follows only
     *                            an existing edge, so such a root leads nowhere
     */
    private function __construct(array $edges, array $roots)
    {
        $this->edges = $edges;
        $this->roots = $roots;
    }

    /**
     * A required name resolves to the locked package of that name, else to every locked package that
     * replaces or provides it, as Composer's solver reads the lock. Root names are compared in lower
     * case, as Composer compares them. `namedIn()` reads both root sections whatever $includeDev says.
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
            $roots = array_values(array_unique(array_merge($roots, $sections[ProjectConfig::REQUIRE_DEV])));
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

        return array_map('strval', array_keys($resolved));
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
