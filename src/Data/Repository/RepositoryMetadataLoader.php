<?php

declare(strict_types=1);

namespace Lockrot\Data\Repository;

use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Downloader\TransportException;
use Composer\Repository\ComposerRepository;
use Composer\Repository\FilterRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\RepositorySecurityException;
use Lockrot\Clock;
use Lockrot\Deadline;
use Seld\JsonLint\ParsingException;

/** @internal */
final class RepositoryMetadataLoader implements MetadataLoaderInterface
{
    public const CHUNK_SIZE = 10;

    /**
     * Packagist puts `abandoned` on every tagged release, so a name with one tag does not need its
     * `~dev` file for that flag. The map uses the single BasePackage::STABILITY_* constants because
     * every supported Composer has them, and BasePackage::STABILITIES does not exist in all.
     */
    private const STABLE_STABILITIES = [
        'stable' => BasePackage::STABILITY_STABLE,
        'RC' => BasePackage::STABILITY_RC,
        'beta' => BasePackage::STABILITY_BETA,
        'alpha' => BasePackage::STABILITY_ALPHA,
    ];

    /**
     * Pass 2 asks for `dev` only. ComposerRepository::loadAsyncPackages() skips the non-dev `{name}`
     * file when `dev` is the only acceptable stability, so pass 2 does not fetch it again.
     */
    private const DEV_ONLY_STABILITIES = [
        'dev' => BasePackage::STABILITY_DEV,
    ];

    /** @var list<RepositoryInterface> */
    private array $repositories;
    private Clock $clock;
    private bool $offline;
    private Deadline $deadline;

    /** @param list<RepositoryInterface> $repositories a ComposerRepository is queried, also through the FilterRepository that wraps it */
    public function __construct(array $repositories, Clock $clock, bool $offline = false, ?Deadline $deadline = null)
    {
        $this->repositories = $repositories;
        $this->clock = $clock;
        $this->offline = $offline;
        $this->deadline = $deadline ?? Deadline::never();
    }

