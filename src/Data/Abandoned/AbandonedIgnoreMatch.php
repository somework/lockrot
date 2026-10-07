<?php

declare(strict_types=1);

namespace Lockrot\Data\Abandoned;

/**
 * The patterns of Composer's abandoned ignore list that match one package name.
 *
 * @internal
 */
final class AbandonedIgnoreMatch
{
    /** The Composer settings that hold the list: an open set, as Composer's settings grow outside lockrot. */
    public const BY_POLICY = 'policy.abandoned';
    public const BY_AUDIT = 'audit.ignore-abandoned';

    private string $by;
    /** @var list<array{pattern: string, reason: ?string, constraints: list<string>}> */
    private array $rules;

    /** @param list<array{pattern: string, reason: ?string, constraints: list<string>}> $rules in config order */
    public function __construct(string $by, array $rules)
    {
        $this->by = $by;
        $this->rules = $rules;
    }

    public function by(): string
    {
        return $this->by;
    }

    /**
     * `reason` as Composer merges a pattern's reasons for audit. `constraints` are those written on
     * the pattern's audit rules: `composer audit` does not apply them, and neither does lockrot.
     *
     * @return list<array{pattern: string, reason: ?string, constraints: list<string>}>
     */
    public function rules(): array
    {
        return $this->rules;
    }
}
