<?php

declare(strict_types=1);

namespace Lockrot\Signal\Rule;

use Lockrot\Clock;
use Lockrot\Signal\AgeMeasure;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalRule;
use Lockrot\Signal\Thresholds;

/** @internal */
final class NoPushRule implements SignalRule
{
    private AgeMeasure $age;

    public function __construct(Clock $clock, Thresholds $thresholds)
    {
        $this->age = new AgeMeasure($clock, $thresholds);
    }

    public function evaluate(PackageFacts $facts): ?Signal
    {
        $activity = $facts->activity();
        if ($activity === null) {
            return null;
        }
        $reading = $this->age->pushOf($activity);
        $level = $reading->level();
        if ($level === null) {
            return null;
        }
        $last = $reading->measuredAt();

        return new Signal(Signal::S4, $level, \sprintf('%s %s (%.1f years ago)', $activity->ref()->activityWording(), $last->format('Y-m-d'), $reading->years()), [
            'last_push' => $last->format(\DATE_ATOM), 'repo' => $activity->repo(), 'host' => $activity->ref()->host(), 'years' => $reading->years(),
        ]);
    }
}
