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
 * `config.json` below COMPOSER_HOME and the COMPOSER_AUTH variable. This config reads an empty home
 * that the caller owns and no COMPOSER_AUTH, and the process environment is restored after.
 */
final class HermeticComposerConfig
{
    private const VARIABLES = ['COMPOSER_HOME', 'COMPOSER_AUTH'];

    /** @param string $home a directory that the caller creates and removes */
    public static function create(string $home, ?IOInterface $io = null): Config
    {
        $saved = [];
        foreach (self::VARIABLES as $name) {
            $saved[$name] = Platform::getEnv($name);
        }
        Platform::putEnv('COMPOSER_HOME', $home);
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
