<?php

declare(strict_types=1);

namespace Lockrot\Signal\Rule;

use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Signal\ConstraintOpenness;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalRule;

final class OldPromiseRule implements SignalRule
{
    private ConstraintOpenness $openness;
    private PhpReleaseDates $dates;
    private string $targetPhp;

    public function __construct(ConstraintOpenness $openness, PhpReleaseDates $dates, string $targetPhp)
    {
        $this->openness = $openness;
        $this->dates = $dates;
        $this->targetPhp = PhpReleaseDates::minorOf($targetPhp);
    }

    public function evaluate(PackageFacts $facts): ?Signal
    {
        $package = $facts->package();
        $released = $package->time();
        $constraint = $package->requirePhp();
        $ga = $this->dates->gaDate($this->targetPhp);
        if ($released === null || $constraint === null || $ga === null || $released >= $ga) {
            return null;
        }
        if (!$this->openness->isOpenEndedFor($constraint, $this->targetPhp)) {
            return null;
        }
        $summary = \sprintf('released %s, before PHP %s GA (%s); php constraint "%s" has no upper bound', $released->format('Y-m-d'), $this->targetPhp, $ga->format('Y-m-d'), $constraint);

        return new Signal(Signal::S5, Signal::LEVEL_WARN, $summary, [
            'target_php' => $this->targetPhp, 'ga_date' => $ga->format('Y-m-d'), 'php_constraint' => $constraint, 'released' => $released->format(\DATE_ATOM),
        ]);
    }
}
