<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

use Lockrot\Exception\ConfigException;
use Lockrot\Signal\Signal;

/**
 * The accepted values: docs/configuration.md#fail-on-values. The verdict and priority vocabularies
 * must not overlap, because one option serves both. The priority `none` is not a threshold.
 *
 * @internal
 */
final class FailOn
{
    public const NONE = 'none';
    /** Fails on any finding that carries S10: docs/verdicts.md#what-was-not-checked. */
    public const UNCHECKED = 'unchecked';

    /** A report writes the kind as `run.fail_on_kind`. */
    public const KIND_NONE = 'none';
    public const KIND_VERDICT = 'verdict';
    public const KIND_PRIORITY = 'priority';
    public const KIND_UNCHECKED = 'unchecked';
    public const KINDS = [self::KIND_NONE, self::KIND_VERDICT, self::KIND_PRIORITY, self::KIND_UNCHECKED];

    private string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    public static function none(): self
    {
        return new self(self::NONE);
    }

    /** @throws ConfigException when $value is neither `none`, a flagged verdict nor a priority */
    public static function fromString(string $value): self
    {
        if (\in_array($value, self::allowed(), true)) {
            return new self($value);
        }

        throw new ConfigException(\sprintf('fail-on must be one of %s; got "%s"', implode(', ', self::allowed()), $value));
    }

    /**
     * `none` first, then the flagged verdicts from the most severe down, then the priorities from the
     * highest down, then `unchecked`.
     *
     * @return list<string>
     */
    public static function allowed(): array
    {
        $verdicts = [];
        foreach (Verdict::all() as $verdict) {
            if (Verdict::flagged($verdict)) {
                $verdicts[] = $verdict;
            }
        }

        return array_merge([self::NONE], $verdicts, self::priorities(), [self::UNCHECKED]);
    }

    /** @return list<string> */
    private static function priorities(): array
    {
        $priorities = [];
        foreach (Priority::all() as $priority) {
            if ($priority !== Priority::NONE) {
                $priorities[] = $priority;
            }
        }

        return $priorities;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function isNone(): bool
    {
        return $this->value === self::NONE;
    }

    public function kind(): string
    {
        if ($this->isNone()) {
            return self::KIND_NONE;
        }
        if ($this->value === self::UNCHECKED) {
            return self::KIND_UNCHECKED;
        }

        return \in_array($this->value, self::priorities(), true) ? self::KIND_PRIORITY : self::KIND_VERDICT;
    }

    public function reaches(Finding $finding): bool
    {
        switch ($this->kind()) {
            case self::KIND_NONE:
                return false;
            case self::KIND_UNCHECKED:
                foreach ($finding->signals() as $signal) {
                    if ($signal->id() === Signal::S10) {
                        return true;
                    }
                }

                return false;
            case self::KIND_PRIORITY:
                return Priority::rank($finding->priority()) >= Priority::rank($this->value);
            default:
                return Verdict::severity($finding->verdict()) >= Verdict::severity($this->value);
        }
    }
}
