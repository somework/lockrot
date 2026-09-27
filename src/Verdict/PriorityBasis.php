<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

/**
 * How a finding's priority was reached: the level its verdict starts at, and each step the walk in
 * {@see Priority::basis()} took from there, in order, with the level before and after it. A step is
 * recorded whenever its fact holds, also when the level cannot move (`low` lowered, `critical`
 * raised), so a reader sees every fact that applied without knowing the ladder.
 *
 * @internal
 */
final class PriorityBasis
{
    /** No direct requirement names the package, but one reaches it. */
    public const STEP_TRANSITIVE = 'transitive';
    /** No direct requirement the run knows reaches the package: its chain is empty. */
    public const STEP_UNREACHED = 'unreached';
    /** Installed only for development. */
    public const STEP_DEV = 'dev';
    /** An advisory the verdict says no fix will come for ({@see Finding::noFixExpected()}). */
    public const STEP_NO_FIX_EXPECTED = 'no_fix_expected';
    /** In the order the steps apply; what the schemas list in `x-known-values`. */
    public const STEPS = [self::STEP_TRANSITIVE, self::STEP_UNREACHED, self::STEP_DEV, self::STEP_NO_FIX_EXPECTED];

    private string $base;
    /** @var list<array{reason: string, from: string, to: string}> */
    private array $steps;

    /** @param list<array{reason: string, from: string, to: string}> $steps */
    private function __construct(string $base, array $steps)
    {
        $this->base = $base;
        $this->steps = $steps;
    }

    public static function startingAt(string $base): self
    {
        return new self($base, []);
    }

    /** The same basis with one more step, from where the last one ended. */
    public function withStep(string $reason, string $to): self
    {
        return new self($this->base, array_merge($this->steps, [['reason' => $reason, 'from' => $this->priority(), 'to' => $to]]));
    }

    public function base(): string
    {
        return $this->base;
    }

    /** @return list<array{reason: string, from: string, to: string}> */
    public function steps(): array
    {
        return $this->steps;
    }

    /** Where the last step ended, or the base when none was taken. */
    public function priority(): string
    {
        return $this->steps === [] ? $this->base : $this->steps[\count($this->steps) - 1]['to'];
    }

    /** @return array{base: string, steps: list<array{reason: string, from: string, to: string}>} */
    public function toArray(): array
    {
        return ['base' => $this->base, 'steps' => $this->steps];
    }
}
