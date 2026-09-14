<?php

declare(strict_types=1);

namespace Lockrot\Signal;

use Lockrot\Clock;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Signal\Rule\AbandonedRule;
use Lockrot\Signal\Rule\ArchivedRule;
use Lockrot\Signal\Rule\NoPushRule;
use Lockrot\Signal\Rule\NoReleaseRule;
use Lockrot\Signal\Rule\OldPromiseRule;
use Lockrot\Signal\Rule\PinnedRule;

final class SignalSet
{
    /** @var list<SignalRule> */
    private array $rules;

    /** @param list<SignalRule> $rules */
    public function __construct(array $rules)
    {
        $this->rules = $rules;
    }

    public static function default(Clock $clock, Thresholds $thresholds, string $targetPhp, PhpReleaseDates $dates): self
    {
        return new self([
            new AbandonedRule(),
            new NoReleaseRule($clock, $thresholds),
            new ArchivedRule(),
            new NoPushRule($clock, $thresholds),
            new OldPromiseRule(new ConstraintOpenness(), $dates, $targetPhp),
            new PinnedRule(),
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
        usort($signals, static fn (Signal $a, Signal $b): int => strcmp($a->id(), $b->id()));

        return $signals;
    }
}
