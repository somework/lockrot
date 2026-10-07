<?php

declare(strict_types=1);

namespace Lockrot\Legacy;

/**
 * The fields: docs/schema.md#how-a-priority-was-reached.
 *
 * @internal
 */
final class PriorityBasis013
{
    public const STEP_TRANSITIVE = 'transitive';
    public const STEP_UNREACHED = 'unreached';
    public const STEP_DEV = 'dev';
    public const STEP_NO_FIX_EXPECTED = 'no_fix_expected';
    /** The order the steps apply. The schemas list them in `x-known-values`. */
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
