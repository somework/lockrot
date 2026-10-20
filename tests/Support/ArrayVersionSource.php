<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Composer\Package\BasePackage;
use Composer\Package\Loader\ArrayLoader;
use Lockrot\Solver\VersionSource;

/** Remote versions written in the test, as Composer's p2 entries, the names that fail to load and the names whose load throws. */
final class ArrayVersionSource implements VersionSource
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $entries;
    /** @var array<string, string> */
    private array $failures;
    /** @var array<string, list<BasePackage>> */
    private array $loaded = [];
    /** @var list<string> */
    private array $asked = [];
    /** @var array<string, \Throwable> */
    private array $throws;

    /**
     * @param array<string, list<array<string, mixed>>> $entries  name => its versions, each without `name`
     * @param array<string, string>                     $failures name => a VersionSource failure
     * @param array<string, \Throwable>                 $throws   name => what its load throws
     */
    public function __construct(array $entries, array $failures = [], array $throws = [])
    {
        $this->entries = $entries;
        $this->failures = $failures;
        $this->throws = $throws;
    }

    public function versions(string $name): array
    {
        $this->asked[] = $name;
        if (isset($this->throws[$name])) {
            throw $this->throws[$name];
        }
        if (!isset($this->loaded[$name])) {
            $loader = new ArrayLoader();
            $this->loaded[$name] = array_map(static fn (array $entry): BasePackage => $loader->load(['name' => $name] + $entry), $this->entries[$name] ?? []);
        }

        return $this->loaded[$name];
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
        return \in_array($name, $this->asked, true) ? ($this->failures[$name] ?? null) : null;
    }

    /** @return list<string> */
    public function asked(): array
    {
        return array_values(array_unique($this->asked));
    }
}
