<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Lockrot\Config\ConfigSchema;

/**
 * Forgets the schema {@see ConfigSchema} keeps for the rest of the process, so the next validation is
 * the first one of a process again. PHPUnit runs every test in one process, and whichever test ran
 * first would otherwise have warmed the cache for all the others: a first call that reads the file
 * differently from a later one — the install-time path makes exactly one — would go unseen.
 */
final class ColdConfigSchema
{
    public static function forget(): void
    {
        \Closure::bind(static function (): void {
            ConfigSchema::$schema = null;
        }, null, ConfigSchema::class)();
    }
}
