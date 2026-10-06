<?php

declare(strict_types=1);

namespace Lockrot\Data\Repository;

use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;

/**
 * The release branch a version belongs to: the range a caret constraint on it stays inside. A
 * branch snapshot (`dev-master`, `2.x-dev`) belongs to no branch. Keys are version strings (`"1"`,
 * `"0.3"`, `"0.0.3"`), so that {@see Comparator} orders them. See docs/verdicts.md#left-behind.
 *
 * @internal
 */
final class ReleaseBranch
{
    /** The branch key of a lock version or a normalized one, null for a dev version or an unparsable one. */
    public static function of(string $version): ?string
    {
        if (VersionParser::parseStability($version) === 'dev') {
            return null;
        }

        try {
            $normalized = (new VersionParser())->normalize($version);
        } catch (\UnexpectedValueException $e) {
            return null;
        }
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)\./', $normalized, $m) !== 1) {
            return null;
        }
        if ($m[1] !== '0') {
            return $m[1];
        }

        return $m[2] === '0' ? '0.0.'.$m[3] : '0.'.$m[2];
    }

    /**
     * `1.x` for key `1`, `0.3.x` for key `0.3`: how a branch is named in evidence. A `0.0.3` branch is
     * one release wide and is named by it.
     */
    public static function label(string $key): string
    {
        return substr_count($key, '.') === 2 ? $key : $key.'.x';
    }

    public static function isAbove(string $key, string $other): bool
    {
        return Comparator::greaterThan($key, $other);
    }
}
