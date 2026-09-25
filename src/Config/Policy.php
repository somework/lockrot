<?php

declare(strict_types=1);

namespace Lockrot\Config;

use Lockrot\Analyzer\Report;
use Lockrot\Verdict\FailOn;

/** @internal */
final class Policy
{
    public const EXIT_OK = 0;
    public const EXIT_FINDINGS = 1;
    public const EXIT_ERROR = 2;

    public static function exitCode(Report $report, LockrotConfig $config): int
    {
        if (self::strictNetworkTripped($report, $config)) {
            return self::EXIT_FINDINGS;
        }
        $threshold = FailOn::fromString($config->failOn());
        if ($threshold->isNone()) {
            return self::EXIT_OK;
        }
        $baseline = $report->baseline();
        foreach ($report->findings() as $finding) {
            // With a baseline present only new and worsened findings are measured against fail-on:
            // a finding the project has already accepted never fails a build again, and a baseline
            // entry whose package has left the lock is reported as stale, not failed on. The
            // baseline is keyed by verdict; a priority threshold changes what is measured, not what
            // counts as accepted.
            if ($baseline !== null && $baseline->isKnown($finding->package())) {
                continue;
            }
            if ($threshold->reaches($finding)) {
                return self::EXIT_FINDINGS;
            }
        }

        return self::EXIT_OK;
    }

    /**
     * Whether --strict-network alone already decides the exit code. Shared with the
     * `--generate-baseline` path, which ignores fail-on entirely but still honours this.
     */
    public static function strictNetworkTripped(Report $report, LockrotConfig $config): bool
    {
        return $config->strictNetwork() && $report->hadNetworkFailures();
    }
}
