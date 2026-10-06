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

    private function __construct(string $path, string $displayPath, string $reportedPath)
    {
        $this->path = $path;
        $this->displayPath = $displayPath;
        $this->reportedPath = $reportedPath;
    }

    /**
     * @param null|string $configured `extra.lockrot.baseline` or `--baseline`, null for the default
     */
    public static function resolve(string $projectDir, ?string $configured): self
    {
        $name = ($configured === null || $configured === '') ? self::DEFAULT_NAME : $configured;
        $reported = Path::isAbsolute($name) ? (Path::relativeTo($name, $projectDir) ?? Path::name($name)) : $name;

        return new self(Path::resolve($projectDir, $name), $name, $reported);
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
     * @throws ConfigException when the file is missing, unreadable, not JSON, or does not match
     *                         resources/lockrot-baseline-1.schema.json
     */
    public function read(): Baseline
    {
        return Baseline::fromArray(JsonReader::readObject($this->path));
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

    /**
     * An empty `findings` map must encode as `{}`: `json_encode()` writes the PHP empty array as
     * `[]`, which resources/lockrot-baseline-1.schema.json rejects.
     *
     * @return array<string, mixed>
     */
    private static function document(Baseline $baseline): array
    {
        $document = $baseline->toArray();
        if ($document['findings'] === []) {
            $document['findings'] = new \stdClass();
        }

        return ['$schema' => Schemas::url(Schemas::BASELINE, Baseline::SCHEMA)] + $document;
    }
}
