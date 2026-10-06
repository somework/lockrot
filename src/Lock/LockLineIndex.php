<?php

declare(strict_types=1);

namespace Lockrot\Lock;

use Lockrot\Exception\ConfigException;

/**
 * Maps a package name to the 1-based line of its `"name"` member in the text of the lock, for the
 * formats that annotate a line. The scan reads text, because a decoded structure has no lines. It
 * accepts a `"name"` member only at depth 3 inside `packages` or `packages-dev`, because `authors[]`
 * and `extra.thanks.name` hold other names. The depth does not depend on indentation, so a
 * reformatted lock still resolves. A lock with no line breaks yields no lines, and the
 * formats then omit the line number.
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

    public static function empty(): self
    {
        return new self([]);
    }

    public function lineOf(string $package): ?int
    {
        return $this->lines[$package] ?? null;
    }
}
