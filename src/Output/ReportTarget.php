<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Config\LockrotConfig;
use Lockrot\Exception\ConfigException;
use Lockrot\Filesystem\Path;

/**
 * One `--output=<format>:<path>`: which format, the file it goes to, and that path as it was given.
 *
 * @internal
 */
final class ReportTarget
{
    private string $option;
    private string $format;
    private string $path;
    private string $displayPath;

    private function __construct(string $option, string $format, string $path, string $displayPath)
    {
        $this->option = $option;
        $this->format = $format;
        $this->path = $path;
        $this->displayPath = $displayPath;
    }

    /**
     * No format name has a colon in it, so the first colon ends the format and everything after it
     * is the path, verbatim — `json:C:\out\r.json` included, and a name with spaces at either end.
     * The format is one `--format` takes, spelled the same way.
     *
     * @param string $spec what follows `--output=`
     * @param string $cwd  the directory a relative path is relative to
     *
     * @throws ConfigException when the spec is not `<format>:<path>`, the format is unknown or the
     *                         path names no file
     */
    public static function parse(string $spec, string $cwd): self
    {
        $option = '--output='.$spec;
        $colon = strpos($spec, ':');
        if ($colon === false) {
            throw new ConfigException($option.': expected <format>:<path>, e.g. --output=sarif:lockrot.sarif');
        }
        $format = substr($spec, 0, $colon);
        if (!\in_array($format, LockrotConfig::FORMATS, true)) {
            throw new ConfigException($option.': unknown format "'.$format.'"; the formats are '.implode(', ', LockrotConfig::FORMATS));
        }
        $path = substr($spec, $colon + 1);
        if ($path === '') {
            throw new ConfigException($option.': the path is empty');
        }
        if (\in_array(substr($path, -1), ['/', '\\'], true)) {
            throw new ConfigException($option.': the path names a directory, not a file');
        }

        return new self($option, $format, Path::resolve($cwd, $path), $path);
    }

    /** `--output=<spec>`, as the option was given: what every message about this target starts with. */
    public function option(): string
    {
        return $this->option;
    }

    public function format(): string
    {
        return $this->format;
    }

    /** Absolute: where the file is written. */
    public function path(): string
    {
        return $this->path;
    }

    /** The path as it was given, for messages. */
    public function displayPath(): string
    {
        return $this->displayPath;
    }
}
