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

    public static function fromString(string $json): self
    {
        $lines = [];
        // Package names always carry a vendor prefix, so requiring the slash keeps the index to
        // package entries: the "name" members of author blocks (which sit deeper inside an entry,
        // after its own name) can never shadow a package of the same spelling.
        foreach (preg_split('/\r\n|\n|\r/', $json) ?: [] as $index => $line) {
            if (preg_match('/^\s*"name":\s*"([^"]+\/[^"]+)"\s*,?\s*$/', $line, $match) !== 1) {
                continue;
            }
            if (!isset($lines[$match[1]])) {
                $lines[$match[1]] = $index + 1;
            }
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
