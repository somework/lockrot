<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

/**
 * The tool version in a recorded file, set to the release that the contract bundle describes.
 * `Version::STRING` changes only at the release bump, and no environment hook pins it, so every
 * recorded file and every fresh output compared with one carries this value instead. A file that
 * prints no version is left as written.
 */
final class VersionMask
{
    public const VALUE = '0.14.0';

    /** The paths that print the tool version: report-2, explain-2 and baseline-2, the html bundle, SARIF. */
    public const PATHS = ['lockrot.version', 'report.lockrot.version', 'runs[].tool.driver.version'];

    /**
     * The bytes of each path's value, so the rest of the file keeps the writer's bytes. The writers
     * put `lockrot` after `$schema` at the root and `version` after `name` in `driver`.
     */
    private const PATTERNS = [
        '{\A(\{\s*(?:"\$schema":\s*"[^"]*",\s*)?"lockrot":\s*\{\s*"version":\s*")[^"]*(")}',
        '{\A(\{\s*"report":\s*\{\s*(?:"\$schema":\s*"[^"]*",\s*)?"lockrot":\s*\{\s*"version":\s*")[^"]*(")}',
        '{("tool":\s*\{\s*"driver":\s*\{\s*"name":\s*"lockrot",\s*"version":\s*")[^"]*(")}',
    ];

    /**
     * @throws \LogicException when the mask would change a value outside PATHS, or miss one of them
     */
    public static function apply(string $contents): string
    {
        $masked = preg_replace(self::PATTERNS, '${1}'.self::VALUE.'${2}', $contents);
        if ($masked === null) {
            throw new \RuntimeException('the version mask failed: '.preg_last_error_msg());
        }
        $data = json_decode($contents, true);
        $expected = \is_array($data) ? self::masked($data) : $contents;
        if ((\is_array($data) ? json_decode($masked, true) : $masked) !== $expected) {
            throw new \LogicException('the version mask must change '.implode(', ', self::PATHS).' and nothing else');
        }

        return $masked;
    }

    /**
     * @param array<mixed, mixed> $data
     *
     * @return array<mixed, mixed> $data with VALUE at each path of PATHS that it holds
     */
    private static function masked(array $data): array
    {
        foreach (self::PATHS as $path) {
            $data = self::set($data, explode('.', $path));
        }

        return $data;
    }

    /**
     * @param array<mixed, mixed> $node
     * @param list<string>        $path
     *
     * @return array<mixed, mixed>
     */
    private static function set(array $node, array $path): array
    {
        $key = array_shift($path);
        $each = substr((string) $key, -2) === '[]';
        $key = $each ? substr((string) $key, 0, -2) : (string) $key;
        if (!\array_key_exists($key, $node)) {
            return $node;
        }
        if ($path === []) {
            $node[$key] = self::VALUE;

            return $node;
        }
        if ($each && \is_array($node[$key])) {
            foreach ($node[$key] as $i => $item) {
                $node[$key][$i] = \is_array($item) ? self::set($item, $path) : $item;
            }

            return $node;
        }
        if (\is_array($node[$key])) {
            $node[$key] = self::set($node[$key], $path);
        }

        return $node;
    }
}
