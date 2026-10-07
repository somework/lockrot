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

    private function __construct(?float $years, ?string $unmeasured)
    {
        $this->years = $years;
        $this->unmeasured = $unmeasured;
    }

    public static function of(float $years): self
    {
        return new self($years, null);
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

    /** One of {@see Libyears::REASONS}, null when measured, also for zero years. */
    public function unmeasuredReason(): ?string
    {
        return $this->unmeasured;
    }
}
