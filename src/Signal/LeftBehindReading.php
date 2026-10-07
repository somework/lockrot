<?php

declare(strict_types=1);

namespace Lockrot\Signal;

/**
 * What S8 judged: the signal as report-1 writes it, and the keys report-2 adds.
 * report-1's S8 is closed, so the added keys stay here until report-2 writes them.
 *
 * @internal
 */
final class LeftBehindReading
{
    private Signal $signal;
    private ?string $reachablePhp;
    /** @var array{project: ?bool, target: ?bool}|null */
    private ?array $reachableAdmits;
    private float $newestYears;
    private ?float $reachableYears;

    /**
     * @param array{project: ?bool, target: ?bool}|null $reachableAdmits null exactly when no branch is within reach
     * @param float                                     $newestYears     on the run clock, one decimal
     */
    public function __construct(Signal $signal, ?string $reachablePhp, ?array $reachableAdmits, float $newestYears, ?float $reachableYears)
    {
        $this->signal = $signal;
        $this->reachablePhp = $reachablePhp;
        $this->reachableAdmits = $reachableAdmits;
        $this->newestYears = $newestYears;
        $this->reachableYears = $reachableYears;
    }

    public function signal(): Signal
    {
        return $this->signal;
    }

    /** The php of the reachable branch's newest release, null when it declares none or no branch is within reach. */
    public function reachablePhp(): ?string
    {
        return $this->reachablePhp;
    }

    /**
     * Whether the project's lowest PHP and the target admit the reachable branch's php. Null for a
     * floor lockrot could not compare, never "no".
     *
     * @return array{project: ?bool, target: ?bool}|null
     */
    public function reachableAdmits(): ?array
    {
        return $this->reachableAdmits;
    }

    public function newestYears(): float
    {
        return $this->newestYears;
    }

    public function reachableYears(): ?float
    {
        return $this->reachableYears;
    }
}
