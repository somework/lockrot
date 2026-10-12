<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

/**
 * The years, or the reason there are none: never both and never neither. The finding keeps this
 * object, so the number that it prints and the key that the report counts come from one decision.
 *
 * @internal
 */
final class LibyearsMeasurement
{
    private ?float $years;
    private ?string $unmeasured;
    private bool $atLeast;

    private function __construct(?float $years, ?string $unmeasured, bool $atLeast = false)
    {
        $this->years = $years;
        $this->unmeasured = $unmeasured;
        $this->atLeast = $atLeast;
    }

    public static function of(float $years): self
    {
        return new self($years, null);
    }

    /** A lower bound: the newest release has no date that lockrot trusts, so the years run to the newest dated release above the installed one. */
    public static function atLeast(float $years): self
    {
        return new self($years, null, true);
    }

    /** @param string $reason one of {@see Libyears::REASONS} */
    public static function unmeasured(string $reason): self
    {
        if (!\in_array($reason, Libyears::REASONS, true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a reason libyears go unmeasured; the reasons are %s.', $reason, implode(', ', Libyears::REASONS)));
        }

        return new self(null, $reason);
    }

    /** Unrounded, null when not measured. */
    public function years(): ?float
    {
        return $this->years;
    }

    public function isAtLeast(): bool
    {
        return $this->atLeast;
    }

    /** One of {@see Libyears::REASONS}, null when measured, also for zero years. */
    public function unmeasuredReason(): ?string
    {
        return $this->unmeasured;
    }
}
