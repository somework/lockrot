<?php

declare(strict_types=1);

namespace Lockrot\Score;

/**
 * The score after a move (`if_applied`): an engine rerun, never a subtraction. A branch row and
 * `security.partial` write it without `lead` and `deciding_advisory` ({@see asRow()}).
 *
 * @internal
 */
final class IfApplied implements \JsonSerializable
{
    public const CLEARS_HOLD = 'clears_hold';
    public const COMPOSER_RESOLVES = 'composer_resolves';
    public const NO_NEW_ADVISORIES = 'no_new_advisories';

    private int $total;
    private string $verdict;
    private ?string $lead;
    private ?string $decidingAdvisory;
    private bool $atMost;
    /** @var list<string> */
    private array $assumes;
    private bool $row = false;

    /**
     * @param bool         $atMost  the move leaves a fact unverified, so the total is an upper bound
     * @param list<string> $assumes in the order of the constants
     */
    public function __construct(int $total, string $verdict, ?string $lead, ?string $decidingAdvisory, bool $atMost, array $assumes)
    {
        $this->total = $total;
        $this->verdict = $verdict;
        $this->lead = $lead;
        $this->decidingAdvisory = $decidingAdvisory;
        $this->atMost = $atMost;
        $this->assumes = $assumes;
    }

    public function asRow(): self
    {
        $clone = clone $this;
        $clone->row = true;

        return $clone;
    }

    public function total(): int
    {
        return $this->total;
    }

    public function verdict(): string
    {
        return $this->verdict;
    }

    public function atMost(): bool
    {
        return $this->atMost;
    }

    /** @return list<string> */
    public function assumes(): array
    {
        return $this->assumes;
    }

    /** @return array{total: int, verdict: string, lead?: ?string, deciding_advisory?: ?string, at_most: bool, assumes: list<string>} */
    public function jsonSerialize(): array
    {
        if ($this->row) {
            return ['total' => $this->total, 'verdict' => $this->verdict, 'at_most' => $this->atMost, 'assumes' => $this->assumes];
        }

        return [
            'total' => $this->total,
            'verdict' => $this->verdict,
            'lead' => $this->lead,
            'deciding_advisory' => $this->decidingAdvisory,
            'at_most' => $this->atMost,
            'assumes' => $this->assumes,
        ];
    }
}
