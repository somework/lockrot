<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Lockrot\Signal\PackageFacts;
use Lockrot\Verdict\Finding;

/**
 * A run's report together with what each finding was decided on: the locked package, its
 * repository metadata, its repository activity and its advisories, by package name. The report is
 * what every format prints; the facts are what `--explain` prints for one package, and they are
 * kept only when a caller asks for them ({@see Analyzer::analyzeWithFacts()}) — a report on its own
 * ({@see Analyzer::analyze()}) lets them go with the run.
 *
 * @internal
 */
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

    /** The facts behind one package's finding, null for a package the run did not analyse. */
    public function facts(string $package): ?PackageFacts
    {
        return $this->facts[$package] ?? null;
    }

    /** The report's finding for one package, null for a package the run did not analyse. */
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
