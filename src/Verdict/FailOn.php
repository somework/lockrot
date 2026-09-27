<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

use Lockrot\Exception\ConfigException;
use Lockrot\Signal\Signal;

/**
 * The `fail-on` threshold: the verdict or the priority at or above which a finding fails the run.
 *
 * A verdict threshold reads what was observed about the package (`silent` fails on `silent` and
 * `abandoned`, wherever the package sits in the project). A priority threshold reads how much that
 * applies to the project (`high` fails on a `critical` or `high` finding — an abandoned direct
 * production requirement, say — and lets the same verdict pass on a transitive development
 * package). `none` fails on nothing. The two vocabularies do not overlap — the priority level `none`
 * is not a threshold and is not accepted as one — so one option serves both.
 *
 * The baseline is not consulted here: {@see \Lockrot\Config\Gate::decide()} exempts a finding the
 * project has already accepted, whichever kind of threshold is set.
 *
 * @internal
 */
final class FailOn
{
    public const NONE = 'none';
    /**
     * Not a verdict and not a priority: a run where a check did not happen. It fails on any finding
     * carrying S10 ({@see \Lockrot\Signal\Rule\NotCheckedRule}), which is how a pipeline asks for
     * a complete run — the usual cause is a workflow that never passed `GITHUB_TOKEN` through.
     */
    public const UNCHECKED = 'unchecked';

    /** What a value names, in the order {@see allowed()} lists them; a report writes it as `run.fail_on_kind`. */
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
     * Every accepted value, `none` first, then the verdicts from the most severe down, then the
     * priorities from the highest down.
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

    /** @return list<string> the priorities a threshold can name — every level but `none`, which is no threshold */
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
        if ($this->value === self::NONE) {
            return self::KIND_NONE;
        }
        if ($this->value === self::UNCHECKED) {
            return self::KIND_UNCHECKED;
        }

        return \in_array($this->value, self::priorities(), true) ? self::KIND_PRIORITY : self::KIND_VERDICT;
    }

    /** Whether $finding is at or above the threshold; never for `none`. */
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
