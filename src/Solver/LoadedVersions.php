<?php

declare(strict_types=1);

namespace Lockrot\Solver;

use Composer\Repository\ArrayRepository;

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
    /** @var array<string, string> */
    private array $asked = [];

    public function __construct(VersionSource $source)
    {
        $this->source = $source;
    }

    public function loadPackages(array $packageNameMap, array $acceptableStabilities, array $stabilityFlags, array $alreadyLoaded = [])
    {
        foreach (array_keys($packageNameMap) as $name) {
            if (isset($this->asked[$name])) {
                continue;
            }
            $this->asked[$name] = $name;
            foreach ($this->source->versions($name) as $package) {
                $this->packages[] = $package;
            }
            $this->packageMap = null;
        }

        return parent::loadPackages($packageNameMap, $acceptableStabilities, $stabilityFlags, $alreadyLoaded);
    }
}
