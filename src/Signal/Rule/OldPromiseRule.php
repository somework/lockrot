<?php

declare(strict_types=1);

namespace Lockrot\Signal\Rule;

use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Signal\ConstraintOpenness;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalRule;

/**
 * S5: the installed release was written for an older PHP major and admits the target PHP only
 * because its constraint has no upper bound (docs/verdicts.md#old-promise). The line is the GA of
 * the target's major, not of its minor. A release cut after PHP 8.0 was written with PHP 8 on
 * its CI matrix. So its open `>=7.2` differs from `^7.2 || ^8.0` in spelling alone.
 *
 * @internal
 */
final class OldPromiseRule implements SignalRule
{
    private ConstraintOpenness $openness;
    private PhpReleaseDates $dates;
    private string $targetPhp;
    /** The first release of the target's major, `8.0` for a target of `8.4`: its GA is the line. */
    private string $targetMajor;

    public function __construct(ConstraintOpenness $openness, PhpReleaseDates $dates, string $targetPhp)
    {
        $this->openness = $openness;
        $this->dates = $dates;
        $this->targetPhp = PhpReleaseDates::minorOf($targetPhp);
        $this->targetMajor = explode('.', $this->targetPhp)[0].'.0';
    }

    public function evaluate(PackageFacts $facts): ?Signal
    {
        $package = $facts->package();
        $released = $package->time();
        $constraint = $package->requirePhp();
        $ga = $this->dates->gaDate($this->targetMajor);
        if ($released === null || $constraint === null || $ga === null || $released >= $ga) {
            return null;
        }
        if (!$this->openness->isOpenEndedFor($constraint, $this->targetPhp)) {
            return null;
        }
        $major = explode('.', $this->targetMajor)[0];
        $writtenFor = $this->openness->lowerMajor($constraint);
        $summary = \sprintf(
            'released %s %s, before PHP %s existed (%s GA %s); admits %s untested',
            $released->format('Y-m-d'),
            $writtenFor === null || $writtenFor === 0 ? 'with php "'.$constraint.'"' : 'for PHP '.$writtenFor.' (php "'.$constraint.'")',
            $major,
            $this->targetMajor,
            $ga->format('Y-m-d'),
            $this->targetPhp
        );

        return new Signal(Signal::S5, Signal::LEVEL_WARN, $summary, [
            'target_php' => $this->targetPhp,
            'target_major' => $this->targetMajor,
            'ga_date' => $ga->format('Y-m-d'),
            'php_constraint' => $constraint,
            'written_for_php' => $writtenFor === null || $writtenFor === 0 ? null : $writtenFor,
            'released' => $released->format(\DATE_ATOM),
        ]);
    }
}
