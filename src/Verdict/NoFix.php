<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

/**
 * Why no fix is expected for one advisory on a finding ({@see Finding::noFixExpected()}). Exactly one
 * applies per advisory: the first, in the order of {@see REASONS}.
 *
 * @internal
 */
final class NoFix
{
    /** Left behind, and a release on a higher branch fixes it: the branch will not get that fix. */
    public const NOT_ON_INSTALLED_BRANCH = 'not_on_installed_branch';
    /** The release list was not read, or the installed version could not be compared: no fix was looked for. */
    public const RELEASES_UNKNOWN = 'releases_unknown';
    /** The advisory gives no affected range, so no release can be read as fixing it. */
    public const AFFECTED_RANGE_UNKNOWN = 'affected_range_unknown';
    /** The releases were read and the range is known: no listed release above the installed one is outside it. */
    public const NO_RELEASE_FIXES = 'no_release_fixes';
    /** In the order the first that applies is picked; what the schemas list in `x-known-values`. */
    public const REASONS = [self::NOT_ON_INSTALLED_BRANCH, self::RELEASES_UNKNOWN, self::AFFECTED_RANGE_UNKNOWN, self::NO_RELEASE_FIXES];
}
