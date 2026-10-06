<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Lockrot\Signal\PackageFacts;
use Lockrot\Verdict\Finding;

/** @internal */
final class Analysis
{
    private Report $report;
    /** @var array<string, PackageFacts> */
    private array $facts;

    /** @param array<string, PackageFacts> $facts by package name */
    public function __construct(Report $report, array $facts)
    {
        $this->report = $report;
        $this->facts = $facts;
    }

    public function report(): Report
    {
        return $this->report;
    }

    /** Null for a package the run did not analyse. */
    public function facts(string $package): ?PackageFacts
    {
        return $this->facts[$package] ?? null;
    }

    /** Null for a package the run did not analyse. */
    public function finding(string $package): ?Finding
    {
        $found = null;
        foreach ($this->report->findings() as $finding) {
            if ($finding->package() === $package) {
                $found = $finding;
            }
        }

        return $found;
    }
}
