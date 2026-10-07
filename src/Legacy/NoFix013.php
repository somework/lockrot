<?php

declare(strict_types=1);

namespace Lockrot\Legacy;

/**
 * The reasons: docs/schema.md#advisories-with-no-fix-expected.
 *
 * @internal
 */
final class NoFix013
{
    public const NOT_ON_INSTALLED_BRANCH = 'not_on_installed_branch';
    public const RELEASES_UNKNOWN = 'releases_unknown';
    public const AFFECTED_RANGE_UNKNOWN = 'affected_range_unknown';
    public const NO_RELEASE_FIXES = 'no_release_fixes';
    /** The first reason that applies wins. The schemas list them in `x-known-values`. */
    public const REASONS = [self::NOT_ON_INSTALLED_BRANCH, self::RELEASES_UNKNOWN, self::AFFECTED_RANGE_UNKNOWN, self::NO_RELEASE_FIXES];
}
