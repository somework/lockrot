<?php

declare(strict_types=1);

namespace Lockrot\Signal\Rule;

use Lockrot\Clock;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalRule;
use Lockrot\Signal\Thresholds;

final class NoPushRule implements SignalRule
{
    private Clock $clock;
    private Thresholds $thresholds;

    public function __construct(Clock $clock, Thresholds $thresholds)
    {
        $this->clock = $clock;
        $this->thresholds = $thresholds;
    }

    public function evaluate(PackageFacts $facts): ?Signal
    {
        $activity = $facts->activity();
        if ($activity === null || $activity->pushedAt() === null) {
            return null;
        }
        $years = $this->clock->yearsSince($activity->pushedAt());
        $level = Thresholds::levelFor($years, $this->thresholds->pushWarnYears(), $this->thresholds->pushHighYears());
        if ($level === null) {
            return null;
        }

        return new Signal(Signal::S4, $level, \sprintf('last push %s (%.1f years ago)', $activity->pushedAt()->format('Y-m-d'), $years), [
            'last_push' => $activity->pushedAt()->format(\DATE_ATOM), 'repo' => $activity->repo(), 'years' => round($years, 1),
        ]);
    }
}
