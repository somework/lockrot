<?php

declare(strict_types=1);

namespace Lockrot\Data\Repository;

/** @internal */
interface MetadataLoaderInterface
{
    /**
     * Composer's ComposerRepository::asyncFetchFile() turns a "network disabled" error into a
     * synthetic 404 when it has no cached copy. A `notFound` while offline therefore proves nothing,
     * so the loader reports the name as failed with this reason. It is a complete statement: callers
     * must not prefix it with another explanation.
     */
    public const OFFLINE_NOT_FOUND_REASON = 'offline: not present in Composer\'s cache';

    /**
     * The loader never asked the repository for this name: the install-time budget
     * ({@see \Lockrot\Deadline}) ran out. Every other failure reason describes an answer, or the
     * lack of one, from the repository.
     */
    public const BUDGET_REASON = 'not checked: install-time budget exhausted';

    /** The repository lists the name, but no version is left after filtering in either pass. */
    public const NO_VERSIONS_REASON = 'repository listed the package but returned no versions';

    /**
     * @param array<string, ?string> $installedByName package name => the version the lock installs,
     *                                                null for a package whose dates alone are read (a
     *                                                monorepo parent): its metadata keeps no release list
     */
    public function load(array $installedByName): MetadataBatch;
}
