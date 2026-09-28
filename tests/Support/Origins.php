<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Lockrot\Lock\ConfiguredRepositories;
use Lockrot\Lock\OriginFacts;
use Lockrot\Lock\PackageOrigin;

/** Lock-entry origins for tests that only care whether a repository was asked: packagist.org, or nowhere lockrot can name. */
final class Origins
{
    public static function facts(bool $fromComposerRepository): OriginFacts
    {
        return new OriginFacts($fromComposerRepository ? 'https://packagist.org/downloads/' : null, null, null, null, null);
    }

    public static function of(bool $fromComposerRepository, string $package = 'vendor/pkg'): PackageOrigin
    {
        return PackageOrigin::of($package, '1.0.0', self::facts($fromComposerRepository), ConfiguredRepositories::none());
    }
}
