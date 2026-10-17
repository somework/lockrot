<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Composer\Package\BasePackage;
use Composer\Package\Loader\ArrayLoader;
use Lockrot\Solver\VersionSource;

/** Remote versions written in the test, as Composer's p2 entries, and the names that fail to load. */
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

    /**
     * @param array<string, list<array<string, mixed>>> $entries  name => its versions, each without `name`
     * @param array<string, string>                     $failures name => a VersionSource failure
     */
    public function __construct(array $entries, array $failures = [])
    {
        $this->entries = $entries;
        $this->failures = $failures;
    }

    public function versions(string $name): array
    {
        $this->asked[] = $name;
        if (!isset($this->loaded[$name])) {
            $loader = new ArrayLoader();
            $this->loaded[$name] = array_map(static fn (array $entry): BasePackage => $loader->load(['name' => $name] + $entry), $this->entries[$name] ?? []);
        }

        return $this->loaded[$name];
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
