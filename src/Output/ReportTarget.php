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
     * The spec starts with a format `--format` takes, spelled the same way, and a colon; everything
     * after that colon is the path, verbatim — `json:C:\out\r.json` included, and a name with spaces
     * at either end. The format is found by matching the known names, the longest first, rather than
     * by cutting at the first colon, so a format whose own name holds a colon — an extension's
     * `<vendor>:<name>` — would still parse.
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
        $format = '';
        foreach (LockrotConfig::FORMATS as $known) {
            if (strpos($spec, $known.':') === 0 && \strlen($known) > \strlen($format)) {
                $format = $known;
            }
        }
        if ($format === '') {
            $colon = strpos($spec, ':');
            throw new ConfigException($colon === false
                ? $option.': expected <format>:<path>, e.g. --output=sarif:lockrot.sarif'
                : $option.': unknown format "'.substr($spec, 0, $colon).'"; the formats are '.implode(', ', LockrotConfig::FORMATS));
        }
        $path = substr($spec, \strlen($format) + 1);
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
