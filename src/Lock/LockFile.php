<?php

declare(strict_types=1);

namespace Lockrot\Lock;

use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Package\CompleteAliasPackage;
use Composer\Package\CompletePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Version\VersionParser;
use Composer\Repository\LockArrayRepository;
use Composer\Repository\PlatformRepository;
use Lockrot\Exception\ConfigException;
use Lockrot\Json\JsonReader;
use Lockrot\Output\FormatContext;

/** @internal */
final class LockFile
{
    /** Composer's package name pattern (composer-schema.json, `name`). */
    private const NAME = '{^[a-z0-9](?:[_.-]?[a-z0-9]+)*/[a-z0-9](?:(?:[_.]|-{1,2})?[a-z0-9]+)*$}D';

    /** @var array<string, LockedPackage> */
    private array $packages;
    /** @var list<string> */
    private array $skipped = [];
    private ?string $contentHash;
    private LockArrayRepository $locked;
    /** @var list<string> the names of the `packages-dev` entries, valid or not */
    private array $devNames = [];
    /** @var array<string, string> */
    private array $platform = [];
    /** @var array<string, string> */
    private array $platformDev = [];
    private ?string $platformOverridePhp = null;
    private bool $hasDevKey = false;

    /** @param array<string, LockedPackage> $packages */
    private function __construct(array $packages, ?string $contentHash, LockArrayRepository $locked)
    {
        $this->packages = $packages;
        $this->contentHash = $contentHash;
        $this->locked = $locked;
    }

    /** @param string|null $name the lock's name in messages, its file name unless given */
    public static function fromFile(string $path, ?string $name = null): self
    {
        return self::fromArray(JsonReader::readObject($path), $name ?? basename($path));
    }

    /**
     * A lock loads only when Composer can install it and it keeps lockrot's own rules. `packages` is
     * a list, `packages-dev` is null or a list, and each `name` is a string. No name is
     * listed twice, case ignored, because a finding needs one entry per package. A platform map
     * follows Composer: the later of two keys that differ in case wins.
     *
     * @param array<string, mixed> $lock
     * @param string               $name the lock's name in messages
     *
     * @throws ConfigException when the lock breaks one of these rules
     */
    public static function fromArray(array $lock, string $name = FormatContext::DEFAULT_LOCK_NAME): self
    {
        self::checkPlatformLinks(['platform' => $lock['platform'] ?? null, 'platform-dev' => $lock['platform-dev'] ?? null], $name);
        $overridePhp = self::overridePhpOf($lock['platform-overrides'] ?? [], $name);
        $packages = [];
        $loader = new ArrayLoader();
        // Composer's Locker loads the lock for an update with these options.
        $lockLoader = new ArrayLoader(null, true);
        $locked = new LockArrayRepository();
        $aliased = [];
        $skipped = [];
        $devNames = [];
        $seen = [];
        foreach ([['packages', false], ['packages-dev', true]] as [$key, $dev]) {
            foreach (self::entries($lock[$key] ?? null, $key, $name) as $index => $entry) {
                [$loaded, $forUpdate, $aliasVersions] = self::load($entry, \sprintf('%s entry #%d in %s cannot be loaded: ', $name, $index, $key), $loader, $lockLoader);
                $id = PackageName::key($loaded->getName());
                if (isset($seen[$id])) {
                    throw new ConfigException(\sprintf('%s lists %s twice: %s and entry #%d in %s', $name, $id, $seen[$id], $index, $key));
                }
                $seen[$id] = \sprintf('entry #%d in %s', $index, $key);
                $locked->addPackage($forUpdate);
                if ($dev) {
                    $devNames[] = $loaded->getName();
                }
                if (preg_match(self::NAME, $loaded->getName()) !== 1) {
                    // A name from data can read as a command option: it reaches no finding and no command.
                    $skipped[] = $loaded->getName();
                    continue;
                }
                $package = LockedPackage::fromPackage($loaded, $dev)->withAliasVersions($aliasVersions);
                $packages[$package->name()] = $package;
                $aliased[$package->name()] = $forUpdate instanceof AliasPackage ? $forUpdate->getAliasOf() : $forUpdate;
            }
        }
        foreach (self::aliases($lock['aliases'] ?? null, $seen, $name) as $alias) {
            $of = $aliased[$alias['package']] ?? null;
            if ($of instanceof CompletePackage) {
                $rootAlias = new CompleteAliasPackage($of, $alias['alias_normalized'], $alias['alias']);
                $rootAlias->setRootPackageAlias(true);
                $locked->addPackage($rootAlias);
            }
        }
        $hash = $lock['content-hash'] ?? null;
        $file = new self($packages, \is_string($hash) ? $hash : null, $locked);
        $file->skipped = $skipped;
        $file->devNames = $devNames;
        $file->platform = self::stringValues($lock['platform'] ?? null);
        $file->platformDev = self::stringValues($lock['platform-dev'] ?? null);
        $file->platformOverridePhp = $overridePhp;
        // Composer's Locker refuses an install with require-dev when the key is missing.
        $file->hasDevKey = isset($lock['packages-dev']);

        return $file;
    }

