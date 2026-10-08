<?php

declare(strict_types=1);

namespace Lockrot\Signal;

/** @internal */
final class Signal
{
    public const S1 = 'S1';
    public const S2 = 'S2';
    public const S3 = 'S3';
    public const S4 = 'S4';
    public const S5 = 'S5';
    public const S6 = 'S6';
    /** Never decides a verdict: docs/verdicts.md#transitive-exposure. */
    public const S7 = 'S7';
    /** See docs/verdicts.md#left-behind. */
    public const S8 = 'S8';
    /** Never decides a verdict, but can raise the priority: docs/verdicts.md#security-advisories. */
    public const S9 = 'S9';
    /** Never decides a verdict: docs/verdicts.md#what-was-not-checked. */
    public const S10 = 'S10';

    /** lockrot's own signal ids, in order: report-2's `run.signal_ids`. */
    public const IDS = [self::S1, self::S2, self::S3, self::S4, self::S5, self::S6, self::S7, self::S8, self::S9, self::S10];
    public const LEVEL_INFO = 'info';
    public const LEVEL_WARN = 'warn';
    public const LEVEL_HIGH = 'high';

    private string $id;
    private string $level;
    private string $summary;
    /** @var array<string, mixed> */
    private array $data;

    /** @param array<string, mixed> $data */
    public function __construct(string $id, string $level, string $summary, array $data = [])
    {
        $this->id = $id;
        $this->level = $level;
        $this->summary = $summary;
        $this->data = $data;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function level(): string
    {
        return $this->level;
    }

    public function summary(): string
    {
        return $this->summary;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->data;
    }

    public function isHigh(): bool
    {
        return $this->level === self::LEVEL_HIGH;
    }
}
