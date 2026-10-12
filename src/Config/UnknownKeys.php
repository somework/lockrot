<?php

declare(strict_types=1);

namespace Lockrot\Config;

use Lockrot\Output\TerminalText;

/**
 * The keys of `extra.lockrot` that lockrot does not read, each as one plain-text warning. It never
 * throws and never judges a value: the schema does that. {@see \Lockrot\Lock\ProjectConfig} adds
 * these lines to a schema error, so a misspelt required key still gets its suggestion. The printers
 * write the lines past Symfony's tag formatter, never through it.
 * See docs/configuration.md#unknown-keys.
 *
 * @internal
 */
final class UnknownKeys
{
    /**
     * The properties of resources/lockrot-config-2.schema.json, in alphabetical order: the first of
     * two equally near keys is the suggestion. ConfigSchemaTest pins the list to the schema.
     */
    public const KNOWN = [
        'accept-unchecked',
        'advisory-lookup',
        'baseline',
        'fail-on',
        'format',
        'ignore',
        'include-dev',
        'install-time',
        'install-time-budget',
        'install-time-strict',
        'project',
        'push-high-years',
        'push-warn-years',
        'release-high-years',
        'release-warn-years',
        'target-php',
    ];

    /** The properties of one `ignore` entry, in alphabetical order, pinned the same way. */
    public const KNOWN_IN_IGNORE = ['expires', 'flags', 'package', 'reason', 'version'];

    /**
     * The properties of one `accept-unchecked` entry, pinned the same way. A misspelt `expires` would
     * otherwise be a silent permanent acceptance.
     */
    public const KNOWN_IN_ACCEPT_UNCHECKED = ['check', 'expires', 'host', 'package', 'reason', 'repository'];

    /** Reserved at the top level for extensions, see docs/compatibility.md#names-reserved-for-extensions. */
    public const EXTENSIONS = 'extensions';

    /** Reserved at both levels, case-sensitive as in OpenAPI, see docs/compatibility.md#names-reserved-for-extensions. */
    public const RESERVED_PREFIX = 'x-';

    /**
     * The substring rule skips a shorter key: it is in too many known keys to say which was meant
     * (`on` is in `fail-on`).
     */
    private const MIN_SUBSTRING = 3;

    /**
     * In bytes. PHP 7.4's levenshtein() measures nothing longer, and a key that long is no
     * near-miss of a known one.
     */
    private const LONGEST_KEY = 255;

    /**
     * One message per unknown key, in document order, with the keys of an `ignore` entry where
     * `ignore` stands. Two keys that read alike after the cut to {@see self::LONGEST_KEY} bytes give
     * one message.
     *
     * @param array<array-key, mixed> $lockrotExtra
     *
     * @return list<string>
     */
    public static function warnings(array $lockrotExtra): array
    {
        $warnings = [];
        foreach ($lockrotExtra as $key => $value) {
            // A JSON key like "5" arrives as the integer 5.
            $key = (string) $key;
            if ($key === 'ignore') {
                $warnings = array_merge($warnings, self::inEntries('ignore', $value, self::KNOWN_IN_IGNORE));
            } elseif ($key === 'accept-unchecked') {
                $warnings = array_merge($warnings, self::inEntries('accept-unchecked', $value, self::KNOWN_IN_ACCEPT_UNCHECKED));
            } elseif ($key !== self::EXTENSIONS && !\in_array($key, self::KNOWN, true) && !self::isReserved($key)) {
                $warnings[] = self::message('extra.lockrot.', $key, self::nearest($key, self::KNOWN));
            }
        }

        return array_values(array_unique($warnings));
    }

    /**
     * @param mixed        $entries a list of objects once the schema accepts it
     * @param list<string> $known   the keys of one entry
     *
     * @return list<string>
     */
    private static function inEntries(string $list, $entries, array $known): array
    {
        $warnings = [];
        foreach (\is_array($entries) ? $entries : [] as $index => $entry) {
            foreach (\is_array($entry) ? array_keys($entry) : [] as $key) {
                // A JSON key like "5" arrives as the integer 5.
                $key = (string) $key;
                if (!\in_array($key, $known, true) && !self::isReserved($key)) {
                    // The index is the project's text too: the list can be an object in a config the schema refused.
                    $warnings[] = self::message('extra.lockrot.'.$list.'['.TerminalText::escape((string) $index, self::LONGEST_KEY).'].', $key, self::nearest($key, $known));
                }
            }
        }

        return $warnings;
    }

    private static function isReserved(string $key): bool
    {
        return strpos($key, self::RESERVED_PREFIX) === 0;
    }

    /** $key is the project's text and is escaped here. $parent arrives escaped. */
    private static function message(string $parent, string $key, ?string $nearest): string
    {
        $message = 'unknown key '.$parent.TerminalText::escape($key, self::LONGEST_KEY).' ignored';

        return $nearest === null ? $message : $message.' (did you mean '.$nearest.'?)';
    }

    /**
     * The rule is Symfony Console's "Did you mean" for a mistyped command, in ASCII lower case. A
     * known key is close when it is at most a third of the key's length of edits away, or contains
     * the key. The fewest edits wins, and a tie goes to the first in $known.
     *
     * @param list<string> $known lower-case, alphabetical
     */
    private static function nearest(string $key, array $known): ?string
    {
        if (\strlen($key) > self::LONGEST_KEY) {
            return null;
        }
        // Not strtolower(): on PHP 7.4 it follows LC_CTYPE, and under a Turkish locale `I` does not
        // become `i`. The PHAR never calls setlocale(), but a plugin's host process can call it.
        $needle = strtr($key, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
        $nearest = null;
        $fewest = \PHP_INT_MAX;
        foreach ($known as $candidate) {
            $distance = levenshtein($needle, $candidate);
            $close = $distance <= \strlen($needle) / 3
                || (\strlen($needle) >= self::MIN_SUBSTRING && strpos($candidate, $needle) !== false);
            if ($close && $distance < $fewest) {
                $nearest = $candidate;
                $fewest = $distance;
            }
        }

        return $nearest;
    }
}
