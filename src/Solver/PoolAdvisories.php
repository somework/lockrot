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
    /** @var array<string, true> */
    private array $keptOut;

    /**
     * @param \Closure(list<string>, bool): (array<string, list<PartialSecurityAdvisory>>|null) $lookup  every record of each name, complete ones only when the flag is true, null when it could not read them
     * @param list<string>                                                                    $keptOut the names that no lookup sends, which stay unread: `advisory-lookup: composer-repositories` keeps the names that no Composer repository serves
     */
    public function __construct(AdvisoryBlocking $blocking, \Closure $lookup, array $keptOut = [])
    {
        $this->blocking = $blocking;
        $this->lookup = $lookup;
        $this->keptOut = array_fill_keys($keptOut, true);
    }

    /**
     * A copy that knows the run's lookup. A name with records that $covered lacks missed a
     * repository: its records are partial, so it counts as unread.
     *
     * @param array<string, list<PartialSecurityAdvisory>> $records every record of each name
     * @param list<string>                                 $covered the names that the lookup read from every repository, with records or without
     */
    public function withRecords(array $records, array $covered): self
    {
        $read = array_fill_keys($covered, true);
        $partial = array_diff_key(array_fill_keys(array_keys($records), true), $read);
        $clone = clone $this;
        $clone->records = $records + $this->records;
        $clone->covered = $read + $partial + $this->covered;
        $clone->unread = $partial + array_diff_key($this->unread, $read);

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
        $keptOut = array_fill_keys(array_values(array_intersect($missing, array_keys($this->keptOut))), true);
        $this->unread += $keptOut;
        $this->covered += $keptOut;
        $missing = array_values(array_diff($missing, array_keys($keptOut)));
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
