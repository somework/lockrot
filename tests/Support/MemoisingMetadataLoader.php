<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Lockrot\Data\Repository\MetadataBatch;
use Lockrot\Data\Repository\MetadataLoaderInterface;
use Lockrot\Data\Repository\PackageMetadata;

/**
 * Asks the loader behind it for each package once: `LockrotCommandTest` runs over a large real lock,
 * and mutation testing re-runs it for every mutant. Remembering weakens nothing: the fixture server
 * is static, the clock is fixed, and the loader has its own tests that fetch for real. The memory is
 * per package name and installed version (`name@version`, `name@` for none): the kept release list
 * depends on the version, so two tests that install one name at two versions must not share it.
 */
final class MemoisingMetadataLoader implements MetadataLoaderInterface
{
    private MetadataLoaderInterface $loader;
    /** @var array<string, PackageMetadata> */
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
     * @param array<string, ?string> $installedByName
     */
    public function load(array $installedByName): MetadataBatch
    {
        $missing = [];
        foreach ($installedByName as $name => $installed) {
            $key = self::key((string) $name, $installed);
            if (!isset($this->metadata[$key]) && !isset($this->notFound[$key]) && !isset($this->failed[$key])) {
                $missing[(string) $name] = $installed;
            }
        }
        if ($missing !== []) {
            $batch = $this->loader->load($missing);
            foreach ($batch->metadata() as $name => $metadata) {
                $this->metadata[self::key($name, $missing[$name] ?? null)] = $metadata;
            }
            foreach ($batch->notFound() as $name) {
                $this->notFound[self::key($name, $missing[$name] ?? null)] = true;
            }
            foreach ($batch->failed() as $name => $reason) {
                $this->failed[self::key($name, $missing[$name] ?? null)] = $reason;
            }
        }

        // Only what this call asked for, in the shape the real loader returns it: a caller must not
        // be handed the names that a previous caller asked for.
        $metadata = [];
        $notFound = [];
        $failed = [];
        foreach ($installedByName as $name => $installed) {
            $name = (string) $name;
            $key = self::key($name, $installed);
            if (isset($this->metadata[$key])) {
                $metadata[$name] = $this->metadata[$key];
            } elseif (isset($this->failed[$key])) {
                $failed[$name] = $this->failed[$key];
            } elseif (isset($this->notFound[$key])) {
                $notFound[] = $name;
            }
        }

        return new MetadataBatch($metadata, $notFound, $failed);
    }

    private static function key(string $name, ?string $installed): string
    {
        return $name.'@'.($installed ?? '');
    }
}
