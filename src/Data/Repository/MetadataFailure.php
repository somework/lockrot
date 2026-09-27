<?php

declare(strict_types=1);

namespace Lockrot\Data\Repository;

/**
 * Why a package's metadata did not come, as the code the report's notes carry: one per reason
 * lockrot writes itself ({@see MetadataLoaderInterface}), and `fetch_failed` for every other message
 * — a transport error, or what a repository threw — so a later release can move a case out of the
 * catch-all into a code of its own.
 *
 * @internal
 */
final class MetadataFailure
{
    public const OFFLINE = 'offline';
    public const INSTALL_TIME_BUDGET = 'install_time_budget';
    public const NO_VERSIONS = 'no_versions';
    public const FETCH_FAILED = 'fetch_failed';
    /** What the schemas list in `x-known-values`. */
    public const REASONS = [self::OFFLINE, self::INSTALL_TIME_BUDGET, self::NO_VERSIONS, self::FETCH_FAILED];

    private const BY_MESSAGE = [
        MetadataLoaderInterface::OFFLINE_NOT_FOUND_REASON => self::OFFLINE,
        MetadataLoaderInterface::BUDGET_REASON => self::INSTALL_TIME_BUDGET,
        MetadataLoaderInterface::NO_VERSIONS_REASON => self::NO_VERSIONS,
    ];

    /** @param string $message a reason {@see MetadataBatch::failed()} gives for a package */
    public static function reason(string $message): string
    {
        return self::BY_MESSAGE[$message] ?? self::FETCH_FAILED;
    }
}
