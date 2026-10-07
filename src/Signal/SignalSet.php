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
    private PhpFloor $floor;

    /**
     * @param list<SignalRule> $rules
     * @param ?PhpFloor        $floor the target and the project PHP, null for neither
     */
    public function __construct(array $rules, ?PhpFloor $floor = null)
    {
        $this->rules = $rules;
        $this->floor = $floor ?? new PhpFloor(null);
    }

    /**
     * @param ?string $projectPhp the project's own `require.php`, the other floor S8 keeps to
     *                            ({@see PhpFloor}), null when composer.json has none
     */
    public static function default(Clock $clock, Thresholds $thresholds, string $targetPhp, PhpReleaseDates $dates, ?string $projectPhp = null): self
    {
        $floor = new PhpFloor($targetPhp, $projectPhp);

        return new self([
            new AbandonedRule(),
            new NoReleaseRule($clock, $thresholds),
            new ArchivedRule(),
            new NoPushRule($clock, $thresholds),
            new OldPromiseRule(new ConstraintOpenness(), $dates, $targetPhp),
            new PinnedRule(),
            new LeftBehindRule($clock, $thresholds, $floor),
            new AdvisoryRule(),
            new NotCheckedRule(),
        ], $floor);
    }

    public function phpFloor(): PhpFloor
    {
        return $this->floor;
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
        // By number, not by string: S10 follows S9 and does not sort between S1 and S2.
        usort($signals, static fn (Signal $a, Signal $b): int => strnatcmp($a->id(), $b->id()));

        return $signals;
    }
}
