<?php

declare(strict_types=1);

namespace Lockrot\Signal;

final class Signal
{
    public const S1 = 'S1';
    public const S2 = 'S2';
    public const S3 = 'S3';
    public const S4 = 'S4';
    public const S5 = 'S5';
    public const S6 = 'S6';
    /** Transitive exposure: attached to a direct requirement whose subtree holds flagged packages. Never decides a verdict. */
    public const S7 = 'S7';
    /** The installed release branch has gone quiet while a higher branch kept releasing. */
    public const S8 = 'S8';
    /** Security advisories affecting the installed version. Never decides a verdict; raises the priority where no fix is coming. */
    public const S9 = 'S9';
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
