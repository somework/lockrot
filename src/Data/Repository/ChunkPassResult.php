<?php

declare(strict_types=1);

namespace Lockrot\Data\Repository;

/**
 * What one pass of {@see RepositoryMetadataLoader::loadChunked()} resolved. `needDev` and
 * `stillRemaining` are mutually exclusive: the stable-stabilities pass only ever populates
 * `needDev` (`stillRemaining` stays empty), the dev-only pass only ever populates `stillRemaining`
 * (`needDev` stays empty) — see {@see RepositoryMetadataLoader::loadChunked()}'s own docblock.
 */
final class ChunkPassResult
{
    /** @var array<string, PackageMetadata> */
    private array $metadata;
    /** @var array<string, string> */
    private array $failed;
    /** @var list<string> */
    private array $stillRemaining;
    /** @var list<string> */
    private array $needDev;
    private bool $budgetExhausted;

    /**
     * @param array<string, PackageMetadata> $metadata
     * @param array<string, string>          $failed
     * @param list<string>                   $stillRemaining
     * @param list<string>                   $needDev
     */
    public function __construct(array $metadata, array $failed, array $stillRemaining, array $needDev, bool $budgetExhausted)
    {
        $this->metadata = $metadata;
        $this->failed = $failed;
        $this->stillRemaining = $stillRemaining;
        $this->needDev = $needDev;
        $this->budgetExhausted = $budgetExhausted;
    }

    /** @return array<string, PackageMetadata> */
    public function metadata(): array
    {
        return $this->metadata;
    }

    /** @return array<string, string> */
    public function failed(): array
    {
        return $this->failed;
    }

    /** @return list<string> */
    public function stillRemaining(): array
    {
        return $this->stillRemaining;
    }

    /** @return list<string> */
    public function needDev(): array
    {
        return $this->needDev;
    }

    public function budgetExhausted(): bool
    {
        return $this->budgetExhausted;
    }
}
