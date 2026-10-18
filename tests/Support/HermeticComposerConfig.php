<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Composer\Config;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use Composer\Util\Platform;

/**
 * Composer's config without the machine's: Factory::createConfig() reads `auth.json` and
 * `config.json` below COMPOSER_HOME and the COMPOSER_AUTH variable, and it creates the home, cache
 * and data directories. This config reads and writes only an empty home that the caller owns, with
 * its cache below it and no COMPOSER_AUTH. The process environment is restored after.
 */
final class HermeticComposerConfig
{
    private const VARIABLES = ['COMPOSER_HOME', 'COMPOSER_CACHE_DIR', 'COMPOSER_AUTH'];

    /** @param string $home a directory that the caller removes, with everything below it */
    public static function create(string $home, ?IOInterface $io = null): Config
    {
        $saved = [];
        foreach (self::VARIABLES as $name) {
            $saved[$name] = Platform::getEnv($name);
        }
        Platform::putEnv('COMPOSER_HOME', $home);
        Platform::putEnv('COMPOSER_CACHE_DIR', $home.'/cache');
        Platform::clearEnv('COMPOSER_AUTH');
        try {
            return Factory::createConfig($io ?? new NullIO());
        } finally {
            foreach ($saved as $name => $value) {
                $value === false ? Platform::clearEnv($name) : Platform::putEnv($name, $value);
            }
        }
    }
}
