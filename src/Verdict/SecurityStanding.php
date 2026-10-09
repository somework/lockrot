<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

/**
 * Where one finding stands on security.
 *
 * @internal
 */
final class SecurityStanding
{
    public const VULNERABLE = 'vulnerable';

    private string $status;
    private string $check;
    private int $ignoredCount;
    /** @var array<string, int> */
    private array $counts;
    private ?string $fixKind;

    /**
     * @param string             $status `vulnerable`, `clear` or `unchecked`
     * @param string             $check  `complete`, `partial` or `not_run`
     * @param array<string, int> $counts the counted advisories by severity, every severity in display order first
     * @param ?string            $fixKind the hardest fix kind of the counted advisories, null when none counts
     */
    public function __construct(string $status, string $check, int $ignoredCount, array $counts, ?string $fixKind)
    {
        $this->status = $status;
        $this->check = $check;
        $this->ignoredCount = $ignoredCount;
        $this->counts = $counts;
        $this->fixKind = $fixKind;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function isVulnerable(): bool
    {
        return $this->status === self::VULNERABLE;
    }

    public function check(): string
    {
        return $this->check;
    }

    /** The advisories that Composer's audit ignore lists keep out of S9. */
    public function ignoredCount(): int
    {
        return $this->ignoredCount;
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        return $this->counts;
    }

    public function fixKind(): ?string
    {
        return $this->fixKind;
    }
}
