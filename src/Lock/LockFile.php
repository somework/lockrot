<?php

declare(strict_types=1);

namespace Lockrot\Lock;

use Composer\Package\AliasPackage;
use Composer\Package\CompletePackage;
use Composer\Package\Loader\ArrayLoader;
use Lockrot\Exception\ConfigException;
use Lockrot\Json\JsonReader;

/** @internal */
final class LockFile
{
    /** @var array<string, LockedPackage> */
    private array $packages;
    private ?string $contentHash;

    /** @param array<string, LockedPackage> $packages */
    private function __construct(array $packages, ?string $contentHash)
    {
        $this->packages = $packages;
        $this->contentHash = $contentHash;
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
                } catch (\UnexpectedValueException $e) {
                    throw new ConfigException(\sprintf(
                        'composer.lock entry #%d in %s cannot be loaded: %s',
                        $index,
                        $key,
                        $e->getMessage()
                    ), 0, $e);
                }
                // A package that declares extra.branch-alias loads as an alias package. Unwrap it, so
                // that the entry's own version, require and source fields are read.
                if ($loaded instanceof AliasPackage) {
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
                $package = LockedPackage::fromPackage($loaded, $dev);
                $packages[$package->name()] = $package;
            }
        }
        $hash = $lock['content-hash'] ?? null;

        return new self($packages, \is_string($hash) ? $hash : null);
    }

    public static function empty(): self
    {
        return new self([], null);
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

        return new self($merged, $this->contentHash);
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

    public function contentHash(): ?string
    {
        return $this->contentHash;
    }
}
