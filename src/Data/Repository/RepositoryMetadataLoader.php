<?php

declare(strict_types=1);

namespace Lockrot\Data\Repository;

use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Repository\ComposerRepository;
use Composer\Repository\RepositoryInterface;
use Lockrot\Clock;
use Lockrot\Deadline;

final class RepositoryMetadataLoader implements MetadataLoaderInterface
{
    public const CHUNK_SIZE = 10;

    /**
     * Packagist puts `abandoned` on every version entry of a package, including its tagged
     * releases, so a name with at least one tag never needs its `~dev` file just to see that flag.
     * Pass 1 therefore asks only for these stabilities first; a name Composer reports as found here
     * with at least one version is fully resolved without ever touching {name}~dev.json.
     * Composer\Package\BasePackage::$stabilities is deprecated in 2.10 in favour of the
     * BasePackage::STABILITIES constant, which 2.2 LTS does not have; the individual
     * BasePackage::STABILITY_* constants exist in both (2.10.3 src/Composer/Package/BasePackage.php:37-41,
     * 2.2.25 :37-41), so the map is spelled out from those.
     */
    private const STABLE_STABILITIES = [
        'stable' => BasePackage::STABILITY_STABLE,
        'RC' => BasePackage::STABILITY_RC,
        'beta' => BasePackage::STABILITY_BETA,
        'alpha' => BasePackage::STABILITY_ALPHA,
    ];

    /**
     * Pass 2, queried only for names pass 1 could not resolve — a package with no tagged release
     * at all (e.g. lox/xhprof, wallabag/rulerz). Requesting only `dev` here matters: Composer's own
     * ComposerRepository::loadAsyncPackages() adds `{name}~dev` to the request list whenever `dev`
     * is an acceptable stability (2.10.3 Repository/ComposerRepository.php:1292-1296, 2.2.25
     * :889-893), and skips the non-dev `{name}` file entirely when `dev` is the *only* acceptable
     * stability (2.10.3 :1297-1300, 2.2.25 :894-897) — so this constant alone is what keeps pass 2
     * from re-fetching the stable file pass 1 already asked for.
     */
    private const DEV_ONLY_STABILITIES = [
        'dev' => BasePackage::STABILITY_DEV,
    ];

    /** @var list<RepositoryInterface> */
    private array $repositories;
    private Clock $clock;
    private bool $offline;
    private Deadline $deadline;

    /** @param list<RepositoryInterface> $repositories only ComposerRepository instances are queried */
    public function __construct(array $repositories, Clock $clock, bool $offline = false, ?Deadline $deadline = null)
    {
        $this->repositories = $repositories;
        $this->clock = $clock;
        $this->offline = $offline;
        $this->deadline = $deadline ?? Deadline::never();
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
     * Two passes over this one repository. Pass 1 asks only for tagged-release stabilities; a name
     * with at least one version there is fully resolved — abandonment, replacement, source URL and
     * type all come from a tagged release's own metadata, so nothing further is needed. A name
     * pass 1 could not resolve (absent, or found with an empty version list) goes to pass 2, which
     * asks only for `dev` — Composer then requests just {name}~dev.json, never re-fetching the
     * stable file pass 1 already has an answer for.
     *
     * Before every chunk in either pass, a budget check ({@see Deadline}) can end the whole call
     * early: every name not yet handed to a chunk (the rest of pass 1, or all of pass 2 if pass 1
     * never finished) is marked failed with {@see MetadataLoaderInterface::BUDGET_REASON} and no
     * further chunk is started, in either pass.
     *
     * @param list<string> $remaining names to query against this one repository
     *
     * @return MetadataBatch metadata()/failed() as this one repository answered them; notFound()
     *                       carries the names it reported as absent. load() rebuilds the queue for
     *                       the next repository from what has been resolved, so both the absent and
     *                       the failed names are offered on.
     */
    private function loadFromRepository(ComposerRepository $repository, array $remaining): MetadataBatch
    {
        $pass1 = $this->loadChunked($repository, $remaining, self::STABLE_STABILITIES);
        if ($pass1['budgetExhausted']) {
            // Every name pass 1 could not get to is already in $pass1['failed']; pass 2 must not
            // run at all, per the "stop, break out of both loops" behaviour.
            return new MetadataBatch($pass1['metadata'], $pass1['stillRemaining'], $pass1['failed']);
        }

        $pass2 = $this->loadChunked($repository, $pass1['needDev'], self::DEV_ONLY_STABILITIES);

        // Neither array can share a key with the other: a name only reaches pass 2 via pass 1's
        // needDev list, which is disjoint from pass 1's own metadata/failed.
        return new MetadataBatch(
            $pass1['metadata'] + $pass2['metadata'],
            $pass2['stillRemaining'],
            $pass1['failed'] + $pass2['failed']
        );
    }

    /**
     * Runs one pass of chunked loadPackages() calls against $names. A name pass 1 (stable
     * stabilities) could not resolve — found with no versions, or not found at all — is returned in
     * `needDev` for pass 2 to retry against the dev-only file. Pass 2 has no further pass to hand
     * those two cases on to, so it routes them straight to `failed` / `stillRemaining` instead.
     *
     * @param list<string>                $names
     * @param array<'alpha'|'beta'|'dev'|'RC'|'stable', 0|5|10|15|20> $acceptableStabilities self::STABLE_STABILITIES
     *                                                                                        for pass 1, self::DEV_ONLY_STABILITIES for pass 2
     *
     * @return array{
     *     metadata: array<string, PackageMetadata>,
     *     failed: array<string, string>,
     *     stillRemaining: list<string>,
     *     needDev: list<string>,
     *     budgetExhausted: bool
     * } needDev and stillRemaining are mutually exclusive: pass 1 only ever populates needDev
     *   (stillRemaining stays empty), pass 2 only ever populates stillRemaining (needDev stays
     *   empty)
     */
    private function loadChunked(ComposerRepository $repository, array $names, array $acceptableStabilities): array
    {
        $isDevOnlyPass = self::DEV_ONLY_STABILITIES === $acceptableStabilities;
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

                return [
                    'metadata' => $metadata,
                    'failed' => $failed,
                    'stillRemaining' => $stillRemaining,
                    'needDev' => $needDev,
                    'budgetExhausted' => true,
                ];
            }

            $chunk = array_splice($toChunk, 0, self::CHUNK_SIZE);

            try {
                $result = $repository->loadPackages(array_fill_keys($chunk, null), $acceptableStabilities, []);
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
                    if ($isDevOnlyPass) {
                        $stillRemaining[] = $name;
                    } else {
                        $needDev[] = $name;
                    }
                    continue;
                }
                $versions = $versionsByName[$name] ?? [];
                if ($versions === []) {
                    // The repository reported this name as found (it has an entry in the file
                    // queried this pass) but every version was filtered out or the entry was empty.
                    if ($isDevOnlyPass) {
                        $failed[$name] = 'repository listed the package but returned no versions';
                    } else {
                        $needDev[] = $name;
                    }
                    continue;
                }
                $metadata[$name] = PackageMetadata::fromPackages($name, $versions, $now);
            }
        }

        return [
            'metadata' => $metadata,
            'failed' => $failed,
            'stillRemaining' => $stillRemaining,
            'needDev' => $needDev,
            'budgetExhausted' => false,
        ];
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
