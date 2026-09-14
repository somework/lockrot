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
     * Composer's ComposerRepository::asyncFetchFile() turns a "network disabled" transport error
     * into a synthetic 404 whenever it has no cached copy to fall back on (no last-modified date to
     * revalidate against) — so a name that comes back genuinely `notFound` while offline is
     * indistinguishable from one that simply was never cached. Since that can't be trusted as "this
     * package really doesn't exist", every such name is reported failed instead, not notFound.
     */
    public const OFFLINE_NOT_FOUND_REASON = 'offline: not present in Composer\'s cache';

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

    /** @param list<string> $names */
    public function load(array $names): MetadataBatch
    {
        $remaining = array_values(array_unique($names));
        $metadata = [];
        $failed = [];

        foreach ($this->repositories as $repository) {
            if ($remaining === [] || !$repository instanceof ComposerRepository) {
                continue;
            }
            $batch = $this->loadFromRepository($repository, $remaining);
            $metadata += $batch->metadata();
            $failed += $batch->failed();
            $remaining = $batch->notFound();
        }

        $notFound = [];
        foreach ($remaining as $name) {
            if (isset($failed[$name])) {
                continue;
            }
            if ($this->offline) {
                $failed[$name] = self::OFFLINE_NOT_FOUND_REASON;
                continue;
            }
            $notFound[] = $name;
        }

        return new MetadataBatch($metadata, $notFound, $failed);
    }

    /**
     * @param list<string> $remaining names to query against this one repository
     *
     * @return MetadataBatch metadata()/failed() resolved in this pass; notFound() carries the
     *                       names still unresolved (and not failed) to hand to the next repository
     */
    private function loadFromRepository(ComposerRepository $repository, array $remaining): MetadataBatch
    {
        $metadata = [];
        $failed = [];
        $stillRemaining = [];

        foreach (array_chunk($remaining, self::CHUNK_SIZE) as $chunk) {
            try {
                $result = $repository->loadPackages(array_fill_keys($chunk, null), BasePackage::$stabilities, []);
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
        foreach ($packages as $package) {
            if ($package instanceof AliasPackage) {
                $package = $package->getAliasOf();
            }
            if (!$package instanceof BasePackage) {
                continue;
            }
            $byName[$package->getName()][] = $package;
        }

        return $byName;
    }
}
