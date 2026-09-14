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
     * @param array<string, list<string>> $edges
     * @param list<string> $roots
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
        $roots = array_values(array_filter($roots, static fn (string $name): bool => isset($edges[$name])));

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
     * @param array<string, string|null> $parent
     * @return list<string>
     */
    private function unwind(array $parent, string $target): array
    {
        $chain = [];
        $node = $target;
        while ($node !== null) {
            array_unshift($chain, $node);
            $node = $parent[$node];
        }

        return $chain;
    }
}
