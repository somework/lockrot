<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

use Lockrot\Exception\ConfigException;

/**
 * The `fail-on` threshold: the verdict or the priority at or above which a finding fails the run.
 *
 * A verdict threshold reads what was observed about the package (`silent` fails on `silent` and
 * `abandoned`, wherever the package sits in the project). A priority threshold reads how much that
 * applies to the project (`high` fails on a `critical` or `high` finding — an abandoned direct
 * production requirement, say — and lets the same verdict pass on a transitive development
 * package). `none` fails on nothing. The two vocabularies do not overlap, so one option serves both.
 *
 * The baseline is not consulted here: {@see \Lockrot\Config\Policy::exitCode()} skips a finding the
 * project has already accepted before asking, whichever kind of threshold is set.
 */
final class FailOn
{
    public const NONE = 'none';

    private string $value;
    private bool $byPriority;

    private function __construct(string $value, bool $byPriority)
    {
        $this->value = $value;
        $this->byPriority = $byPriority;
    }

    public static function none(): self
    {
        return new self(self::NONE, false);
    }

    /** @throws ConfigException when $value is neither `none`, a flagged verdict nor a priority */
    public static function fromString(string $value): self
    {
        if ($value === self::NONE) {
            return self::none();
        }
        if (Verdict::isValid($value) && Verdict::flagged($value)) {
            return new self($value, false);
        }
        if (\in_array($value, self::priorities(), true)) {
            return new self($value, true);
        }

        throw new ConfigException(\sprintf('fail-on must be one of %s; got "%s"', implode(', ', self::allowed()), $value));
    }

    /**
     * Every accepted value, `none` first, then the verdicts from the most severe down, then the
     * priorities from the highest down.
     *
     * @return list<string>
     */
    public static function allowed(): array
    {
        return array_merge([self::NONE], array_values(array_filter(Verdict::all(), static fn (string $verdict): bool => Verdict::flagged($verdict))), self::priorities());
    }

    /** @return list<string> the priorities a threshold can name — every level but `none`, which is no threshold */
    private static function priorities(): array
    {
        return array_values(array_filter(Priority::all(), static fn (string $priority): bool => $priority !== Priority::NONE));
    }

    public function value(): string
    {
        return $this->value;
    }

    public function isNone(): bool
    {
        return $this->value === self::NONE;
    }

    /** Whether the threshold names a priority rather than a verdict. */
    public function isPriority(): bool
    {
        return $this->byPriority;
    }

    /** Whether $finding is at or above the threshold; never for `none`. */
    public function reaches(Finding $finding): bool
    {
        if ($this->isNone()) {
            return false;
        }
        if ($this->byPriority) {
            return Priority::rank($finding->priority()) >= Priority::rank($this->value);
        }

        return Verdict::severity($finding->verdict()) >= Verdict::severity($this->value);
    }
}
