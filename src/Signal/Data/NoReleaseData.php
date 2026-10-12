<?php

declare(strict_types=1);

namespace Lockrot\Signal\Data;

use Lockrot\Signal\Signal;

/** @internal */
final class NoReleaseData implements SignalData
{
    private string $lastRelease;
    private ?string $lastVersion;
    private ?float $years;
    private ?string $datedBy;

    /** @param string $lastRelease an ISO 8601 date and time */
    public function __construct(string $lastRelease, ?string $lastVersion, ?float $years, ?string $datedBy)
    {
        $this->lastRelease = $lastRelease;
        $this->lastVersion = $lastVersion;
        $this->years = $years;
        $this->datedBy = $datedBy;
    }

    public function signalId(): string
    {
        return Signal::S2;
    }

    public function lastRelease(): string
    {
        return $this->lastRelease;
    }

    public function lastVersion(): ?string
    {
        return $this->lastVersion;
    }

    public function years(): ?float
    {
        return $this->years;
    }

    public function datedBy(): ?string
    {
        return $this->datedBy;
    }

    /** @return array{last_release: string, last_version: ?string, years: ?float, dated_by: ?string} */
    public function jsonSerialize(): array
    {
        return ['last_release' => $this->lastRelease, 'last_version' => $this->lastVersion, 'years' => $this->years, 'dated_by' => $this->datedBy];
    }
}
