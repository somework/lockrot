<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Lockrot\Data\Repository\MetadataBatch;
use Lockrot\Data\Repository\MetadataLoaderInterface;

/**
 * A loader that asks the one behind it for a package once and remembers the answer.
 *
 * `LockrotCommandTest` runs the command end to end over a large real lock. Fetching every package
 * from the fixture HTTP server again in each test is slow, and mutation testing pays that cost once
 * per mutant, because it re-runs the covering tests for every mutated line. Remembering weakens
 * nothing: the fixture server is static, the clock is fixed, and the loader has its own tests that
 * fetch for real. The memory is per package name, so a run that asks for other names (`--no-dev`,
 * an `--explain` of one package) fetches only the rest.
 */
final class MemoisingMetadataLoader implements MetadataLoaderInterface
{
    private MetadataLoaderInterface $loader;
    /** @var array<string, \Lockrot\Data\Repository\PackageMetadata> */
    private array $metadata = [];
    /** @var array<string, true> */
    private array $notFound = [];
    /** @var array<string, string> */
    private array $failed = [];

    public function __construct(MetadataLoaderInterface $loader)
    {
        $this->loader = $loader;
    }

    /**
     * @param list<string> $names
     */
    public function load(array $names): MetadataBatch
    {
        $wanted = array_values(array_unique($names));
        $missing = [];
        foreach ($wanted as $name) {
            if (!isset($this->metadata[$name]) && !isset($this->notFound[$name]) && !isset($this->failed[$name])) {
                $missing[] = $name;
            }
        }
        if ($missing !== []) {
            $batch = $this->loader->load($missing);
            $this->metadata += $batch->metadata();
            foreach ($batch->notFound() as $name) {
                $this->notFound[$name] = true;
            }
            foreach ($batch->failed() as $name => $reason) {
                $this->failed[$name] = $reason;
            }
        }

        // Only what this call asked for, in the shape the real loader returns it: a caller must not
        // be handed the names that a previous caller asked for.
        $metadata = [];
        $notFound = [];
        $failed = [];
        foreach ($wanted as $name) {
            if (isset($this->metadata[$name])) {
                $metadata[$name] = $this->metadata[$name];
            } elseif (isset($this->failed[$name])) {
                $failed[$name] = $this->failed[$name];
            } elseif (isset($this->notFound[$name])) {
                $notFound[] = $name;
            }
        }

        return new MetadataBatch($metadata, $notFound, $failed);
    }
}
