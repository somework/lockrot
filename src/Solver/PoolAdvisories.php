<?php

declare(strict_types=1);

namespace Lockrot\Solver;

use Composer\Advisory\PartialSecurityAdvisory;
use Composer\Semver\Constraint\ConstraintInterface;

/**
 * The advisories of the names that a solve's pool holds, as Composer 2.9 and later look them up
 * for an update. The run's own lookup covers the names that it analysed. For the other names, one
 * lookup per solve that meets new ones reads the partial records of the metadata that the solve
 * loaded, and asks the complete ones when the ignore list needs them, as Composer does.
 *
 * @internal
 */
final class PoolAdvisories
{
    private AdvisoryBlocking $blocking;
    /** @var \Closure(list<string>, bool): (array<string, list<PartialSecurityAdvisory>>|null) */
    private \Closure $lookup;
    /** @var array<string, list<PartialSecurityAdvisory>> */
    private array $records = [];
    /** @var array<string, true> */
    private array $covered = [];
    /** @var array<string, true> the covered names whose records the lookup could not read */
    private array $unread = [];

    /** @param \Closure(list<string>, bool): (array<string, list<PartialSecurityAdvisory>>|null) $lookup every record of each name, complete ones only when the flag is true, null when it could not read them */
    public function __construct(AdvisoryBlocking $blocking, \Closure $lookup)
    {
        $this->blocking = $blocking;
        $this->lookup = $lookup;
    }

    /**
     * A copy that knows the run's lookup.
     *
     * @param array<string, list<PartialSecurityAdvisory>> $records every record of each name
     * @param list<string>                                 $covered the names that the lookup read, with records or without
     */
    public function withRecords(array $records, array $covered): self
    {
        $clone = clone $this;
        $clone->records = $records + $this->records;
        $clone->covered = array_fill_keys($covered, true) + array_fill_keys(array_keys($records), true) + $this->covered;

        return $clone;
    }

    public function blocking(): AdvisoryBlocking
    {
        return $this->blocking;
    }

    /**
     * @param array<string> $names the pool's names that the update can move
     *
     * @return array<string, list<ConstraintInterface>> the ranges that the update blocks, by name
     */
    public function ranges(array $names): array
    {
        if (!$this->blocking->blocks()) {
            return [];
        }
        $missing = array_values(array_diff(array_unique($names), array_keys($this->covered)));
        if ($missing !== []) {
            $found = ($this->lookup)($missing, false);
            if ($found !== null && $this->blocking->needsComplete($found)) {
                $found = ($this->lookup)($missing, true);
            }
            if ($found === null) {
                $this->unread += array_fill_keys($missing, true);
            }
            $this->records = ($found ?? []) + $this->records;
            $this->covered += array_fill_keys($missing, true);
        }

        return $this->blocking->ranges(array_intersect_key($this->records, array_flip($names)));
    }

    /**
     * @param array<string> $names
     *
     * @return list<string> those of $names whose records a lookup could not read
     */
    public function unread(array $names): array
    {
        return array_values(array_filter($names, fn (string $name): bool => isset($this->unread[$name])));
    }
}
