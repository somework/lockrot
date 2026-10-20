<?php

declare(strict_types=1);

namespace Lockrot\Solver;

use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Package\Version\StabilityFilter;
use Composer\Repository\ArrayRepository;
use Composer\Semver\CompilingMatcher;
use Composer\Semver\Constraint\Constraint;

/**
 * The remote versions of one solve: Composer's pool builder asks for a name, and this repository
 * takes it from the {@see VersionSource}. A package keeps the repository that loaded it:
 * addPackage() would set this one, and a package refuses a second repository.
 *
 * @internal
 */
final class LoadedVersions extends ArrayRepository
{
    private VersionSource $source;
    /** @var array<string, list<BasePackage>> in load order */
    private array $byName = [];

    public function __construct(VersionSource $source)
    {
        $this->source = $source;
    }

    /**
     * The answer of ArrayRepository::loadPackages(), which reads every package on each call. This
     * one reads the asked names' packages only, and matches a version as ComposerRepository does.
     */
    public function loadPackages(array $packageNameMap, array $acceptableStabilities, array $stabilityFlags, array $alreadyLoaded = [])
    {
        foreach ($this->source->versionsOf(array_keys(array_diff_key($packageNameMap, $this->byName))) as $name => $versions) {
            $this->byName[$name] = $versions;
            foreach ($versions as $package) {
                $this->packages[] = $package;
            }
            $this->packageMap = null;
        }

        $asked = array_intersect_key($this->byName, $packageNameMap);
        $result = [];
        $aliases = [];
        foreach ($asked as $name => $versions) {
            $constraint = $packageNameMap[$name];
            foreach ($versions as $package) {
                if (
                    ($constraint === null || CompilingMatcher::match($constraint, Constraint::OP_EQ, $package->getVersion()))
                    && StabilityFilter::isPackageAcceptable($acceptableStabilities, $stabilityFlags, $package->getNames(), $package->getStability())
                    && !isset($alreadyLoaded[$name][$package->getVersion()])
                ) {
                    $result[spl_object_id($package)] = $package;
                    if ($package instanceof AliasPackage && !isset($result[spl_object_id($package->getAliasOf())])) {
                        $result[spl_object_id($package->getAliasOf())] = $package->getAliasOf();
                    }
                }
                if ($package instanceof AliasPackage) {
                    $aliases[] = $package;
                }
            }
        }
        // An alias of a selected package joins it, as in ArrayRepository, whether or not it matches.
        foreach ($aliases as $alias) {
            if (isset($result[spl_object_id($alias->getAliasOf())])) {
                $result[spl_object_id($alias)] = $alias;
            }
        }

        return ['namesFound' => array_keys(array_filter($asked)), 'packages' => $result];
    }
}
