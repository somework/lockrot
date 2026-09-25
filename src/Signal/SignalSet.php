<?php

declare(strict_types=1);

namespace Lockrot\Signal;

use Lockrot\Clock;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Signal\Rule\AbandonedRule;
use Lockrot\Signal\Rule\AdvisoryRule;
use Lockrot\Signal\Rule\ArchivedRule;
use Lockrot\Signal\Rule\LeftBehindRule;
use Lockrot\Signal\Rule\NoPushRule;
use Lockrot\Signal\Rule\NoReleaseRule;
use Lockrot\Signal\Rule\NotCheckedRule;
use Lockrot\Signal\Rule\OldPromiseRule;
use Lockrot\Signal\Rule\PinnedRule;

/** @internal */
final class SignalSet
{
    /** @var list<SignalRule> */
    private array $rules;

    /** @param list<SignalRule> $rules */
    public function __construct(array $rules)
    {
        $this->rules = $rules;
    }

    /**
     * @param string  $targetPhp  the PHP the run targets: S5 measures the installed release against it, S8 names no branch outside it
     * @param ?string $projectPhp the project's own `require.php`, the other floor S8 keeps to ({@see PhpFloor}); null when the manifest has none
     */
    public static function default(Clock $clock, Thresholds $thresholds, string $targetPhp, PhpReleaseDates $dates, ?string $projectPhp = null): self
    {
        return new self([
            new AbandonedRule(),
            new NoReleaseRule($clock, $thresholds),
            new ArchivedRule(),
            new NoPushRule($clock, $thresholds),
            new OldPromiseRule(new ConstraintOpenness(), $dates, $targetPhp),
            new PinnedRule(),
            new LeftBehindRule($clock, $thresholds, new PhpFloor($targetPhp, $projectPhp)),
            new AdvisoryRule(),
            new NotCheckedRule(),
        ]);
    }

    /** @return list<Signal> */
    public function evaluate(PackageFacts $facts): array
    {
        $signals = [];
        foreach ($this->rules as $rule) {
            $signal = $rule->evaluate($facts);
            if ($signal !== null) {
                $signals[] = $signal;
            }
        }
        // by number, not by string: S10 follows S9 rather than sitting between S1 and S2
        usort($signals, static fn (Signal $a, Signal $b): int => strnatcmp($a->id(), $b->id()));

        return $signals;
    }
}
