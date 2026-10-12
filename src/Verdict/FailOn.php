<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

use Lockrot\Exception\ConfigException;

/**
 * The parsed `--fail-on` list: at most one value per kind (docs/configuration.md#fail-on-values).
 * {@see \Lockrot\Config\Gate::decide()}, not this class, decides which finding a value reaches.
 *
 * @internal
 */
final class FailOn
{
    public const NONE = 'none';
    /** Fails on any finding that carries S10: docs/verdicts.md#what-was-not-checked. */
    public const UNCHECKED = 'unchecked';

    public const KIND_GRADE = 'grade';
    public const KIND_FLAG = 'flag';
    public const KIND_VULNERABLE = 'vulnerable';
    public const KIND_UNCHECKED = 'unchecked';
    /** The kinds in the order of the canonical form, `run.gates[].kind`. */
    public const KINDS = [self::KIND_GRADE, self::KIND_FLAG, self::KIND_VULNERABLE, self::KIND_UNCHECKED];

    /** Each `vulnerable` value with the lowest raw severity it reaches. */
    public const VULNERABLE = ['vulnerable' => 'low', 'vulnerable-medium' => 'medium', 'vulnerable-high' => 'high', 'vulnerable-critical' => 'critical'];

    private const KIND_NOUNS = [self::KIND_GRADE => 'grades', self::KIND_FLAG => 'flags', self::KIND_VULNERABLE => 'vulnerable values', self::KIND_UNCHECKED => 'unchecked values'];

    /** @var list<FailOnValue> in kind order */
    private array $values;

    /** @param list<FailOnValue> $values */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * Values are trimmed and lower-cased, and whitespace around commas is accepted.
     *
     * @param string|list<string> $input  a comma-separated list, or the JSON array of config
     * @param string              $source the option, variable or key that gave it, as the message names it
     *
     * @throws ConfigException for an unknown value, `vulnerable-low`, two values of one kind, or `none` beside another value
     */
    public static function parse($input, string $source = 'fail-on'): self
    {
        $words = \is_array($input) ? $input : explode(',', $input);
        $byKind = [];
        $seen = [];
        foreach ($words as $word) {
            $value = strtolower(trim($word));
            if ($value === 'vulnerable-low') {
                throw new ConfigException($source.': vulnerable-low is not a value: use "vulnerable": every counted advisory is at least low');
            }
            $kind = self::kindOf($value);
            if ($kind === null) {
                throw new ConfigException(\sprintf('%s: unknown value "%s". The values are: %s', $source, $value, implode(', ', self::allowed())));
            }
            if (isset($seen[$value])) {
                throw new ConfigException(\sprintf('%s: "%s" is given twice', $source, $value));
            }
            if ($seen !== [] && ($value === self::NONE || isset($seen[self::NONE]))) {
                throw new ConfigException(\sprintf('%s: "none" stands alone: drop it, or drop every other value', $source));
            }
            if ($kind !== self::NONE && isset($byKind[$kind])) {
                throw new ConfigException(\sprintf('%s: "%s" and "%s" are both %s. A list holds one value per kind', $source, $byKind[$kind]->value(), $value, self::KIND_NOUNS[$kind]));
            }
            $seen[$value] = true;
            if ($kind !== self::NONE) {
                $byKind[$kind] = new FailOnValue($value, $kind, self::thresholdOf($value, $kind));
            }
        }

        return new self(array_values(array_filter(array_map(static fn (string $kind): ?FailOnValue => $byKind[$kind] ?? null, self::KINDS))));
    }

    /**
     * `none`, then each kind's values: the grades from the highest down, the maintenance flags in
     * flag order, `vulnerable` and its raw severities upwards, `unchecked`.
     *
     * @return list<string>
     */
    public static function allowed(): array
    {
        return array_merge([self::NONE], ScoreModel::GRADES, array_keys(ScoreModel::POINTS), array_keys(self::VULNERABLE), [self::UNCHECKED]);
    }

    private static function kindOf(string $value): ?string
    {
        if ($value === self::NONE) {
            return self::NONE;
        }
        if (\in_array($value, ScoreModel::GRADES, true)) {
            return self::KIND_GRADE;
        }
        if (isset(ScoreModel::POINTS[$value])) {
            return self::KIND_FLAG;
        }
        if (isset(self::VULNERABLE[$value])) {
            return self::KIND_VULNERABLE;
        }

        return $value === self::UNCHECKED ? self::KIND_UNCHECKED : null;
    }

    private static function thresholdOf(string $value, string $kind): ?string
    {
        if ($kind === self::KIND_VULNERABLE) {
            return self::VULNERABLE[$value];
        }

        return $kind === self::KIND_UNCHECKED ? null : $value;
    }

    /** `run.fail_on`: the values in kind order joined with `,`, `none` without a value. */
    public function value(): string
    {
        return $this->values === [] ? self::NONE : implode(',', array_map(static fn (FailOnValue $value): string => $value->value(), $this->values));
    }

    public function isNone(): bool
    {
        return $this->values === [];
    }

    /** @return list<FailOnValue> `run.gates[]`, in kind order */
    public function values(): array
    {
        return $this->values;
    }

    /** @return list<string> */
    public function kinds(): array
    {
        return array_map(static fn (FailOnValue $value): string => $value->kind(), $this->values);
    }

    public function has(string $kind): bool
    {
        return \in_array($kind, $this->kinds(), true);
    }
}
