<?php

declare(strict_types=1);

namespace Lockrot\Data\Advisory;

interface AdvisoryLoaderInterface
{
    /**
     * The advisories affecting each installed version, from the configured Composer repositories.
     *
     * @param array<string, string> $versionByName package name => the locked version, as the lock spells it
     */
    public function load(array $versionByName): AdvisoryBatch;
}
