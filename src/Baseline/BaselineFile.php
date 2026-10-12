<?php

declare(strict_types=1);

namespace Lockrot\Baseline;

use Composer\Json\JsonFile;
use Lockrot\Exception\ConfigException;
use Lockrot\Filesystem\AtomicWriter;
use Lockrot\Filesystem\Path;
use Lockrot\Json\JsonReader;
use Lockrot\Json\Schemas;

/**
 * The baseline file has three paths. {@see path()} is absolute for the filesystem.
 * {@see displayPath()} is the configured path for terminal messages. {@see reportedPath()} goes
 * into a report. A report is published, so it must not carry an absolute path: that path names
 * the account that ran lockrot, and two machines then write different lines.
 *
 * @internal
 */
final class BaselineFile
{
    public const DEFAULT_NAME = 'lockrot-baseline.json';

    private string $path;
    private string $displayPath;
    private string $reportedPath;
    private string $release;

    private function __construct(string $path, string $displayPath, string $reportedPath, string $release)
    {
        $this->path = $path;
        $this->displayPath = $displayPath;
        $this->reportedPath = $reportedPath;
        $this->release = $release;
    }

    /**
     * @param null|string $configured `extra.lockrot.baseline` or `--baseline`, null for the default
     * @param string      $release    the running release, `Version::STRING`: the refusal of a
     *                                baseline-1 file names its major and minor
     */
    public static function resolve(string $projectDir, ?string $configured, string $release): self
    {
        $name = ($configured === null || $configured === '') ? self::DEFAULT_NAME : $configured;
        $reported = Path::isAbsolute($name) ? (Path::relativeTo($name, $projectDir) ?? Path::name($name)) : $name;

        return new self(Path::resolve($projectDir, $name), $name, $reported, $release);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function displayPath(): string
    {
        return $this->displayPath;
    }

    public function reportedPath(): string
    {
        return $this->reportedPath;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * A file whose `lockrot.schema` is the integer 1 is refused before schema validation and before
     * any gate decision (docs/baseline.md#a-baseline-1-file). No fact of it is read.
     *
     * @throws ConfigException when the file is missing, unreadable, not JSON, a baseline-1 file, or does
     *                         not match resources/lockrot-baseline-2.schema.json
     */
    public function read(): Baseline
    {
        $document = JsonReader::readObject($this->path);
        if (self::numberIn($document) === 1) {
            throw new ConfigException($this->refusal());
        }

        return Baseline::fromArray($document);
    }

    /**
     * The `lockrot.schema` of the file, read alone. Null when the file is not a JSON object, or the
     * key is missing or not an integer. `--generate-baseline` reads it to skip a baseline-1 file.
     */
    public function schemaNumber(): ?int
    {
        try {
            return self::numberIn(JsonReader::readObject($this->path));
        } catch (ConfigException $e) {
            return null;
        }
    }

    /** @param array<string, mixed> $document */
    private static function numberIn(array $document): ?int
    {
        $lockrot = $document['lockrot'] ?? null;
        $number = \is_array($lockrot) ? ($lockrot['schema'] ?? null) : null;

        return \is_int($number) ? $number : null;
    }

    /** The release's major and minor stay true for every later release that reads baseline-2 only. */
    private function refusal(): string
    {
        $line = preg_match('/^(\d+)\.(\d+)/', $this->release, $m) === 1 ? $m[1].'.'.$m[2] : $this->release;

        return \sprintf('lockrot %s reads baseline-2 only. Review the findings, then run --generate-baseline.', $line);
    }

    /**
     * The write is atomic ({@see AtomicWriter}): a truncated baseline reads as "these findings
     * were never accepted" on the next CI run. JsonFile::encode() lays the file out like
     * composer.json, and the trailing newline matches JsonFile::write().
     *
     * @throws ConfigException when the target cannot be written
     */
    public function write(Baseline $baseline): void
    {
        $json = JsonFile::encode(
            self::document($baseline),
            \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE
        )."\n";

        AtomicWriter::write($this->path, $json, $this->displayPath);
    }

    /** @return array<string, mixed> */
    private static function document(Baseline $baseline): array
    {
        return ['$schema' => Schemas::url(Schemas::BASELINE, Baseline::SCHEMA)] + $baseline->toArray();
    }
}
