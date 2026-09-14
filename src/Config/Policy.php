<?php

declare(strict_types=1);

namespace Lockrot\Config;

use Lockrot\Analyzer\Report;
use Lockrot\Verdict\Verdict;

final class Policy
{
    public const EXIT_OK = 0;
    public const EXIT_FINDINGS = 1;
    public const EXIT_ERROR = 2;

    public static function exitCode(Report $report, LockrotConfig $config): int
    {
        if ($config->strictNetwork() && $report->hadNetworkFailures()) {
            return self::EXIT_FINDINGS;
        }
        if ($config->failOn() === LockrotConfig::FAIL_ON_NONE) {
            return self::EXIT_OK;
        }
        $threshold = Verdict::severity($config->failOn());
        foreach ($report->findings() as $finding) {
            if (Verdict::severity($finding->verdict()) >= $threshold) {
                return self::EXIT_FINDINGS;
            }
        }

        return self::EXIT_OK;
    }
}
