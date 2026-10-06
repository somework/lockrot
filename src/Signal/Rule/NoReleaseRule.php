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
final class NoReleaseRule implements SignalRule
{
    private AgeMeasure $age;

    public function __construct(Clock $clock, Thresholds $thresholds)
    {
        $this->age = new AgeMeasure($clock, $thresholds);
    }

    public function evaluate(PackageFacts $facts): ?Signal
    {
        // The reading S2 judges is the one it quotes: date, version, years and level all come from it.
        $reading = $this->age->release($facts);
        $level = $reading->level();
        if ($level === null) {
            return null;
        }
        $last = $reading->measuredAt();
        $datedBy = $reading->datedBy();

        return new Signal(Signal::S2, $level, \sprintf('last release %s (%.1f years ago%s)', $last->format('Y-m-d'), $reading->years(), $datedBy === null ? '' : ', dated by '.$datedBy), [
            'last_release' => $last->format(\DATE_ATOM), 'last_version' => $reading->version(), 'years' => $reading->years(), 'dated_by' => $datedBy,
        ]);
    }
}
