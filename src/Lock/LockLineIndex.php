<?php

declare(strict_types=1);

namespace Lockrot\Lock;

use Lockrot\Exception\ConfigException;

/**
 * Maps a package name to the 1-based line of its `"name": "vendor/pkg"` member in the raw text of a
 * composer.lock, so annotation-carrying output formats (GitHub workflow commands, SARIF) can point
 * at the entry a finding is about.
 *
 * The lock is scanned as text rather than parsed: Composer's JSON reader hands back a decoded
 * structure with no line information at all, and the reverse mapping only needs the one member.
 * Both `packages` and `packages-dev` are covered by a single pass; `packages-dev` comes second in
 * the file, so a name present in both (impossible in a lock Composer wrote) resolves to the first.
 *
 * A `"name"` member alone does not identify a package entry — `authors[]` has one, and
 * `extra.thanks.name` holds the name of a *different* package, so matching on the member alone can
 * map a package to a line inside someone else's entry. The scan therefore tracks nesting depth
 * (string literals blanked first, so a brace inside a description cannot shift it) and accepts the
 * member only where a package entry's own members live: depth 3, inside the top-level `packages` or
 * `packages-dev` array. That anchor is independent of indentation, so a reformatted lock still
 * resolves correctly.
 *
 * The index is best-effort by design: a lock with no line breaks (minified, or one entry per line)
 * yields no lines at all rather than wrong ones, and the formatters then emit their annotations
 * without a line number.
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

    /**
     * @throws ConfigException when $path is not a readable file — an unreadable lock is a
     *                         configuration error the caller reports, never a silently empty index
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new ConfigException($path.' not found');
        }
        // is_readable() first so an unreadable file is reported without file_get_contents() raising
        // a warning on the way; the false check behind it still covers the race and the exotic
        // stream failures is_readable() cannot see, so nothing is swallowed either way.
        if (!is_readable($path)) {
            throw new ConfigException('Cannot read '.$path);
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new ConfigException('Cannot read '.$path);
        }

        return self::fromString($contents);
    }

    /** Top-level keys whose array elements are package entries. */
    private const PACKAGE_SECTIONS = ['packages', 'packages-dev'];

    /** The nesting depth of a package entry's own members: root object > section array > entry. */
    private const ENTRY_DEPTH = 3;

    public static function fromString(string $json): self
    {
        $lines = [];
        $section = null;
        $depth = 0;

        foreach (preg_split('/\r\n|\n|\r/', $json) ?: [] as $index => $line) {
            // Blank every string literal before anything structural is read off the line, so neither
            // a brace inside a description nor a colon inside a URL can be mistaken for syntax.
            $structure = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/', '""', $line) ?? '';

            if ($depth === 1 && preg_match('/^\s*"([^"]+)"\s*:/', $line, $key) === 1) {
                $section = $key[1];
            }
            if (
                $depth === self::ENTRY_DEPTH
                && \in_array($section, self::PACKAGE_SECTIONS, true)
                && preg_match('/^\s*"name":\s*"([^"]+)"\s*,?\s*$/', $line, $match) === 1
                && !isset($lines[$match[1]])
            ) {
                $lines[$match[1]] = $index + 1;
            }

            $depth += substr_count($structure, '{') + substr_count($structure, '[')
                - substr_count($structure, '}') - substr_count($structure, ']');
        }

        return new self($lines);
    }

    /** The "no lock to point at" index: every lookup misses, and nothing was read to get there. */
    public static function empty(): self
    {
        return new self([]);
    }

    public function lineOf(string $package): ?int
    {
        return $this->lines[$package] ?? null;
    }
}
