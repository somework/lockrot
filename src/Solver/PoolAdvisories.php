<?php

declare(strict_types=1);

namespace Lockrot\Solver;

use Composer\Advisory\PartialSecurityAdvisory;
use Composer\Semver\Constraint\ConstraintInterface;

/**
 * The advisory lookups of the move check, kept per kind: partial records, and complete records.
 * Composer's own `SecurityAdvisoryPoolFilter` asks them through a {@see PoolAdvisoryFeed}. The
 * run's own lookup seeds the complete records of the names that it analysed. Every other name is
 * looked up once per kind, as the project's repositories answer Composer.
 *
 * @internal
 */
final class PoolAdvisories
{
    private AdvisoryBlocking $blocking;
    /** @var \Closure(list<string>, bool): (array<string, list<PartialSecurityAdvisory>>|null) */
    private \Closure $lookup;
    /** @var array<string, list<PartialSecurityAdvisory>|null> each looked up name => its partial records, null when unread */
    private array $partial = [];
    /** @var array<string, list<PartialSecurityAdvisory>|null> each looked up name => its complete records, null when unread */
    private array $complete = [];
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
     * A copy whose complete records of the run's names come from the run's lookup. A name with
     * records that $covered lacks missed a repository, so its complete records are unread.
     *
     * @param array<string, list<PartialSecurityAdvisory>> $records every record of each name
     * @param list<string>                                 $covered the names that the lookup read from every repository, with records or without
     */
    public function withRecords(array $records, array $covered): self
    {
        $complete = [];
        foreach ($covered as $name) {
            $complete[$name] = $records[$name] ?? [];
        }
        foreach (array_diff_key($records, $complete) as $name => $partial) {
            $complete[$name] = null;
        }
        $clone = clone $this;
        $clone->complete = $complete + $this->complete;

        return $clone;
    }

    public function blocking(): AdvisoryBlocking
    {
        return $this->blocking;
    }

    /**
     * The records of the asked names as a Composer repository answers `getSecurityAdvisories()`:
     * each record whose range meets the name's constraint.
     *
     * @param array<string, ConstraintInterface> $constraintMap name => the versions that the pool holds
     *
     * @return array{namesFound: list<string>, advisories: array<string, list<PartialSecurityAdvisory>>, unread: list<string>}
     */
    public function answer(array $constraintMap, bool $partial): array
    {
        $memo = $partial ? $this->partial : $this->complete;
        $missing = [];
        foreach (array_keys($constraintMap) as $name) {
            if (\array_key_exists($name, $memo)) {
                continue;
            }
            if (isset($this->keptOut[$name])) {
                $memo[$name] = null;
                continue;
            }
            $missing[] = (string) $name;
        }
        if ($missing !== []) {
            $found = ($this->lookup)($missing, !$partial);
            foreach ($missing as $name) {
                $memo[$name] = $found === null ? null : $found[$name] ?? [];
            }
        }
        if ($partial) {
            $this->partial = $memo;
        } else {
            $this->complete = $memo;
        }

        $answer = ['namesFound' => [], 'advisories' => [], 'unread' => []];
        foreach ($constraintMap as $name => $constraint) {
            $records = $memo[$name] ?? null;
            if ($records === null) {
                $answer['unread'][] = (string) $name;
                continue;
            }
            $matching = array_values(array_filter($records, static fn (PartialSecurityAdvisory $advisory): bool => $advisory->affectedVersions->matches($constraint)));
            if ($matching !== []) {
                $answer['namesFound'][] = (string) $name;
                $answer['advisories'][$name] = $matching;
            }
        }

        return $answer;
    }
}
