<?php

declare(strict_types=1);

namespace Lockrot\Data\Repository;

/** @internal */
interface MetadataLoaderInterface
{
    /**
     * Composer's ComposerRepository::asyncFetchFile() turns a "network disabled" transport error
     * into a synthetic 404 whenever it has no cached copy to fall back on (no last-modified date to
     * revalidate against) — so a name that comes back genuinely `notFound` while offline is
     * indistinguishable from one that simply was never cached. Since that cannot be trusted as
     * "this package really does not exist", every such name is reported failed with this reason
     * instead of notFound. It reads as a complete statement on its own, so callers rendering it
     * must not prefix it with another explanation.
     */
    public const OFFLINE_NOT_FOUND_REASON = 'offline: not present in Composer\'s cache';

    /**
     * Reported for a name the loader never got to query against a repository at all because the
     * install-time budget ({@see \Lockrot\Deadline}) had already run out. Distinct from every
     * other failure reason: those describe an answer (or the lack of one) the repository actually
     * gave; this one means the repository was never asked.
     */
    public const BUDGET_REASON = 'not checked: install-time budget exhausted';

    /** @param list<string> $names */
    public function load(array $names): MetadataBatch;
}
