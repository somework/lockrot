<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Lock\LockFile;

/**
 * C08: for a transitive package, whether each direct dependent's newest stable release still
 * requires it. It reads the `require` names of the metadata that the run already loaded, so it
 * makes no request.
 *
 * @internal
 */
final class ThroughCheck
{
    /**
     * One entry per direct dependent, in `direct_dependents` order; `[]` for a direct package and an
     * unreached one.
     *
     * @param list<string>                   $dependents `direct_dependents`
     * @param array<string, PackageMetadata> $metadata   by package name, what the run read
     *
     * @return list<ThroughEntry>
     */
    public static function of(string $package, bool $direct, array $dependents, LockFile $lock, array $metadata): array
    {
        if ($direct) {
            return [];
        }
        $entries = [];
        foreach ($dependents as $name) {
            $locked = $lock->find($name);
            $version = $locked === null ? null : $locked->version();
            $meta = $metadata[$name] ?? null;
            if ($locked !== null && !$locked->isFromComposerRepository()) {
                $entries[] = ThroughEntry::unread($name, $version, ThroughEntry::NOT_FROM_COMPOSER_REPOSITORY);
            } elseif ($meta === null || $version === null) {
                $entries[] = ThroughEntry::unread($name, $version, ThroughEntry::RELEASES_UNKNOWN);
            } else {
                $entries[] = ThroughEntry::measured($name, $version, strtolower($package), $meta->installedRequires(), $meta->newestStable(), $meta->newestRequires());
            }
        }

        return $entries;
    }
}
