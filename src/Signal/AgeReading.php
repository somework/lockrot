<?php

declare(strict_types=1);

namespace Lockrot\Signal;

/**
 * One reading of {@see AgeMeasure}: a date lockrot holds and how long before the run clock it was,
 * or why there is none. A measured reading carries the date, the years in integer tenths (the
 * published form), the exact ratio its level was decided on, and the level; an unmeasured one
 * carries only its reason. S2, S4 and S8 copy their reading, so a fired signal and its reading
 * cannot disagree.
 *
 * @internal
 */
final class AgeReading
{
    private ?\DateTimeImmutable $at;
    private ?int $tenths;
    private ?float $ratio;
    private ?string $version;
    private ?string $datedBy;
    private ?string $level;
    private ?string $unmeasured;

    private function __construct(?\DateTimeImmutable $at, ?int $tenths, ?float $ratio, ?string $version, ?string $datedBy, ?string $level, ?string $unmeasured)
    {
        $this->at = $at;
        $this->tenths = $tenths;
        $this->ratio = $ratio;
        $this->version = $version;
        $this->datedBy = $datedBy;
        $this->level = $level;
        $this->unmeasured = $unmeasured;
    }

    /**
     * @param int     $tenths  the years since $at in tenths ({@see \Lockrot\Clock::tenthsSince()})
     * @param float   $ratio   the exact years since $at, which the level was decided on ({@see \Lockrot\Clock::yearsSince()})
     * @param ?string $version the release dated, null for a reading that dates no release (the last push, the installed release)
     * @param ?string $datedBy the monorepo parent whose tag dates it, null when the date is the package's own
     * @param ?string $level   {@see Signal::LEVEL_WARN} or {@see Signal::LEVEL_HIGH}, null below every threshold or where none applies
     */
    public static function measured(\DateTimeImmutable $at, int $tenths, float $ratio, ?string $version, ?string $datedBy, ?string $level): self
    {
        return new self($at, $tenths, $ratio, $version, $datedBy, $level, null);
    }

    /** No reading, and the reason id why ({@see AgeMeasure}'s constants, or an activity reason). */
    public static function unmeasuredBecause(string $reason): self
    {
        return new self(null, null, null, null, null, null, $reason);
    }

    public function isMeasured(): bool
    {
        return $this->unmeasured === null;
    }

    public function at(): ?\DateTimeImmutable
    {
        return $this->at;
    }

    /**
     * The date of a measured reading, for a caller that has already checked {@see level()} or
     * {@see isMeasured()}: a level is only ever set on a measured reading.
     *
     * @throws \LogicException on an unmeasured reading
     */
    public function measuredAt(): \DateTimeImmutable
    {
        if ($this->at === null) {
            throw new \LogicException('An unmeasured reading has no date');
        }

        return $this->at;
    }

    /** The published years: integer tenths over ten, `5.0` for 4.95 years, null when unmeasured. */
    public function years(): ?float
    {
        return $this->tenths === null ? null : $this->tenths / 10;
    }

    public function tenths(): ?int
    {
        return $this->tenths;
    }

    /** The exact years, which thresholds compare; never published. */
    public function ratio(): ?float
    {
        return $this->ratio;
    }

    public function version(): ?string
    {
        return $this->version;
    }

    public function datedBy(): ?string
    {
        return $this->datedBy;
    }

    public function level(): ?string
    {
        return $this->level;
    }

    /** Why there is no reading, null when there is one. */
    public function unmeasured(): ?string
    {
        return $this->unmeasured;
    }
}
