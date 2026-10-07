<?php

declare(strict_types=1);

namespace Lockrot\Data\Advisory;

/** @internal */
interface AdvisoryLoaderInterface
{
    /**
     * @param array<string, string>       $versionByName             package name => the locked version, as the lock spells it
     * @param list<string>                $notFromComposerRepository the names of $versionByName that no Composer repository serves
     * @param array<string, list<string>> $aliasVersionsByName       package name => the normalised versions of its aliases
     */
    public function load(array $versionByName, array $notFromComposerRepository = [], array $aliasVersionsByName = []): AdvisoryBatch;
}
