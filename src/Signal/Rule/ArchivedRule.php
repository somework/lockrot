<?php

declare(strict_types=1);

namespace Lockrot\Signal\Rule;

use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalRule;

final class ArchivedRule implements SignalRule
{
    public function evaluate(PackageFacts $facts): ?Signal
    {
        $activity = $facts->activity();
        if ($activity === null || !$activity->isArchived()) {
            return null;
        }

        return new Signal(Signal::S3, Signal::LEVEL_HIGH, 'repository archived on GitHub', ['repo' => $activity->repo()]);
    }
}
