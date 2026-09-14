<?php

declare(strict_types=1);

namespace Lockrot\Data\Repository;

interface MetadataLoaderInterface
{
    /** @param list<string> $names */
    public function load(array $names): MetadataBatch;
}
