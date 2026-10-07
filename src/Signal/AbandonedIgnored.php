<?php

declare(strict_types=1);

namespace Lockrot\Signal;

/**
 * Composer's abandoned ignore list removed this package's S1. The record keeps the list's match and
 * the marking S1 would have carried, so the fact is still shown, from one place
 * (docs/verdicts.md#abandoned).
 *
 * @internal
 */
final class AbandonedIgnored
{
    public const MARKED_BY_REPOSITORY = 'repository';
    public const MARKED_BY_LOCK = 'lock';

    private string $by;
    /** @var list<array{pattern: string, reason: ?string, constraints: list<string>}> */
    private array $rules;
    private string $markedBy;
    private ?string $replacement;
    private ?string $replacementUrl;

    /** @param list<array{pattern: string, reason: ?string, constraints: list<string>}> $rules */
    public function __construct(string $by, array $rules, string $markedBy, ?string $replacement, ?string $replacementUrl)
    {
        $this->by = $by;
        $this->rules = $rules;
        $this->markedBy = $markedBy;
        $this->replacement = $replacement;
        $this->replacementUrl = $replacementUrl;
    }

    public function by(): string
    {
        return $this->by;
    }

    /** @return list<array{pattern: string, reason: ?string, constraints: list<string>}> */
    public function rules(): array
    {
        return $this->rules;
    }

    /** `repository` or `lock`: the source S1 would have read. */
    public function markedBy(): string
    {
        return $this->markedBy;
    }

    public function replacement(): ?string
    {
        return $this->replacement;
    }

    public function replacementUrl(): ?string
    {
        return $this->replacementUrl;
    }
}
