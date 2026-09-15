<?php

declare(strict_types=1);

namespace Lockrot\Lock;

use Composer\Package\AliasPackage;
use Composer\Package\CompletePackage;
use Composer\Package\Loader\ArrayLoader;
use Lockrot\Exception\ConfigException;
use Lockrot\Json\JsonReader;

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
                // A package declaring extra.branch-alias (e.g. dev-master aliased to 1.0.x-dev)
                // loads as a CompleteAliasPackage; unwrap it to the underlying CompletePackage so
                // the locked entry's own version/require/source fields are what gets read.
                if ($loaded instanceof AliasPackage) {
                    $loaded = $loaded->getAliasOf();
                }
                // Unreachable with ArrayLoader::load()'s default $class arg (it returns CompletePackage,
                // or the CompleteAliasPackage already unwrapped above); this narrows the type for PHPStan.
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

    /** A lock with no packages and no content hash — the base {@see withPackages()} builds onto. */
    public static function empty(): self
    {
        return new self([], null);
    }

    /**
     * A lock built from packages already in memory rather than from a file on disk — the
     * install-time path uses it as the dependency-chain source when the project has no
     * composer.lock yet (the very first `composer require` in a fresh directory). There is no
     * content hash to carry, since nothing was read from a lock file.
     *
     * @param list<LockedPackage> $packages
     */
    public static function fromPackages(array $packages): self
    {
        return self::empty()->withPackages($packages);
    }

    /**
     * A new lock where each given package replaces (by name) or adds to this one's entries; every
     * other entry, and this instance itself, is left unchanged. The install-time path uses this to
     * make a Composer transaction's own packages part of the dependency-chain source regardless of
     * whether a lock existed on disk or was stale — see {@see \Lockrot\Composer\InstallTimeSummary}.
     *
     * @param list<LockedPackage> $packages
     */
    public function withPackages(array $packages): self
    {
        $merged = $this->packages;
        foreach ($packages as $package) {
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