    /**
     * The first canonical repository, in configured order, that resolves a name wins. A repository
     * with `only` or `exclude` is asked only for the names that its filter admits. A name that a
     * canonical repository fails on fails with that reason, and no later repository is asked for it.
     * A repository with `canonical: false` answers a name only when no later repository resolves it,
     * and its failure leaves the name to the later ones.
     *
     * @param array<string, ?string> $installedByName
     */
    public function load(array $installedByName): MetadataBatch
    {
        $remaining = array_keys($installedByName);
        $metadata = [];
        $notCanonical = [];
        $reasons = [];
        $failed = [];

        foreach ($this->repositories as $repository) {
            $filter = $repository instanceof FilterRepository ? $repository : null;
            $inner = $filter === null ? $repository : $filter->getRepository();
            if ($remaining === [] || !$inner instanceof ComposerRepository) {
                continue;
            }
            $canonical = $filter === null || self::isCanonical($filter);
            $asked = $filter === null ? $remaining : self::kept($remaining, static fn (string $name): bool => self::admits($filter, $name));
            $batch = $this->loadFromRepository($inner, $asked, $installedByName);
            $url = $inner->getRepoConfig()['url'] ?? null;
            foreach ($batch->metadata() as $name => $meta) {
                $meta = $meta->withComposerRepositoryUrl(\is_string($url) ? $url : null);
                if ($canonical) {
                    $metadata[$name] = $meta;
                } else {
                    $notCanonical[$name] ??= $meta;
                }
            }
            foreach ($batch->failed() as $name => $reason) {
                if ($canonical) {
                    $failed[$name] = $reason;
                } else {
                    $reasons[$name] = $reason;
                }
            }
            $remaining = self::kept($remaining, static fn (string $name): bool => !isset($metadata[$name]) && !isset($failed[$name]));
        }

        $notFound = [];
        foreach ($remaining as $name) {
            if (isset($notCanonical[$name])) {
                $metadata[$name] = $notCanonical[$name];
                continue;
            }
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
     * @param list<string> $names
     * @param callable(string): bool $keep
     *
     * @return list<string>
     */
    private static function kept(array $names, callable $keep): array
    {
        $kept = [];
        foreach ($names as $name) {
            if ($keep($name)) {
                $kept[] = $name;
            }
        }

        return $kept;
    }

    /**
     * RepositoryManager::createRepository() moves `only`, `exclude` and `canonical` out of the
     * repository config into FilterRepository's private state, which has no getter.
     */
    private static function admits(FilterRepository $repository, string $name): bool
    {
        return \Closure::bind(fn (): bool => $this->isAllowed($name), $repository, FilterRepository::class)();
    }

    private static function isCanonical(FilterRepository $repository): bool
    {
        return \Closure::bind(fn (): bool => $this->canonical, $repository, FilterRepository::class)();
    }

    /**
     * Pass 1 asks for tagged-release stabilities, and a name with a version there is resolved: a
     * tagged release holds abandonment, replacement, repository URL and type. Pass 2 asks for `dev`
     * only, for the names that pass 1 could not resolve. Before every chunk, an expired
     * {@see Deadline} marks each name that no chunk received as failed with
     * {@see MetadataLoaderInterface::BUDGET_REASON} and starts no further chunk in either pass.
     *
     * @param list<string>           $remaining
     * @param array<string, ?string> $installedByName
     *
     * @return MetadataBatch notFound() carries the names that this repository reported as absent
     */
    private function loadFromRepository(ComposerRepository $repository, array $remaining, array $installedByName): MetadataBatch
    {
        $pass1 = $this->loadChunked($repository, $remaining, self::STABLE_STABILITIES, false, $installedByName);
        if ($pass1->budgetExhausted()) {
            // Pass 2 must not run. A name that pass 1 reached but sent to needDev() never had its dev
            // file requested, so it fails with BUDGET_REASON instead of surfacing as notFound().
            $failed = $pass1->failed();
            foreach ($pass1->needDev() as $name) {
                $failed[$name] = MetadataLoaderInterface::BUDGET_REASON;
            }

            return new MetadataBatch($pass1->metadata(), $pass1->stillRemaining(), $failed);
        }

        $pass2 = $this->loadChunked($repository, $pass1->needDev(), self::DEV_ONLY_STABILITIES, true, $installedByName);

        // The unions are safe: a name reaches pass 2 only through needDev(), which is disjoint from
        // the metadata and the failed names of pass 1.
        return new MetadataBatch(
            $pass1->metadata() + $pass2->metadata(),
            $pass2->stillRemaining(),
            $pass1->failed() + $pass2->failed()
        );
    }

    /**
     * Runs one pass of chunked loadPackages() calls. Pass 1 returns a name that it could not
     * resolve, found with no versions or not found, in `needDev`. Pass 2 has no later pass, so it
     * routes that name to `failed` or `stillRemaining`. A result never fills both `needDev` and
     * `stillRemaining`.
     *
     * @param list<string>                $names
     * @param array<'alpha'|'beta'|'dev'|'RC'|'stable', 0|5|10|15|20> $acceptableStabilities
     * @param array<string, ?string>      $installedByName
     */
    private function loadChunked(ComposerRepository $repository, array $names, array $acceptableStabilities, bool $isDevOnlyPass, array $installedByName): ChunkPassResult
    {
        $metadata = [];
        $failed = [];
        $stillRemaining = [];
        $needDev = [];
        $toChunk = $names;

        while ($toChunk !== []) {
            if ($this->deadline->isPast()) {
                foreach ($toChunk as $name) {
                    $failed[$name] = MetadataLoaderInterface::BUDGET_REASON;
                }

                return new ChunkPassResult($metadata, $failed, $stillRemaining, $needDev, true);
            }

            $chunk = array_splice($toChunk, 0, self::CHUNK_SIZE);

            try {
                $result = $repository->loadPackages(array_fill_keys($chunk, null), $acceptableStabilities, []);
            } catch (\RuntimeException|ParsingException|RepositorySecurityException|\LogicException $e) {
                // ComposerRepository::fetchFile() refuses an answer with these, none of them a RuntimeException.
                // A ParsingException is an answer that does not parse: a proxy page instead of the file.
                // Composer cancels a request with 499 when the network is disabled, and an offline run
                // that lacks the root packages.json in the cache gets that, not a cached answer.
                $reason = $this->offline && $e instanceof TransportException && $e->getStatusCode() === 499
                    ? MetadataLoaderInterface::OFFLINE_NOT_FOUND_REASON
                    : RepositoryUrl::inText($e->getMessage());
                foreach ($chunk as $name) {
                    $failed[$name] = $reason;
                }
                continue;
            }

            // loadPackages() has no native return type. Its @phpstan-return shape says nothing about
            // the concrete package class, so groupByName() checks each element.
            $namesFound = $result['namesFound'];
            $versionsByName = $this->groupByName($result['packages']);
            unset($result);

            $now = $this->clock->now();
            foreach ($chunk as $name) {
                if (!\in_array($name, $namesFound, true)) {
                    if ($isDevOnlyPass) {
                        $stillRemaining[] = $name;
                    } else {
                        $needDev[] = $name;
                    }
                    continue;
                }
                $versions = $versionsByName[$name] ?? [];
                if ($versions === []) {
                    // Found, but every version was filtered out or the entry was empty.
                    if ($isDevOnlyPass) {
                        $failed[$name] = MetadataLoaderInterface::NO_VERSIONS_REASON;
                    } else {
                        $needDev[] = $name;
                    }
                    continue;
                }
                $metadata[$name] = PackageMetadata::fromPackages($name, $versions, $now, $installedByName[$name] ?? null);
            }
        }

        return new ChunkPassResult($metadata, $failed, $stillRemaining, $needDev, false);
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
                // loadPackages() returns an AliasPackage built from extra.branch-alias and, as a
                // separate entry, the package it aliases. Unwrapping without deduplicating counts
                // that release twice.
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
