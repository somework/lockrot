<?php

declare(strict_types=1);

namespace Lockrot\Lock;

use Composer\Package\AliasPackage;
use Composer\Package\CompleteAliasPackage;
use Composer\Package\CompletePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Repository\LockArrayRepository;
use Lockrot\Exception\ConfigException;
use Lockrot\Json\JsonReader;

/** @internal */
final class LockFile
{
    /** @var array<string, LockedPackage> */
    private array $packages;
    private ?string $contentHash;
    private LockArrayRepository $locked;

    /** @param array<string, LockedPackage> $packages */
    private function __construct(array $packages, ?string $contentHash, LockArrayRepository $locked)
    {
        $this->packages = $packages;
        $this->contentHash = $contentHash;
        $this->locked = $locked;
    }

    public static function fromFile(string $path): self
    {
        return self::fromArray(JsonReader::readObject($path));
    }

    /** @param array<string, mixed> $lock */
    public static function fromArray(array $lock): self
    {
        $packages = [];
        $loader = new ArrayLoader();
        // Composer's Locker loads the lock for an update with these options.
        $lockLoader = new ArrayLoader(null, true);
        $locked = new LockArrayRepository();
        $aliased = [];
        foreach ([['packages', false], ['packages-dev', true]] as [$key, $dev]) {
            $entries = $lock[$key] ?? null;
            $entries = \is_array($entries) ? array_values($entries) : [];
            foreach ($entries as $index => $entry) {
                if (!\is_array($entry)) {
                    throw new ConfigException(\sprintf(
                        'composer.lock entry #%d in %s cannot be loaded: entry must be a JSON object',
                        $index,
                        $key
                    ));
                }
                try {
                    $loaded = $loader->load($entry);
                    $forUpdate = $lockLoader->load($entry);
                } catch (\UnexpectedValueException $e) {
                    throw new ConfigException(\sprintf(
                        'composer.lock entry #%d in %s cannot be loaded: %s',
                        $index,
                        $key,
                        $e->getMessage()
                    ), 0, $e);
                }
                // A package that declares `extra.branch-alias`, or is a `default-branch`, loads as an
                // alias package. Unwrap it, so that the entry's own version, require and source fields
                // are read, and keep the alias version: `composer audit` matches advisories on it.
                $aliasVersions = [];
                if ($loaded instanceof AliasPackage) {
                    $aliasVersions[] = $loaded->getVersion();
                    $loaded = $loaded->getAliasOf();
                }
                // Unreachable: with its default class, ArrayLoader::load() returns a CompletePackage or
                // an alias of one, which the unwrap removes. The check narrows the type for PHPStan.
                if (!$loaded instanceof CompletePackage) {
                    throw new ConfigException(\sprintf(
                        'composer.lock entry #%d in %s cannot be loaded: loader returned %s instead of a CompletePackage',
                        $index,
                        $key,
                        \get_class($loaded)
                    ));
                }
                $package = LockedPackage::fromPackage($loaded, $dev)->withAliasVersions($aliasVersions);
                $packages[$package->name()] = $package;
                $locked->addPackage($forUpdate);
                $aliased[$package->name()] = $forUpdate instanceof AliasPackage ? $forUpdate->getAliasOf() : $forUpdate;
            }
        }
        foreach (\is_array($lock['aliases'] ?? null) ? $lock['aliases'] : [] as $alias) {
            $of = \is_array($alias) && \is_string($alias['package'] ?? null) ? ($aliased[$alias['package']] ?? null) : null;
            if ($of instanceof CompletePackage && \is_string($alias['alias_normalized'] ?? null) && \is_string($alias['alias'] ?? null)) {
                $rootAlias = new CompleteAliasPackage($of, $alias['alias_normalized'], $alias['alias']);
                $rootAlias->setRootPackageAlias(true);
                $locked->addPackage($rootAlias);
            }
        }
        $hash = $lock['content-hash'] ?? null;

        return new self($packages, \is_string($hash) ? $hash : null, $locked);
    }

    public static function empty(): self
    {
        return new self([], null, new LockArrayRepository());
    }

    /**
     * A new lock where each given package replaces the entry of its name or adds one. The
     * install-time path uses it to make a Composer transaction's packages part of the dependency
     * chains ({@see \Lockrot\Composer\InstallTimeSummary}). An entry that the lock already lists keeps
     * its `packages` or `packages-dev` membership. A transaction carries no dev flag, and only the
     * lock knows it.
     *
     * @param list<LockedPackage> $packages
     */
    public function withPackages(array $packages): self
    {
        $merged = $this->packages;
        foreach ($packages as $package) {
            $existing = $merged[$package->name()] ?? null;
            if ($existing !== null && $existing->isDev() !== $package->isDev()) {
                $package = $package->withDev($existing->isDev());
            }
            $merged[$package->name()] = $package;
        }

        return new self($merged, $this->contentHash, $this->locked);
    }

    /** @return list<LockedPackage> */
    public function packages(bool $includeDev): array
    {
        $out = [];
        foreach ($this->packages as $package) {
            if ($includeDev || !$package->isDev()) {
                $out[] = $package;
            }
        }

        return $out;
    }

    public function find(string $name): ?LockedPackage
    {
        return $this->packages[$name] ?? null;
    }

    /**
     * The lock as Composer's Locker::getLockedRepository(true) reads it for an update: `packages`
     * and `packages-dev`, with the root aliases of its `aliases`. {@see withPackages()} keeps the
     * lock's own entries here.
     */
    public function lockedRepository(): LockArrayRepository
    {
        return $this->locked;
    }

    public function contentHash(): ?string
    {
        return $this->contentHash;
    }
}
