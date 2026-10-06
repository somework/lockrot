<?php

declare(strict_types=1);

namespace Lockrot\Data\Advisory;

/** @internal */
interface AdvisoryLoaderInterface
{
    /** @param array<string, string> $versionByName package name => the locked version, as the lock spells it */
    public function load(array $versionByName): AdvisoryBatch;
}
