<?php

declare(strict_types=1);

namespace Lockrot\Signal\Rule;

use Lockrot\Signal\Data\ArchivedData;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalRule;

/** @internal */
final class ArchivedRule implements SignalRule
{
    public function evaluate(PackageFacts $facts): ?Signal
    {
        $activity = $facts->activity();
        if ($activity === null || !$activity->isArchived()) {
            return null;
        }

        return new Signal(Signal::LEVEL_HIGH, 'repository archived on '.$activity->ref()->forgeLabel(), new ArchivedData($activity->repo(), $activity->ref()->host(), $activity->ref()->forge()));
    }
}
