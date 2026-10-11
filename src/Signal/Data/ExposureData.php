<?php

declare(strict_types=1);

namespace Lockrot\Signal\Data;

/** @internal */
final class ExposureData implements SignalData
{
    /** @var non-empty-list<ExposedPackage> */
    private array $packages;

    /** @param non-empty-list<ExposedPackage> $packages */
    public function __construct(array $packages)
    {
        $this->packages = $packages;
    }

    /** @return non-empty-list<ExposedPackage> */
    public function packages(): array
    {
        return $this->packages;
    }

    /** @return array{flagged: int, packages: non-empty-list<ExposedPackage>} */
    public function jsonSerialize(): array
    {
        return ['flagged' => \count($this->packages), 'packages' => $this->packages];
    }
}
