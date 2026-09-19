<?php

declare(strict_types=1);

namespace Lockrot\Signal\Rule;

use Lockrot\Clock;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalRule;
use Lockrot\Signal\Thresholds;

final class NoReleaseRule implements SignalRule
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
        $metadata = $facts->metadata();
        if ($metadata === null || !$metadata->hasStableRelease()) {
            return null;
        }
        $last = $metadata->lastStableReleaseAt();
        if ($last === null) {
            return null;
        }
        $years = $this->clock->yearsSince($last);
        $level = Thresholds::levelFor($years, $this->thresholds->releaseWarnYears(), $this->thresholds->releaseHighYears());
        if ($level === null) {
            return null;
        }

        $datedBy = $metadata->lastStableDatedBy();

        return new Signal(Signal::S2, $level, \sprintf('last release %s (%.1f years ago%s)', $last->format('Y-m-d'), $years, $datedBy === null ? '' : ', dated by '.$datedBy), [
            'last_release' => $last->format(\DATE_ATOM), 'last_version' => $metadata->lastStableVersion(), 'years' => round($years, 1), 'dated_by' => $datedBy,
        ]);
    }
}
