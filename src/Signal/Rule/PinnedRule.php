<?php

declare(strict_types=1);

namespace Lockrot\Signal\Rule;

use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalRule;

final class PinnedRule implements SignalRule
{
    public function evaluate(PackageFacts $facts): ?Signal
    {
        $package = $facts->package();
        if ($package->isBranchSnapshot()) {
            return new Signal(Signal::S6, Signal::LEVEL_WARN, 'pinned to branch snapshot '.$package->version(), ['version' => $package->version()]);
        }
        $metadata = $facts->metadata();
        if ($metadata !== null && !$metadata->hasStableRelease()) {
            return new Signal(Signal::S6, Signal::LEVEL_WARN, 'no tagged release in its repository', ['version' => $package->version()]);
        }

        return null;
    }
}
