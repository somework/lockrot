<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Lockrot\Data\Advisory\AdvisoryBatch;
use Lockrot\Data\Advisory\AdvisoryLoaderInterface;

final class FixedAdvisories implements AdvisoryLoaderInterface
{
    private AdvisoryBatch $batch;

    public function __construct(AdvisoryBatch $batch)
    {
        $this->batch = $batch;
    }

    public function load(array $versionByName, array $notFromComposerRepository = [], array $aliasVersionsByName = []): AdvisoryBatch
    {
        return $this->batch;
    }
}
