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

    /**
     * The environment variables that turn off the blocking of an update on this Composer version,
     * in the order of `run.move_check.blocking_disabled_by`.
     *
     * @return list<'COMPOSER_NO_BLOCKING'|'COMPOSER_NO_SECURITY_BLOCKING'>
     */
    public static function blockingVariables(string $version): array
    {
        if (version_compare($version, '2.10.0-dev', '>=')) {
            return ['COMPOSER_NO_BLOCKING', 'COMPOSER_NO_SECURITY_BLOCKING'];
        }

        return version_compare($version, '2.9.2', '>=') ? ['COMPOSER_NO_SECURITY_BLOCKING'] : [];
    }
}
