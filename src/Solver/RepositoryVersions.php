<?php

declare(strict_types=1);

namespace Lockrot\Solver;

use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Package\CompleteAliasPackage;
use Composer\Package\CompletePackage;
use Composer\Package\Version\StabilityFilter;
use Composer\Repository\ComposerRepository;
use Composer\Repository\RepositoryInterface;
use Lockrot\Deadline;

/**
 * Loads a name's versions from the project's Composer repositories: the tagged stabilities first,
 * then `dev` for a name with no tagged release or one that the root admits at `dev`, as Composer's
 * repository loads a name's `~dev` file. Composer serves a file
 * that this process already fetched from its cache, so a name that the run read costs no request.
 * The first repository that lists a name wins, as a canonical repository shadows the later ones.
 *
 * @internal
 */
final class RepositoryVersions implements VersionSource
{
    private const STABLE = [
        'stable' => BasePackage::STABILITY_STABLE,
        'RC' => BasePackage::STABILITY_RC,
        'beta' => BasePackage::STABILITY_BETA,
        'alpha' => BasePackage::STABILITY_ALPHA,
    ];
    private const DEV = ['dev' => BasePackage::STABILITY_DEV];

    /** @var array<ComposerRepository> */
    private array $repositories;
    private bool $offline;
    private Deadline $deadline;
    /** @var array<string, list<BasePackage>> */
    private array $loaded = [];
    /** @var array<string, string> */
    private array $failures = [];
    /** @var array<string, null> the names whose every version loaded: a failed request keeps them */
    private array $complete = [];
    /** @var array<key-of<BasePackage::STABILITIES>, BasePackage::STABILITY_*> */
    private array $acceptable;
    /** @var array<string, BasePackage::STABILITY_*> */
    private array $stabilityFlags;

    /**
     * @param list<RepositoryInterface> $repositories              only ComposerRepository instances are asked
     * @param array<string, array<BasePackage>> $fromLock the lock's packages of each name that a path, VCS or package repository serves: the lock's version is their only version
     * @param string                    $minimumStability          the root's `minimum-stability`
     * @param array<string, BasePackage::STABILITY_*> $stabilityFlags the root's stability flags, by name
     */
    public function __construct(array $repositories, array $fromLock, bool $offline, Deadline $deadline, string $minimumStability = 'stable', array $stabilityFlags = [])
    {
        $floor = BasePackage::$stabilities[$minimumStability] ?? BasePackage::STABILITY_STABLE;
        $this->acceptable = array_filter(BasePackage::$stabilities, static fn (int $value): bool => $value <= $floor);
        $this->stabilityFlags = $stabilityFlags;
        $this->repositories = array_filter($repositories, static fn (RepositoryInterface $repository): bool => $repository instanceof ComposerRepository);
        foreach ($fromLock as $name => $packages) {
            $this->loaded[$name] = self::copies($packages);
        }
        $this->offline = $offline;
        $this->deadline = $deadline;
    }

    public function versions(string $name): array
    {
        return $this->versionsOf([$name])[$name];
    }

    public function versionsOf(array $names): array
    {
        $missing = array_filter($names, fn (string $name): bool => !isset($this->loaded[$name]));
        if ($missing !== []) {
            $this->load($missing);
        }
        $versions = [];
        foreach ($names as $name) {
            $versions[$name] = $this->loaded[$name];
        }

        return $versions;
    }

    public function failure(string $name): ?string
    {
        return $this->failures[$name] ?? null;
    }

    /**
     * Asks each repository for the names together, so Composer sends the requests side by side.
     * One failed request fails them all: the names that it leaves are loaded one by one.
     *
     * @param array<string> $names
     */
    private function load(array $names): void
    {
        $pending = array_fill_keys($names, null);
        $this->loaded += array_fill_keys($names, []);

        try {
            $this->loadTogether($pending);
        } catch (\Exception $e) {
            // A transport failure or an answer that does not parse (Seld\JsonLint\ParsingException).
            // A name with versions still to load, such as an admitted name with its tags only, loads again alone.
            foreach (array_keys($pending) as $name) {
                if (!\array_key_exists($name, $this->complete)) {
                    $this->loaded[$name] = $this->loadOne($name);
                }
            }
        }
    }

