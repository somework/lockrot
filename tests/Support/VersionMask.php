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

    /** The key order of the writers puts `version` first in `lockrot` and before `rules` in `driver`. */
    private const PATTERNS = [
        '{("lockrot":\s*\{\s*"version":\s*")[^"]*(")}',
        '{("driver":\s*\{[^{}\[\]]*?"version":\s*")[^"]*(")}',
    ];

    public static function apply(string $contents): string
    {
        $masked = preg_replace(self::PATTERNS, '${1}'.self::VALUE.'${2}', $contents);
        if ($masked === null) {
            throw new \RuntimeException('the version mask failed: '.preg_last_error_msg());
        }

        return $masked;
    }
}
