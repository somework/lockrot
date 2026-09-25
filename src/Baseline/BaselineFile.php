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
 * Where the baseline lives and how it is read and written.
 *
 * lockrot writes the files the caller names — reports with `--output`, this file with
 * `--generate-baseline` — each through a temporary file beside it that is renamed over it
 * ({@see AtomicWriter}); its activity cache under Composer's cache directory; and, with
 * `self-update`, the PHAR. It never writes composer.json or composer.lock.
 *
 * Two paths are kept apart on purpose. {@see path()} is what the filesystem needs — absolute, so
 * the file lands next to the project's composer.json whatever the process's working directory is.
 * {@see displayPath()} is what reports print: the path exactly as it was configured, or the default
 * file name. Both of those are relative, so two machines analysing the same project produce
 * byte-identical output; a project that configures an *absolute* baseline path gives up that
 * property, since the table line and the JSON `baseline.path` then carry the absolute path it asked
 * for. Printing it as given is the deliberate choice: a path the reader recognises beats a
 * relativised one they have to reconstruct.
 *
 * @internal
 */
final class BaselineFile
{
    public const DEFAULT_NAME = 'lockrot-baseline.json';

    private string $path;
    private string $displayPath;

    private function __construct(string $path, string $displayPath)
    {
        $this->path = $path;
        $this->displayPath = $displayPath;
    }

    /**
     * @param string      $projectDir the directory holding composer.json
     * @param null|string $configured `extra.lockrot.baseline` or `--baseline`; null for the default
     */
    public static function resolve(string $projectDir, ?string $configured): self
    {
        $name = ($configured === null || $configured === '') ? self::DEFAULT_NAME : $configured;

        return new self(Path::resolve($projectDir, $name), $name);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function displayPath(): string
    {
        return $this->displayPath;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * @throws ConfigException when the file is missing, unreadable, not JSON, or does not match
     *                         resources/lockrot-baseline.schema.json
     */
    public function read(): Baseline
    {
        return Baseline::fromArray(JsonReader::readObject($this->path));
    }

    /**
     * Writes the baseline atomically ({@see AtomicWriter}), so a run interrupted mid-write can never
     * leave a truncated baseline behind — which would read as "these findings were never accepted"
     * on the next CI run.
     *
     * JsonFile::encode() is used for the encoding, so the file is laid out exactly like
     * composer.json — 4-space indent, unescaped slashes and unicode — and the trailing newline
     * JsonFile::write() appends for a pretty-printed document is added here too.
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
     * The baseline as JSON sees it. `Baseline::toArray()` is the PHP view, where an empty findings
     * map is an empty array — and `json_encode()` writes that as `[]`, which
     * resources/lockrot-baseline.schema.json rejects, since it requires an object there. An explicit
     * stdClass keeps a baseline with nothing in it valid against its own schema.
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
