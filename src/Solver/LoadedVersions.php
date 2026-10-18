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
    /** @var array<string, true> */
    private array $asked = [];

    public function __construct(VersionSource $source)
    {
        parent::__construct();
        $this->source = $source;
    }

    /** @return list<string> the names that the pool builder asked for, in order */
    public function asked(): array
    {
        return array_keys($this->asked);
    }

    public function loadPackages(array $packageNameMap, array $acceptableStabilities, array $stabilityFlags, array $alreadyLoaded = [])
    {
        $this->getPackages();
        foreach (array_keys($packageNameMap) as $name) {
            $name = (string) $name;
            if (isset($this->asked[$name])) {
                continue;
            }
            $this->asked[$name] = true;
            foreach ($this->source->versions($name) as $package) {
                $this->packages[] = $package;
            }
            $this->packageMap = null;
        }

        return parent::loadPackages($packageNameMap, $acceptableStabilities, $stabilityFlags, $alreadyLoaded);
    }
}
