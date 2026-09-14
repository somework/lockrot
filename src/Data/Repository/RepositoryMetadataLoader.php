<?php

declare(strict_types=1);

namespace Lockrot\Data\Repository;

use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Repository\ComposerRepository;
use Composer\Repository\RepositoryInterface;
use Lockrot\Clock;

final class RepositoryMetadataLoader implements MetadataLoaderInterface
{
    public const CHUNK_SIZE = 10;

    /**
     * Every stability, i.e. no stability filtering at all: lockrot reports on what the lock file
     * has already resolved, so a pre-release or branch version must still be readable here.
     * Composer\Package\BasePackage::$stabilities is deprecated in 2.10 in favour of the
     * BasePackage::STABILITIES constant, which 2.2 LTS does not have; the individual
     * BasePackage::STABILITY_* constants exist in both (2.10.3 src/Composer/Package/BasePackage.php:37-41,
     * 2.2.25 :37-41), so the map is spelled out from those.
     */
    private const ALL_STABILITIES = [
        'stable' => BasePackage::STABILITY_STABLE,
        'RC' => BasePackage::STABILITY_RC,
        'beta' => BasePackage::STABILITY_BETA,
        'alpha' => BasePackage::STABILITY_ALPHA,
        'dev' => BasePackage::STABILITY_DEV,
    ];

    /** @var list<RepositoryInterface> */
    private array $repositories;
    private Clock $clock;
    private bool $offline;

    /** @param list<RepositoryInterface> $repositories only ComposerRepository instances are queried */
    public function __construct(array $repositories, Clock $clock, bool $offline = false)
    {
        $this->repositories = $repositories;
        $this->clock = $clock;
        $this->offline = $offline;
    }

    /**
     * Repositories are consulted in their configured order and the first one to resolve a name
     * wins. A name a repository could not answer for — absent from it, or a failure against it —
     * stays in the queue for the repositories behind it: one repository being unable to answer
     * says nothing about whether the next one holds the package. A failure reason is therefore only
     * reported once every repository has been given the name and none of them resolved it.
     *
     * @param list<string> $names
     */
    public function load(array $names): MetadataBatch
    {
        $remaining = array_values(array_unique($names));
        $metadata = [];
        $reasons = [];

        foreach ($this->repositories as $repository) {
            if ($remaining === [] || !$repository instanceof ComposerRepository) {
                continue;
            }
            $batch = $this->loadFromRepository($repository, $remaining);
            $metadata += $batch->metadata();
            foreach ($batch->failed() as $name => $reason) {
                $reasons[$name] = $reason;
            }
            $remaining = $this->unresolved($remaining, $metadata);
        }

        $notFound = [];
        $failed = [];
        foreach ($remaining as $name) {
            if (isset($reasons[$name])) {
                $failed[$name] = $reasons[$name];
                continue;
            }
            if ($this->offline) {
                $failed[$name] = MetadataLoaderInterface::OFFLINE_NOT_FOUND_REASON;
                continue;
            }
            $notFound[] = $name;
        }

        return new MetadataBatch($metadata, $notFound, $failed);
    }

    /**
     * @param list<string>                   $names
     * @param array<string, PackageMetadata> $metadata everything resolved so far, by any repository
     *
     * @return list<string>
     */
    private function unresolved(array $names, array $metadata): array
    {
        $still = [];
        foreach ($names as $name) {
            if (!isset($metadata[$name])) {
                $still[] = $name;
            }
        }

        return $still;
    }

    /**
     * @param list<string> $remaining names to query against this one repository
     *
     * @return MetadataBatch metadata()/failed() as this one repository answered them; notFound()
     *                       carries the names it reported as absent. load() rebuilds the queue for
     *                       the next repository from what has been resolved, so both the absent and
     *                       the failed names are offered on.
     */
    private function loadFromRepository(ComposerRepository $repository, array $remaining): MetadataBatch
    {
        $metadata = [];
        $failed = [];
        $stillRemaining = [];

        foreach (array_chunk($remaining, self::CHUNK_SIZE) as $chunk) {
            try {
                $result = $repository->loadPackages(array_fill_keys($chunk, null), self::ALL_STABILITIES, []);
            } catch (\RuntimeException $e) {
                foreach ($chunk as $name) {
                    $failed[$name] = $e->getMessage();
                }
                continue;
            }

            // loadPackages() itself has no native return type, but RepositoryInterface documents
            // it as @phpstan-return array{namesFound: array<string>, packages: array<BasePackage>},
            // which PHPStan resolves through ComposerRepository's inherited signature; grouping
            // still guards each element (AliasPackage vs BasePackage) since that shape says nothing
            // about which concrete package class comes back.
            $namesFound = $result['namesFound'];
            $versionsByName = $this->groupByName($result['packages']);
            unset($result);

            $now = $this->clock->now();
            foreach ($chunk as $name) {
                if (!\in_array($name, $namesFound, true)) {
                    $stillRemaining[] = $name;
                    continue;
                }
                $versions = $versionsByName[$name] ?? [];
                if ($versions === []) {
                    // The repository reported this name as found (it has an entry in at least one
                    // of {name}.json / {name}~dev.json) but every version was filtered out or the
                    // entry was empty, so there is nothing to build a PackageMetadata from.
                    $failed[$name] = 'repository listed the package but returned no versions';
                    continue;
                }
                $metadata[$name] = PackageMetadata::fromPackages($name, $versions, $now);
            }
        }

        return new MetadataBatch($metadata, $stillRemaining, $failed);
    }

    /**
     * @param array<int|string, mixed> $packages
     *
     * @return array<string, list<BasePackage>>
     */
    private function groupByName(array $packages): array
    {
        $byName = [];
        $seen = [];
        foreach ($packages as $package) {
            if ($package instanceof AliasPackage) {
                // loadPackages() returns an AliasPackage built from extra.branch-alias *and*, as a
                // separate entry, the package it aliases (2.10.3
                // src/Composer/Repository/ComposerRepository.php:1348-1352, 2.2.25 :960-964), so
                // unwrapping without deduplicating would count that release twice.
                $package = $package->getAliasOf();
            }
            if (!$package instanceof BasePackage) {
                continue;
            }
            $id = spl_object_id($package);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $byName[$package->getName()][] = $package;
        }

        return $byName;
    }
}
