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

    /** The exit code of a check run: {@see Gate::decide()} over the report and the configuration's policy. */
    public static function exitCode(Report $report, LockrotConfig $config): int
    {
        return Gate::decide($report, FailOn::fromString($config->failOn()), $config->strictNetwork(), Gate::MODE_CHECK)->fails()
            ? self::EXIT_FINDINGS
            : self::EXIT_OK;
    }
}
