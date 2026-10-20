<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Composer\Downloader\TransportException;
use Composer\Package\BasePackage;
use Composer\Repository\ComposerRepository;

/**
 * A Composer repository that serves the given versions by stability and records each request: its
 * names and its first stability. A request that names a failing name throws, as a failed download
 * does, after the callback runs. With $everyName, an answer holds the versions of every name, as a
 * repository that answers with the packages that provide or replace a name.
 */
final class RecordingComposerRepository extends ComposerRepository
{
    /** @var list<array{list<string>, string}> */
    public array $asked = [];
    /** @var list<BasePackage> */
    private array $served;
    /** @var list<string> */
    private array $failing;
    private ?\Closure $onFailure;
    private bool $everyName;

    /**
     * @param list<BasePackage>     $served
     * @param list<string>          $failing
     * @param ?\Closure(): void     $onFailure
     */
    public function __construct(array $served, array $failing = [], ?\Closure $onFailure = null, bool $everyName = false)
    {
        $this->served = $served;
        $this->failing = $failing;
        $this->onFailure = $onFailure;
        $this->everyName = $everyName;
    }

    public function loadPackages(array $packageNameMap, array $acceptableStabilities, array $stabilityFlags, array $alreadyLoaded = []): array
    {
        $this->asked[] = [array_map('strval', array_keys($packageNameMap)), (string) array_key_first($acceptableStabilities)];
        if (array_intersect(array_keys($packageNameMap), $this->failing) !== []) {
            if ($this->onFailure !== null) {
                ($this->onFailure)();
            }

            throw new TransportException('The "https://repo.example.org/p2/acme/lib.json" file could not be downloaded');
        }
        $packages = array_values(array_filter($this->served, fn (BasePackage $package): bool => ($this->everyName || \array_key_exists($package->getName(), $packageNameMap)) && isset($acceptableStabilities[$package->getStability()])));

        return ['namesFound' => array_map(static fn (BasePackage $package): string => $package->getName(), $packages), 'packages' => $packages];
    }
}