    /**
     * @param mixed $entries
     *
     * @return list<mixed>
     */
    private static function entries($entries, string $key, string $name): array
    {
        // Composer's Locker reads `packages` without a check, and `packages-dev` only when it is set.
        if ($entries === null && $key === 'packages-dev') {
            return [];
        }
        if (!\is_array($entries) || ($entries !== [] && !JsonReader::isList($entries))) {
            throw new ConfigException(\sprintf('%s: %s must be a list of package entries', $name, $key));
        }

        return $entries;
    }

    /**
     * @param mixed $entry
     *
     * @return array{0: CompletePackage, 1: BasePackage, 2: list<string>} the package, the package as an update loads it, its alias versions
     */
    private static function load($entry, string $refusal, ArrayLoader $loader, ArrayLoader $lockLoader): array
    {
        if (!\is_array($entry)) {
            throw new ConfigException($refusal.'entry must be a JSON object');
        }
        // Composer's loader takes the name as given: a number reads as a name on one Composer and
        // is a TypeError on another.
        if (isset($entry['name']) && !\is_string($entry['name'])) {
            throw new ConfigException($refusal.'"name" must be a string');
        }
        try {
            $loaded = $loader->load($entry);
            $forUpdate = $lockLoader->load($entry);
        } catch (\Throwable $e) {
            // ArrayLoader changes no state, so any failure means that the entry cannot be loaded.
            throw new ConfigException($refusal.$e->getMessage(), 0, $e);
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
            throw new ConfigException($refusal.'loader returned '.\get_class($loaded).' instead of a CompletePackage');
        }

        return [$loaded, $forUpdate, $aliasVersions];
    }

    /** @param array{platform: mixed, platform-dev: mixed} $platform */
    private static function checkPlatformLinks(array $platform, string $name): void
    {
        // Composer's Locker passes `platform` and `platform-dev` to parseLinks() when they are not empty.
        $parser = new VersionParser();
        foreach ($platform as $key => $links) {
            if (empty($links)) {
                continue;
            }
            if (!\is_array($links)) {
                throw new ConfigException(\sprintf('%s: %s must be an object', $name, $key));
            }
            foreach (array_filter($links, 'is_string') as $constraint) {
                // Composer's ArrayLoader::createLink() replaces `self.version` with the version of the root.
                if ($constraint === 'self.version') {
                    continue;
                }
                try {
                    $parser->parseConstraints($constraint);
                } catch (\UnexpectedValueException $e) {
                    throw new ConfigException(\sprintf('%s: %s cannot be loaded: %s', $name, $key, $e->getMessage()), 0, $e);
                }
            }
        }
    }

