<?php

declare(strict_types=1);

namespace Lockrot\Solver;

use Composer\Composer;
use Composer\Repository\RepositorySet;

/**
 * What the running Composer offers the move check. One code path solves on Composer 2.2 to 2.10,
 * and these switches cover where the versions differ (docs/verdicts.md#the-move-check).
 *
 * @internal
 */
final class ComposerFeatures
{
    public static function version(): string
    {
        return Composer::getVersion();
    }

    /**
     * Composer 2.4 and later take an inline constraint (`update vendor/pkg:^1.2`) as a temporary
     * constraint of the RepositorySet. Composer 2.2 and 2.3 accept one only on a root requirement.
     */
    public static function hasTemporaryConstraints(): bool
    {
        return (new \ReflectionMethod(RepositorySet::class, '__construct'))->getNumberOfParameters() >= 6;
    }

    /** Composer 2.9 and later remove the versions that an advisory affects from the pool of an update. */
    public static function blocksAdvisories(): bool
    {
        return class_exists('Composer\\DependencyResolver\\SecurityAdvisoryPoolFilter');
    }
}
