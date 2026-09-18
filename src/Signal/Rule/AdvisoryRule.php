<?php

declare(strict_types=1);

namespace Lockrot\Signal\Rule;

use Lockrot\Data\Advisory\Advisory;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalRule;

/**
 * S9: security advisories that affect the installed version — what `composer audit` reports,
 * carried on the finding so the report can say what lockrot alone knows: whether a fix is coming.
 * The signal never decides a verdict; {@see \Lockrot\Verdict\Finding} raises the priority when the
 * verdict says nobody will publish the fix.
 */
final class AdvisoryRule implements SignalRule
{
    /** Advisories named in the summary before the rest are counted. */
    public const NAMED = 3;

    public function evaluate(PackageFacts $facts): ?Signal
    {
        $advisories = $facts->advisories();
        if ($advisories === []) {
            return null;
        }
        $count = \count($advisories);
        $labels = array_map(static fn (Advisory $advisory): string => $advisory->label(), \array_slice($advisories, 0, self::NAMED));
        $named = implode(', ', $labels);
        if ($count > self::NAMED) {
            $named .= \sprintf(' and %d more', $count - self::NAMED);
        }
        $summary = \sprintf(
            '%d security %s %s (%s)',
            $count,
            $count === 1 ? 'advisory affects' : 'advisories affect',
            $facts->package()->version(),
            $named
        );

        return new Signal(Signal::S9, Signal::LEVEL_WARN, $summary, [
            'advisories' => array_map(static fn (Advisory $advisory): array => $advisory->toArray(), $advisories),
        ]);
    }
}