    /**
     * `platform-overrides.php` as Composer's PlatformRepository reads it: names in lower case, the
     * later of two keys wins, and each value is a version or `false`. Composer's Locker passes
     * `platform-overrides` to PlatformRepository when it is set.
     *
     * @param mixed $overrides
     */
    private static function overridePhpOf($overrides, string $name): ?string
    {
        if (!\is_array($overrides)) {
            throw new ConfigException($name.': platform-overrides must be an object');
        }
        try {
            // @phpstan-ignore argument.type (the constructor's own value check is the refusal)
            new PlatformRepository([], $overrides);
        } catch (\Throwable $e) {
            throw new ConfigException($name.': platform-overrides cannot be loaded: '.$e->getMessage(), 0, $e);
        }
        $byName = [];
        foreach ($overrides as $override => $version) {
            $byName[strtolower((string) $override)] = $version;
        }
        // PlatformRepository::initialize() refuses these names and versions when Composer installs the lock.
        $parser = new VersionParser();
        foreach ($byName as $override => $version) {
            if (!PlatformRepository::isPlatformPackage($override)) {
                throw new ConfigException(\sprintf('%s: platform-overrides cannot be loaded: %s is no platform package', $name, $override));
            }
            try {
                if (\is_string($version)) {
                    $parser->normalize($version);
                }
            } catch (\UnexpectedValueException $e) {
                throw new ConfigException($name.': platform-overrides cannot be loaded: '.$e->getMessage(), 0, $e);
            }
        }

        $php = $byName['php'] ?? null;

        return \is_string($php) ? $php : null;
    }

    /**
     * The `aliases` entries of locked packages. Composer's Locker skips an entry whose package is
     * not locked, and reads no other field of it.
     *
     * @param mixed               $aliases
     * @param array<string, string> $locked the lower-case names of the locked packages as keys
     *
     * @return list<array{package: string, alias: string, alias_normalized: string}>
     */
    private static function aliases($aliases, array $locked, string $name): array
    {
        if ($aliases === null) {
            return [];
        }
        $refusal = $name.': aliases must hold objects with the string package';
        if (!\is_array($aliases)) {
            throw new ConfigException($refusal);
        }
        $out = [];
        foreach ($aliases as $alias) {
            if (!\is_array($alias) || !\is_string($alias['package'] ?? null)) {
                throw new ConfigException($refusal);
            }
            if (!isset($locked[$alias['package']])) {
                continue;
            }
            if (!\is_string($alias['alias'] ?? null) || !\is_string($alias['alias_normalized'] ?? null)) {
                throw new ConfigException(\sprintf('%s: aliases entry for %s must have the strings alias and alias_normalized', $name, $alias['package']));
            }
            $out[] = ['package' => $alias['package'], 'alias' => $alias['alias'], 'alias_normalized' => $alias['alias_normalized']];
        }

        return $out;
    }

    /**
     * @param mixed $links
     *
     * @return array<string, string> lowercased name => string value
     */
    private static function stringValues($links): array
    {
        $out = [];
        foreach (\is_array($links) ? $links : [] as $name => $value) {
            if (\is_string($name) && \is_string($value)) {
                $out[strtolower($name)] = $value;
            }
        }

        return $out;
    }

    /** @return list<string> the names of the entries that are no Composer package name, in lock order */
    public function skippedNames(): array
    {
        return $this->skipped;
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

        $file = new self($merged, $this->contentHash, $this->locked);
        $file->skipped = $this->skipped;
        $file->devNames = $this->devNames;
        $file->platform = $this->platform;
        $file->platformDev = $this->platformDev;
        $file->platformOverridePhp = $this->platformOverridePhp;
        $file->hasDevKey = $this->hasDevKey;

        return $file;
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

    /** Whether the {@see lockedRepository()} entry of this name is in `packages-dev`. */
    public function isDevEntry(string $name): bool
    {
        return \in_array($name, $this->devNames, true);
    }

    /** @return array<string, string> the lock's `platform`: the root's platform requirements of `require`, by lowercased name */
    public function platform(): array
    {
        return $this->platform;
    }

    /** @return array<string, string> the lock's `platform-dev`: those of `require-dev` */
    public function platformDev(): array
    {
        return $this->platformDev;
    }

    /** The lock's `platform-overrides.php`: the PHP that `composer install` checks the lock at. */
    public function platformOverridePhp(): ?string
    {
        return $this->platformOverridePhp;
    }

    public function hasDevKey(): bool
    {
        return $this->hasDevKey;
    }
}
