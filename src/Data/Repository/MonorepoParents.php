<?php

declare(strict_types=1);

namespace Lockrot\Data\Repository;

use Lockrot\Exception\ConfigException;
use Lockrot\Json\JsonReader;
use Lockrot\Lock\LockedPackage;

/**
 * Dates a split package's release branches by its monorepo's. A subtree split cuts a tag on
 * every monorepo release, so its tags share one commit, and {@see PackageMetadata::fromPackages()}
 * hands such a tag over undated. The monorepo's tag of the same version carries the release date.
 * The parent comes from the batch or from resources/monorepo-parents.json, and its live `replace`
 * list decides what it dates. See docs/verdicts.md#dates-from-the-monorepo.
 *
 * @internal
 */
final class MonorepoParents
{
    /** @var array<string, list<string>> parent package name => the packages that resources/monorepo-parents.json lists for it */
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
     * The packages that need dates from a parent: their metadata is loaded and
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
     * absent from the batch. A child that no listed monorepo replaces, such as symfony/polyfill-*,
     * costs no request.
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
     * Dates each child's metadata by the parent in the batch whose `replace` names the child, and
     * leaves the rest of the batch as it is.
     *
     * @param list<string>                   $children {@see children()}
     * @param array<string, PackageMetadata> $metadata by package name, parents included
     *
     * @return array<string, PackageMetadata>
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
