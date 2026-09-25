<?php

declare(strict_types=1);

namespace Lockrot\Data\Repository;

/** @internal */
final class MetadataBatch
{
    /** @var array<string, PackageMetadata> */
    private array $metadata;
    /** @var list<string> */
    private array $notFound;
    /** @var array<string, string> */
    private array $failed;

    /**
     * @param array<string, PackageMetadata> $metadata
     * @param list<string>                   $notFound
     * @param array<string, string>          $failed
     */
    public function __construct(array $metadata, array $notFound, array $failed)
    {
        $this->metadata = $metadata;
        $this->notFound = $notFound;
        $this->failed = $failed;
    }

    /** @return array<string, PackageMetadata> */
    public function metadata(): array
    {
        return $this->metadata;
    }

    /** @return list<string> */
    public function notFound(): array
    {
        return $this->notFound;
    }

    /** @return array<string, string> */
    public function failed(): array
    {
        return $this->failed;
    }
}