    /** @param array<string, null> $pending */
    private function loadTogether(array $pending): void
    {
        if ($this->deadline->isPast()) {
            $this->failures += array_fill_keys(array_keys($pending), self::BUDGET);

            return;
        }
        foreach ($this->repositories as $repository) {
            $tagged = [];
            foreach ([self::STABLE, self::DEV] as $stabilities) {
                $asked = $pending + $tagged;
                if ($asked === []) {
                    return;
                }
                $result = $repository->loadPackages($asked, $stabilities, []);
                foreach ($result['packages'] as $package) {
                    if ($package instanceof BasePackage && \array_key_exists($package->getName(), $asked)) {
                        $this->loaded[$package->getName()][] = $package;
                    }
                }
                $listed = array_intersect_key($pending, array_filter($this->loaded));
                $pending = array_diff_key($pending, $listed);
                if ($stabilities === self::DEV) {
                    $this->complete += $tagged + $listed;
                    foreach (array_keys($tagged) as $name) {
                        $this->failOfflineWithoutDevFile($name, $result);
                    }
                } else {
                    $tagged = array_filter($listed, fn (string $name): bool => $this->admitsDev($name), \ARRAY_FILTER_USE_KEY);
                    $this->complete += array_diff_key($listed, $tagged);
                }
            }
        }
        if ($this->offline) {
            $this->failures += array_fill_keys(array_keys($pending), self::OFFLINE);
        }
    }

    /** @return list<BasePackage> */
    private function loadOne(string $name): array
    {
        if ($this->deadline->isPast()) {
            $this->failures[$name] = self::BUDGET;

            return [];
        }
        $failed = false;
        foreach ($this->repositories as $repository) {
            $versions = [];
            try {
                $versions = self::named($repository->loadPackages([$name => null], self::STABLE, []), $name);
                if ($versions === [] || $this->admitsDev($name)) {
                    $dev = $repository->loadPackages([$name => null], self::DEV, []);
                    if ($versions !== [] && $this->failOfflineWithoutDevFile($name, $dev)) {
                        return [];
                    }
                    $versions = array_merge($versions, self::named($dev, $name));
                }
            } catch (\Exception $e) {
                $failed = true;
                // The first repository that lists the name wins: its tags without its dev versions are no answer.
                if ($versions !== []) {
                    break;
                }
                continue;
            }
            if ($versions !== []) {
                return $versions;
            }
        }
        if ($failed) {
            $this->failures[$name] = self::FAILED;
        } elseif ($this->offline) {
            $this->failures[$name] = self::OFFLINE;
        }

        return [];
    }

    /**
     * @param array{packages: array<BasePackage>} $result a repository's answer
     *
     * @return list<BasePackage>
     */
    private static function named(array $result, string $name): array
    {
        return array_values(array_filter($result['packages'], static fn (BasePackage $package): bool => $package->getName() === $name));
    }

    /**
     * Offline, Composer reads a `~dev` file that its cache lacks as a 404, and its answer leaves the
     * name out of `namesFound`. The tags alone are then no answer, so the name is OFFLINE
     * (docs/verdicts.md#the-move-check).
     *
     * @param array{namesFound: array<string>} $result the dev answer of the repository that lists the name's tags
     */
    private function failOfflineWithoutDevFile(string $name, array $result): bool
    {
        if (!$this->offline || \in_array($name, $result['namesFound'], true)) {
            return false;
        }
        $this->failures[$name] = self::OFFLINE;
        $this->loaded[$name] = [];

        return true;
    }

    /**
     * Copies of the packages without their root aliases, which Composer's pool builder adds from
     * the root. A package keeps the repository that loaded it, and the pool builder never loads a
     * package of the lock's repository.
     *
     * @param array<BasePackage> $packages
     *
     * @return list<BasePackage>
     */
    private static function copies(array $packages): array
    {
        $copies = [];
        $bases = [];
        foreach ($packages as $package) {
            if (!$package instanceof AliasPackage) {
                $copy = clone $package;
                $bases[spl_object_id($package)] = $copy;
                $copies[] = $copy;
            }
        }
        foreach ($packages as $package) {
            $base = $package instanceof AliasPackage && !$package->isRootPackageAlias() ? ($bases[spl_object_id($package->getAliasOf())] ?? null) : null;
            if ($base instanceof CompletePackage) {
                $copies[] = new CompleteAliasPackage($base, $package->getVersion(), $package->getPrettyVersion());
            }
        }

        return $copies;
    }

    private function admitsDev(string $name): bool
    {
        return StabilityFilter::isPackageAcceptable($this->acceptable, $this->stabilityFlags, [$name], 'dev');
    }
}
