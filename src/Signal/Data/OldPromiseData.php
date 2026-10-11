<?php

declare(strict_types=1);

namespace Lockrot\Signal\Data;

/** @internal */
final class OldPromiseData implements SignalData
{
    private string $targetPhp;
    private string $targetMajor;
    private string $gaDate;
    private string $phpConstraint;
    private ?int $writtenForPhp;
    private string $released;

    /**
     * @param string $targetMajor   the first release of the target's major, `8.0`
     * @param ?int   $writtenForPhp the constraint's lowest major, null when it names none
     * @param string $released      an ISO 8601 date and time
     */
    public function __construct(string $targetPhp, string $targetMajor, string $gaDate, string $phpConstraint, ?int $writtenForPhp, string $released)
    {
        $this->targetPhp = $targetPhp;
        $this->targetMajor = $targetMajor;
        $this->gaDate = $gaDate;
        $this->phpConstraint = $phpConstraint;
        $this->writtenForPhp = $writtenForPhp;
        $this->released = $released;
    }

    public function targetPhp(): string
    {
        return $this->targetPhp;
    }

    public function targetMajor(): string
    {
        return $this->targetMajor;
    }

    public function gaDate(): string
    {
        return $this->gaDate;
    }

    public function phpConstraint(): string
    {
        return $this->phpConstraint;
    }

    public function writtenForPhp(): ?int
    {
        return $this->writtenForPhp;
    }

    public function released(): string
    {
        return $this->released;
    }

    /** @return array{target_php: string, target_major: string, ga_date: string, php_constraint: string, written_for_php: ?int, released: string} */
    public function jsonSerialize(): array
    {
        return [
            'target_php' => $this->targetPhp,
            'target_major' => $this->targetMajor,
            'ga_date' => $this->gaDate,
            'php_constraint' => $this->phpConstraint,
            'written_for_php' => $this->writtenForPhp,
            'released' => $this->released,
        ];
    }
}
