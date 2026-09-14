<?php

declare(strict_types=1);

namespace Lockrot\Data\Packagist;

use Composer\MetadataMinifier\MetadataMinifier;

/**
 * Expands Packagist p2 "composer/2.0" minified version lists.
 *
 * Delegates to Composer's own bundled composer/metadata-minifier rather than reimplementing the
 * diff/unset loop. Semantics per composer/metadata-minifier MetadataMinifier::expand()
 * (composer/metadata-minifier src/MetadataMinifier.php:22-45): the first entry is complete, every
 * following entry is a diff against the previous expanded entry, and the sentinel string "__unset"
 * removes a key from the accumulated entry.
 */
final class P2Expander
{
    public const MINIFIED_FORMAT = 'composer/2.0';

    /**
     * @param list<array<string, mixed>> $versions
     * @return list<array<string, mixed>>
     */
    public static function expand(array $versions): array
    {
        /** @var list<array<string, mixed>> $expanded */
        $expanded = MetadataMinifier::expand($versions);

        return $expanded;
    }

    /**
     * @param array<string, mixed> $response
     * @return list<array<string, mixed>>
     */
    public static function expandResponse(array $response, string $packageName): array
    {
        $packages = $response['packages'] ?? null;
        $versions = \is_array($packages) ? ($packages[$packageName] ?? null) : null;
        if (!\is_array($versions)) {
            return [];
        }
        /** @var list<array<string, mixed>> $versions */
        $versions = array_values($versions);
        if (($response['minified'] ?? null) === self::MINIFIED_FORMAT) {
            return self::expand($versions);
        }

        return $versions;
    }
}
