<?php

declare(strict_types=1);

namespace Lockrot\Output;

/**
 * One id means one cause in every reason set that lists it, so one table words them all. The
 * rendering bundle records this table as `contract/en/cause-words.json`: change both together.
 *
 * @internal
 */
final class CauseWords
{
    public const WORDS = [
        'undated_releases' => 'undated releases',
        'allowlisted' => 'allowlisted',
        'no_token' => 'no token',
        'anonymous_budget' => 'anonymous request budget spent',
        'install_time_budget' => 'install-time budget spent',
        'offline' => 'offline',
        'rate_limit' => 'rate limited',
        'fetch_failed' => 'request failed',
        'no_repository' => 'no repository found',
        'not_from_composer_repository' => 'not from a Composer repository',
        'unavailable' => 'metadata unavailable',
        'not_found' => 'not in the repository',
        'releases_unknown' => 'releases not read',
        'composer_too_old' => 'Composer older than 2.4',
        'lookup_failed' => 'lookup failed',
        'branch_snapshot' => 'a branch snapshot has no release branch',
        'host_not_supported' => 'host not supported',
        'repository_not_found' => 'repository not found',
        'no_feed' => 'no repository publishes advisories',
        'unparseable_version' => 'version not readable',
        'no_versions' => 'no version left to read',
        'shared_commit' => 'dated only by a commit its tags share',
        'undated' => 'no date',
        'no_stable_release' => 'no stable release',
        'no_branch_row' => 'no release on its branch',
        'above_listed_releases' => 'installed above every listed release',
        'unreached' => 'lockrot found no path to it from composer.json',
        'no_composer_json' => 'the run read no composer.json',
        'installed_unlisted' => 'its installed release is not among the releases read',
        'through_other_package' => 'its installed release does not require the package itself',
    ];

    /** The words of $id. An id that the table does not know reads with each `_` as a space. */
    public static function of(string $id): string
    {
        return self::WORDS[$id] ?? str_replace('_', ' ', $id);
    }
}
