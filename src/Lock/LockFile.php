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
