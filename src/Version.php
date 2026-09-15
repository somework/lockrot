<?php

declare(strict_types=1);

namespace Lockrot;

/**
 * The single place lockrot's own release number is written down. Read by the PHAR entry point
 * (bin/lockrot, `--version` and the Console application version), by the JSON report envelope
 * (Lockrot\Output\JsonFormatter::VERSION, kept as an alias for backwards compatibility) and by the
 * SARIF run's tool.driver.version.
 */
final class Version
{
    public const STRING = '0.1.0';
}
