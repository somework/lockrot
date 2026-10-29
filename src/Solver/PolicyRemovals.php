<?php

declare(strict_types=1);

namespace Lockrot\Solver;

use Composer\Advisory\PartialSecurityAdvisory;
use Composer\Advisory\SecurityAdvisory;
use Composer\DependencyResolver\Pool;

/**
 * The versions that Composer's policy filters removed from a pool. `Pool` is Composer's internal
 * API, and its shape differs between the Composer minors that lockrot supports: Composer 2.10 keeps
 * one map per policy, and Composer 2.11 keeps one map of `PolicyRemovalReason`. This class is the
 * one place that reads either shape. It tells them apart by the methods of the pool.
 *
 * @internal
 */
final class PolicyRemovals
{
    public const ADVISORIES = 'advisories';
    public const ABANDONED = 'abandoned';

    /**
     * @return array<string, array<string, string>> name => normalized version => the policy that
     *                                              removed it, in key order of name and then of version. The
     *                                              policy is {@see ADVISORIES} or {@see ABANDONED},
     *                                              or another type of Composer's `PolicyRemovalReason`.
     */
    public static function of(Pool $pool): array
    {
        $removals = method_exists($pool, 'getAllSecurityRemovedPackageVersions') ? self::byMap($pool) : self::byReason($pool);
        ksort($removals);

        return array_map(static function (array $versions): array {
            ksort($versions);

            return $versions;
        }, $removals);
    }

    /** @return array<string, array<string, string>> */
    private static function byReason(Pool $pool): array
    {
        $removals = [];
        foreach ($pool->getAllPolicyRemovedVersions() as $name => $versions) {
            foreach ($versions as $version => $reason) {
                $removals[$name][$version] = $reason->getType();
            }
        }

        return $removals;
    }

    /** @return array<string, array<string, string>> */
    private static function byMap(Pool $pool): array
    {
        $maps = [];
        if (method_exists($pool, 'getAllSecurityRemovedPackageVersions')) {
            /** @var array<string, array<string, array<SecurityAdvisory|PartialSecurityAdvisory>>> $security name => version => the advisories */
            $security = $pool->getAllSecurityRemovedPackageVersions();
            $maps[self::ADVISORIES] = $security;
        }
        if (method_exists($pool, 'getAllAbandonedRemovedPackageVersions')) {
            /** @var array<string, array<string, string>> $abandoned name => version => the pretty version */
            $abandoned = $pool->getAllAbandonedRemovedPackageVersions();
            $maps[self::ABANDONED] = $abandoned;
        }
        $removals = [];
        foreach ($maps as $policy => $map) {
            foreach ($map as $name => $versions) {
                foreach (array_keys($versions) as $version) {
                    $removals[$name][$version] = $policy;
                }
            }
        }

        return $removals;
    }
}
