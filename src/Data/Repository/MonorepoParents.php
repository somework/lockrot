<?php

declare(strict_types=1);

namespace Lockrot\Data\Repository;

use Lockrot\Exception\ConfigException;
use Lockrot\Json\JsonReader;
use Lockrot\Lock\LockedPackage;

/**
 * Dates a split package's release branches by its monorepo's. A subtree split (`illuminate/*`
 * out of laravel/framework, `symfony/*` out of symfony/symfony) cuts a tag on every release of
 * the monorepo whether or not the directory changed, so its tags pile up on one commit and
 * Packagist dates each by that commit: {@see PackageMetadata::fromPackages()} hands such a tag
 * over undated, and S8 and S2 have nothing to measure. The monorepo's own tag for the same
 * version is dated by its release, and `replace: {child: self.version}` says the versions are the
 * same — so the parent's branch dates are the child's.
 *
 * The parent is found in two places, in order: a package already in the metadata batch whose
 * `replace` list names the child — a Laravel application has laravel/framework in its lock, and
 * nothing more is fetched — else one of the monorepos in resources/monorepo-parents.json, loaded
 * from the configured repositories for this purpose alone. That file lists, per monorepo, the
 * packages it was last seen to replace; the snapshot decides only whether a lock is worth one
 * request for that parent. What a parent actually dates is its live `replace` list, so a name the
 * snapshot has lost is dated anyway when the parent is in the lock, one it has gained is skipped
 * when the repository no longer agrees, and symfony/polyfill-* — which symfony/symfony does not
 * replace, though its own monorepo dates its tags the same way — costs no request at all.
 *
 * @internal
 */
final class MonorepoParents
{
    /** @var array<string, list<string>> parent package name => the packages it was last seen to replace */
    private array $candidates;

    /** @param array<string, list<string>> $candidates */
    public function __construct(array $candidates)
    {
        $this->candidates = $candidates;
    }

    public static function load(?string $path = null): self
    {
        $path ??= __DIR__.'/../../../resources/monorepo-parents.json';
        $data = JsonReader::readObject($path);
        $parents = $data['parents'] ?? null;
        if (!\is_array($parents)) {
            throw new ConfigException($path.' must carry a "parents" map');
        }
        $candidates = [];
        foreach ($parents as $parent => $replaces) {
            if (!\is_string($parent) || $parent === '' || !\is_array($replaces)) {
                throw new ConfigException($path.': "parents" maps a package name to the list of packages it replaces');
            }
            $names = [];
            foreach ($replaces as $name) {
                if (!\is_string($name) || $name === '') {
                    throw new ConfigException($path.': every package '.$parent.' replaces must be a package name');
                }
                $names[] = $name;
            }
            $candidates[$parent] = $names;
        }

        return new self($candidates);
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** @return list<string> the listed monorepos, whether or not any lock needs them */
    public function candidates(): array
    {
        return array_keys($this->candidates);
    }

    /**
     * The packages whose findings would read dates a parent could supply: metadata is loaded and
     * {@see PackageMetadata::needsParentDates()} says so for the installed branch and version.
     *
     * @param list<LockedPackage>            $packages
     * @param array<string, PackageMetadata> $metadata by package name
     *
     * @return list<string> package names, in lock order
     */
    public function children(array $packages, array $metadata): array
    {
        $children = [];
        foreach ($packages as $package) {
            $meta = $metadata[$package->name()] ?? null;
            if ($meta !== null && $meta->needsParentDates(ReleaseBranch::of($package->version()), $package->version())) {
                $children[] = $package->name();
            }
        }

        return $children;
    }

    /**
     * The monorepos worth one request for this lock: listed as replacing one of the children, and
     * not in the batch already. A lock with no split package fetches nothing, and neither does one
     * whose undated packages belong to no listed monorepo — symfony/polyfill-* cuts its tags the
     * same way, and no Packagist package replaces it.
     *
     * @param list<string>                   $children {@see children()}
     * @param array<string, PackageMetadata> $metadata by package name
     *
     * @return list<string>
     */
    public function missingCandidates(array $children, array $metadata): array
    {
        if ($children === []) {
            return [];
        }
        $missing = [];
        foreach ($this->candidates as $candidate => $replaces) {
            if (!isset($metadata[$candidate]) && array_intersect($replaces, $children) !== []) {
                $missing[] = $candidate;
            }
        }

        return $missing;
    }

    /**
     * Each child's metadata dated by its parent's, where the batch holds one whose `replace` names
     * the child; the rest of the batch unchanged. The batch may carry the parents themselves, in
     * the lock or loaded for this ({@see missingCandidates()}); a parent that is also a child of
     * another package (it does not happen) is dated first, in batch order.
     *
     * @param list<string>                   $children {@see children()}
     * @param array<string, PackageMetadata> $metadata by package name, parents included
     *
     * @return array<string, PackageMetadata> the same map, children replaced by their dated copies
     */
    public function date(array $children, array $metadata): array
    {
        foreach ($children as $child) {
            $parent = self::parentOf($child, $metadata);
            if ($parent !== null) {
                $metadata[$child] = $metadata[$child]->datedBy($parent);
            }
        }

        return $metadata;
    }

    /** @param array<string, PackageMetadata> $metadata */
    private static function parentOf(string $child, array $metadata): ?PackageMetadata
    {
        foreach ($metadata as $name => $candidate) {
            if ($name !== $child && \in_array($child, $candidate->replaces(), true)) {
                return $candidate;
            }
        }

        return null;
    }
}
