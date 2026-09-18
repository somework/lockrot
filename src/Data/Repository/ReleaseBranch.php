<?php

declare(strict_types=1);

namespace Lockrot\Data\Repository;

use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;

/**
 * The release branch a version belongs to: the range a caret constraint on it would stay inside.
 * `1.2.3` and `1.9.0` share branch `1`; `0.3.1` and `0.3.9` share `0.3`, `0.4.0` is another branch,
 * as `^0.3` would have it. A branch snapshot (`dev-master`, `2.x-dev`) belongs to no branch — S6
 * speaks for it.
 *
 * Keys are version strings on purpose (`"1"`, `"0.3"`): {@see Comparator} orders them.
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
        if (preg_match('/^(\d+)\.(\d+)\./', $normalized, $m) !== 1) {
            return null;
        }

        return $m[1] === '0' ? '0.'.$m[2] : $m[1];
    }

    /** `1.x` for key `1`, `0.3.x` for key `0.3`: how a branch is named in evidence. */
    public static function label(string $key): string
    {
        return $key.'.x';
    }

    public static function isAbove(string $key, string $other): bool
    {
        return Comparator::greaterThan($key, $other);
    }
}
