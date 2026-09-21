<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Lockrot\Data\Repository\MetadataBatch;
use Lockrot\Data\Repository\MetadataLoaderInterface;

/**
 * A loader that asks the one behind it for a package once and remembers the answer.
 *
 * `LockrotCommandTest` runs the command end to end over a real 200-package lock, and it did so
 * thirty-three times, each one fetching all two hundred packages from the fixture HTTP server
 * again. Measured: every one of those tests cost ~0.92s, flat, with no first-run step — 30 of the
 * class's 40 seconds, and the class is 73% of the whole unit suite. Mutation testing then pays
 * that bill once per mutant, because it re-runs the covering tests for every mutated line: the
 * `composer and self-update` shard took 40m23s, against a 50-minute cap.
 *
 * Nothing is weakened by remembering. The fixture server is static, the clock is fixed, and what
 * these tests are responsible for is the command, not the loading — the loader has its own class
 * of tests, which still fetches for real. The memory is per package name rather than per call, so
 * a run that asks for a different set of names (`--no-dev`, an `--explain` of one package) is
 * served from what is already known and fetches only the rest.
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

        // Only what this call asked for, in the shape the real loader returns it: a caller that
        // asked for ten names must not be handed the two hundred a previous caller asked for.
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
