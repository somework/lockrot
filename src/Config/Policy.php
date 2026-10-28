<?php

declare(strict_types=1);

namespace Lockrot\Config;

/** @internal */
final class Policy
{
    public const EXIT_OK = 0;
    public const EXIT_FINDINGS = 1;
    public const EXIT_ERROR = 2;

    /** The one map from a run's gate to its exit code: docs/ci.md#exit-codes. */
    public static function exitCode(Gate $gate): int
    {
        return $gate->fails() ? self::EXIT_FINDINGS : self::EXIT_OK;
    }
}
