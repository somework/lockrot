<?php

declare(strict_types=1);

namespace Lockrot\Data\Abandoned;

/**
 * One pattern of Composer's abandoned ignore list.
 *
 * @internal
 */
final class AbandonedIgnoreRule implements \JsonSerializable
{
    private string $pattern;
    private ?string $reason;
    /** @var list<string> */
    private array $constraints;

    /**
     * @param ?string      $reason      as Composer merges the pattern's reasons for audit
     * @param list<string> $constraints written on the pattern's audit rules: `composer audit` does not apply them, and neither does lockrot
     */
    public function __construct(string $pattern, ?string $reason, array $constraints)
    {
        $this->pattern = $pattern;
        $this->reason = $reason;
        $this->constraints = $constraints;
    }

    /** As written: `*` is a wildcard. */
    public function pattern(): string
    {
        return $this->pattern;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    /** @return list<string> */
    public function constraints(): array
    {
        return $this->constraints;
    }

    /** @return array{pattern: string, reason: ?string, constraints: list<string>} */
    public function jsonSerialize(): array
    {
        return ['pattern' => $this->pattern, 'reason' => $this->reason, 'constraints' => $this->constraints];
    }
}
