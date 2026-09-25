<?php

declare(strict_types=1);

namespace Lockrot\Baseline;

/**
 * One accepted finding as the baseline file records it: the package it is about, the version and
 * verdict at the time the baseline was written, and the date lockrot first saw it.
 *
 * `version` is informational — matching is by package name only, so a version bump that keeps the
 * same verdict stays accepted. `firstSeen` is carried over across regenerations so the file says
 * how long a finding has been tolerated.
 *
 * @internal
 */
final class BaselineEntry
{
    private string $package;
    private string $version;
    private string $verdict;
    private string $firstSeen;

    public function __construct(string $package, string $version, string $verdict, string $firstSeen)
    {
        $this->package = $package;
        $this->version = $version;
        $this->verdict = $verdict;
        $this->firstSeen = $firstSeen;
    }

    public function package(): string
    {
        return $this->package;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function verdict(): string
    {
        return $this->verdict;
    }

    /** The `Y-m-d` date this package first appeared in a baseline. */
    public function firstSeen(): string
    {
        return $this->firstSeen;
    }

    /** @return array<string, string> the entry's body, keyed as the baseline file writes it */
    public function toArray(): array
    {
        return ['version' => $this->version, 'verdict' => $this->verdict, 'first_seen' => $this->firstSeen];
    }
}
