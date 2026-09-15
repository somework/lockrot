<?php

declare(strict_types=1);

namespace Lockrot\Baseline;

use Composer\Json\JsonFile;
use Lockrot\Exception\ConfigException;
use Lockrot\Json\JsonReader;

/**
 * Where the baseline lives and how it is read and written.
 *
 * This is the only file lockrot ever writes, and only on an explicit `--generate-baseline`
 * (SPEC "Non-functional": never modify composer.json/composer.lock).
 *
 * Two paths are kept apart on purpose. {@see path()} is what the filesystem needs — absolute, so
 * the file lands next to the project's composer.json whatever the process's working directory is.
 * {@see displayPath()} is what reports print: the configured value, or the default file name, both
 * relative, so two machines analysing the same project produce byte-identical output.
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
        $path = self::isAbsolute($name) ? $name : rtrim($projectDir, '/\\').'/'.$name;

        return new self($path, $name);
    }

    /**
     * Unix absolute paths start with a separator; Windows ones with a drive letter (`C:\project`)
     * or a UNC prefix (`\\server\share`), both of which the leading-separator test already covers.
     */
    private static function isAbsolute(string $path): bool
    {
        return strpos($path, '/') === 0
            || strpos($path, '\\') === 0
            || preg_match('{^[A-Za-z]:[\\\\/]}', $path) === 1;
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
     * Writes the baseline atomically: the encoded document goes to a sibling `.tmp` file first and
     * is then renamed over the target, so a run interrupted mid-write can never leave a truncated
     * baseline behind — which would read as "these findings were never accepted" on the next CI run.
     *
     * Composer's own JsonFile::write() is not used for the write itself: it calls file_put_contents()
     * without checking the result (2.10.3 src/Composer/Json/JsonFile.php:163,181-189; 2.2.25
     * :153,173-181), so an unwritable target returns silently. JsonFile::encode() is used for the
     * encoding, so the file is laid out exactly like composer.json — 4-space indent, unescaped
     * slashes and unicode — and the trailing newline JsonFile::write() appends for a pretty-printed
     * document is added here too.
     *
     * @throws ConfigException when the target cannot be written
     */
    public function write(Baseline $baseline): void
    {
        $json = JsonFile::encode(
            $baseline->toArray(),
            \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE
        )."\n";

        $temporary = $this->path.'.tmp';
        // Cleared first so reason() below reports this write's own failure and never an unrelated
        // warning some earlier part of the run left behind.
        error_clear_last();
        $written = @file_put_contents($temporary, $json);
        if ($written !== \strlen($json)) {
            @unlink($temporary);

            throw new ConfigException('Cannot write '.$this->displayPath.': '.$this->reason());
        }
        if (!@rename($temporary, $this->path)) {
            @unlink($temporary);

            throw new ConfigException('Cannot write '.$this->displayPath.': '.$this->reason());
        }
    }

    /** The last filesystem failure PHP recorded, or a generic reason when it recorded none. */
    private function reason(): string
    {
        $error = error_get_last();
        $message = $error === null ? null : $error['message'];

        return \is_string($message) && $message !== '' ? $message : 'the file could not be created';
    }
}
