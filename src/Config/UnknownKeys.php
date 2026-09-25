<?php

declare(strict_types=1);

namespace Lockrot\Config;

use Lockrot\Output\TerminalText;

/**
 * The keys of an `extra.lockrot` that lockrot does not read, each as one plain-text warning.
 *
 * The config schema accepts any key (config-1 says so, and a composer.json that worked keeps
 * working), so a typo — `install-tme`, `failOn`, an ignore entry's `expire` — used to change
 * nothing and say nothing. This names each such key once, with the known key it was probably meant
 * to be when one is close enough. It never throws and never judges a value: the schema does that.
 * When the schema rejects the config, {@see \Lockrot\Lock\ProjectConfig} adds these lines to its
 * error, so a misspelt required key (`reasn`) still gets its suggestion.
 *
 * A key is shown through {@see TerminalText::escape()}, cut at {@see self::LONGEST_KEY} bytes, so a
 * warning is one printable line and two different keys never read alike. The lines are plain text:
 * the printers write them past Symfony's tag formatter, never through it.
 *
 * Two namespaces are reserved and never warned about: `extensions` at the top level, whose contents
 * belong to whatever reads them and are not walked, and any key starting with `x-`, at either level.
 *
 * @internal
 */
final class UnknownKeys
{
    /**
     * The properties of resources/lockrot-config.schema.json, in alphabetical order: the first of two
     * equally near keys is the suggestion. ConfigSchemaTest pins the list to the schema.
     */
    public const KNOWN = [
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
    public const KNOWN_IN_IGNORE = ['expires', 'package', 'reason', 'version'];

    /** Reserved at the top level for the configuration of extensions; never warned about, never walked. */
    public const EXTENSIONS = 'extensions';

    /** Reserved at both levels for tools and people: `x-ci`, `x-ticket`. Case-sensitive, as in OpenAPI. */
    public const RESERVED_PREFIX = 'x-';

    /**
     * The shortest key the substring rule applies to. Shorter ones are in too many known keys to
     * say which was meant: `e` is in half of them, `on` in `fail-on`.
     */
    private const MIN_SUBSTRING = 3;

    /**
     * The longest key compared with the known ones and shown whole, in bytes. PHP 7.4's levenshtein()
     * measures nothing longer, and a key that long is never a near-miss of a 19-byte setting anyway.
     */
    private const LONGEST_KEY = 255;

    /**
     * One message per unknown key, in document order — an ignore entry's where `ignore` is — such as
     * `unknown key extra.lockrot.install-tme ignored (did you mean install-time?)`. Two keys that
     * read the same once cut to {@see self::LONGEST_KEY} bytes give one message.
     *
     * @param array<array-key, mixed> $lockrotExtra contents of composer.json extra.lockrot; a JSON key like "5" is an integer here
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
                $warnings = array_merge($warnings, self::inIgnore($value));
            } elseif ($key !== self::EXTENSIONS && !\in_array($key, self::KNOWN, true) && !self::isReserved($key)) {
                $warnings[] = self::message('extra.lockrot.', $key, self::nearest($key, self::KNOWN));
            }
        }

        return array_values(array_unique($warnings));
    }

    /**
     * @param mixed $ignore the `ignore` value, a list of objects once the schema has accepted it
     *
     * @return list<string>
     */
    private static function inIgnore($ignore): array
    {
        $warnings = [];
        foreach (\is_array($ignore) ? $ignore : [] as $index => $entry) {
            foreach (\is_array($entry) ? array_keys($entry) : [] as $key) {
                // A JSON key like "5" arrives as the integer 5.
                $key = (string) $key;
                if (!\in_array($key, self::KNOWN_IN_IGNORE, true) && !self::isReserved($key)) {
                    $warnings[] = self::message('extra.lockrot.ignore['.$index.'].', $key, self::nearest($key, self::KNOWN_IN_IGNORE));
                }
            }
        }

        return $warnings;
    }

    private static function isReserved(string $key): bool
    {
        return strpos($key, self::RESERVED_PREFIX) === 0;
    }

    /** $parent is lockrot's own text; only $key, the project's, is escaped. */
    private static function message(string $parent, string $key, ?string $nearest): string
    {
        $message = 'unknown key '.$parent.TerminalText::escape($key, self::LONGEST_KEY).' ignored';

        return $nearest === null ? $message : $message.' (did you mean '.$nearest.'?)';
    }

    /**
     * The known key $key was probably meant to be, or null when none is close. The rule is Symfony
     * Console's "Did you mean" for a mistyped command, compared in ASCII lower case: a known key is close
     * when it is at most a third of the key's length of edits away, or when it contains the key; the
     * fewest edits wins, and a tie goes to the first in $known, which is alphabetical.
     *
     * @param list<string> $known lower-case, alphabetical
     */
    private static function nearest(string $key, array $known): ?string
    {
        // PHP 7.4's levenshtein() warns and returns -1 past 255 bytes. No key that long is near a
        // known one anyway: hundreds of edits apart, and too long for any known key to contain it.
        if (\strlen($key) > self::LONGEST_KEY) {
            return null;
        }
        // Not strtolower(): on PHP 7.4 it follows LC_CTYPE, and under a Turkish locale `I` does not
        // become `i`. The PHAR never calls setlocale(), but a plugin's host process may have.
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
