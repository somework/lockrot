<?php

declare(strict_types=1);

namespace Lockrot\Data\Repository;

use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Repository\ComposerRepository;
use Composer\Repository\RepositoryInterface;
use Lockrot\Clock;
use Lockrot\Data\Packagist\PackageMetadata;

final class RepositoryMetadataLoader implements MetadataLoaderInterface
{
    public const CHUNK_SIZE = 10;

    /** @var list<RepositoryInterface> */
    private array $repositories;
    private Clock $clock;

    /** @param list<RepositoryInterface> $repositories only ComposerRepository instances are queried */
    public function __construct(array $repositories, Clock $clock)
    {
        $this->repositories = $repositories;
        $this->clock = $clock;
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
            $remaining = $this->loadFromRepository($repository, $remaining, $metadata, $failed);
        }

        $notFound = [];
        foreach ($remaining as $name) {
            if (!isset($failed[$name])) {
                $notFound[] = $name;
            }
        }

        return new MetadataBatch($metadata, $notFound, $failed);
    }

    /**
     * @param list<string>                   $remaining
     * @param array<string, PackageMetadata> $metadata   appended to in place
     * @param array<string, string>          $failed     appended to in place
     *
     * @return list<string> names still unresolved after querying this repository
     */
    private function loadFromRepository(ComposerRepository $repository, array $remaining, array &$metadata, array &$failed): array
    {
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
                $metadata[$name] = PackageMetadata::fromPackages($name, $versionsByName[$name] ?? [], $now);
            }
        }

        return $stillRemaining;
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
