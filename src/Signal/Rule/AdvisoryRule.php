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

    /** Packagist's severity scale, worst first; an advisory without one sorts last. */
    private const SEVERITY_RANK = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];

    public function evaluate(PackageFacts $facts): ?Signal
    {
        $advisories = $facts->advisories();
        if ($advisories === []) {
            return null;
        }
        $count = \count($advisories);
        $advisories = self::worstFirst($advisories);
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

    /**
     * Severity first, worst on top, then the repository's own order (newest report first on
     * Packagist) — so the three names the line carries are the three that matter most.
     *
     * @param list<Advisory> $advisories
     *
     * @return list<Advisory>
     */
    private static function worstFirst(array $advisories): array
    {
        $indexed = array_values($advisories);
        $order = array_keys($indexed);
        usort($order, static function (int $a, int $b) use ($indexed): int {
            $rankA = self::SEVERITY_RANK[$indexed[$a]->severity() ?? ''] ?? \count(self::SEVERITY_RANK);
            $rankB = self::SEVERITY_RANK[$indexed[$b]->severity() ?? ''] ?? \count(self::SEVERITY_RANK);

            return $rankA <=> $rankB ?: $a <=> $b;
        });

        return array_map(static fn (int $i): Advisory => $indexed[$i], $order);
    }
}
