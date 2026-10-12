<?php

declare(strict_types=1);

namespace Lockrot\Signal;

use Lockrot\Data\Abandoned\AbandonedIgnoreRule;
use Lockrot\Signal\Data\AbandonedData;

/**
 * Composer's abandoned ignore list removed this package's S1. The record keeps the list's match and
 * the marking that S1 carries without the list, so the fact is still shown, from one place
 * (docs/verdicts.md#abandoned).
 *
 * @internal
 */
final class AbandonedIgnored implements \JsonSerializable
{
    public const MARKED_BY_REPOSITORY = 'repository';
    public const MARKED_BY_LOCK = 'lock';

    private string $by;
    /** @var list<AbandonedIgnoreRule> */
    private array $rules;
    private string $markedBy;
    private ?string $replacement;
    private ?string $replacementUrl;

    /** @param list<AbandonedIgnoreRule> $rules */
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

    /** @return list<AbandonedIgnoreRule> */
    public function rules(): array
    {
        return $this->rules;
    }

    /** `repository` or `lock`: the source that S1 reads. */
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

    /**
     * report-2's `finding.abandoned_ignored`, whose `marking` is S1's data.
     *
     * @return array{by: string, rules: list<AbandonedIgnoreRule>, marking: AbandonedData}
     */
    public function jsonSerialize(): array
    {
        return ['by' => $this->by, 'rules' => $this->rules, 'marking' => new AbandonedData($this->markedBy, $this->replacement, $this->replacementUrl)];
    }
}
