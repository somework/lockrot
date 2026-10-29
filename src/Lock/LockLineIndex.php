<?php

declare(strict_types=1);

namespace Lockrot\Lock;

use Lockrot\Exception\ConfigException;

/**
 * The 1-based line of a package's `"name"` member in the lock text, keyed by {@see PackageName}.
 * Only a member at depth 3 in `packages` or `packages-dev` counts (`authors[]` and
 * `extra.thanks.name` hold other names), at any indentation. A member that does not stand alone on
 * its line, as in a minified lock, has no line. Of two equal names the last wins, as in
 * json_decode(). A line whose strings PCRE cannot blank leaves no line at all: the depth after it
 * is unknown.
 *
 * @internal
 */
final class LockLineIndex
{
    /** @var array<string, int> */
    private array $lines;

    /** @param array<string, int> $lines */
    private function __construct(array $lines)
    {
        $this->lines = $lines;
    }

    /** @throws ConfigException when $path is not a readable file */
    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new ConfigException($path.' not found');
        }
        // is_readable() first, so that file_get_contents() raises no warning. The `false` check
        // still covers a race or a stream failure that is_readable() cannot see.
        if (!is_readable($path)) {
            throw new ConfigException('Cannot read '.$path);
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new ConfigException('Cannot read '.$path);
        }

        return self::fromString($contents);
    }

    private const PACKAGE_SECTIONS = ['packages', 'packages-dev'];

    /** The nesting depth of a package entry's own members: root object > section array > entry. */
    private const ENTRY_DEPTH = 3;

    public static function fromString(string $json): self
    {
        $lines = [];
        $section = null;
        $depth = 0;

        foreach (preg_split('/\r\n|\n|\r/', $json) ?: [] as $index => $line) {
            // Blank each string literal, so that a brace in a description or a colon in a URL is
            // not read as syntax.
            $structure = preg_replace('/"(?:[^"\\\\]++|\\\\.)*+"/', '""', $line);
            if ($structure === null) {
                return self::empty();
            }

            if ($depth === 1 && preg_match('/^\s*"([^"]+)"\s*:/', $line, $key) === 1) {
                $section = $key[1];
            }
            if (
                $depth === self::ENTRY_DEPTH
                && \in_array($section, self::PACKAGE_SECTIONS, true)
                && preg_match('/^\s*"name":\s*"([^"]+)"\s*,?\s*$/', $line, $match) === 1
            ) {
                $name = json_decode('"'.$match[1].'"');
                if (\is_string($name)) {
                    $lines[PackageName::key($name)] = $index + 1;
                }
            }

            $depth += substr_count($structure, '{') + substr_count($structure, '[')
                - substr_count($structure, '}') - substr_count($structure, ']');
        }

        return new self($lines);
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function lineOf(string $package): ?int
    {
        return $this->lines[$package] ?? null;
    }
}
