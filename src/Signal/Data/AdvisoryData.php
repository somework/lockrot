<?php

declare(strict_types=1);

namespace Lockrot\Signal\Data;

use Lockrot\Signal\Signal;
use Lockrot\Verdict\GradedPackage;

/** @internal */
final class AdvisoryData implements SignalData
{
    /** @var list<AdvisoryRow> */
    private array $rows;
    private bool $releasesRead;
    private bool $complete;

    /**
     * @param list<AdvisoryRow> $rows         in the tie-break order of the deciding advisory
     * @param bool              $releasesRead the releases were read and compared with each range
     * @param bool              $complete     every advisory feed answered
     */
    public function __construct(array $rows, bool $releasesRead, bool $complete)
    {
        $this->rows = $rows;
        $this->releasesRead = $releasesRead;
        $this->complete = $complete;
    }

    public function signalId(): string
    {
        return Signal::S9;
    }

    /** @return list<AdvisoryRow> */
    public function rows(): array
    {
        return $this->rows;
    }

    /** A copy with the row of that advisory marked deciding and every other row not. */
    public function withDeciding(?string $id): self
    {
        $clone = clone $this;
        $clone->rows = array_map(static fn (AdvisoryRow $row): AdvisoryRow => $row->withDeciding($row->id() === $id), $this->rows);

        return $clone;
    }

    /** @param array<string, GradedPackage> $findings by package name */
    public function withFindings(array $findings): self
    {
        $clone = clone $this;
        $clone->rows = array_map(static fn (AdvisoryRow $row): AdvisoryRow => $row->withFindings($findings), $this->rows);

        return $clone;
    }

    /** @return array{advisories: list<AdvisoryRow>, releases_read: bool, complete: bool} */
    public function jsonSerialize(): array
    {
        return ['advisories' => $this->rows, 'releases_read' => $this->releasesRead, 'complete' => $this->complete];
    }
}
