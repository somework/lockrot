<?php

declare(strict_types=1);

namespace Lockrot\Allowlist;

use Lockrot\Signal\Data\Check;

/**
 * An S10 check that an `accept-unchecked` entry accepted: `finding.unchecked_accepted[]`, with the
 * entry S10 would have held and the entry that accepted it.
 *
 * @internal
 *
 * @phpstan-import-type Shape from UncheckedAcceptanceEntry as AcceptedBy
 *
 * @phpstan-type Shape array{check: string, reason: string, blocks: list<string>, accepted_by: UncheckedAcceptanceEntry}
 */
final class AcceptedCheck implements \JsonSerializable
{
    private Check $unchecked;
    private UncheckedAcceptanceEntry $entry;

    public function __construct(Check $unchecked, UncheckedAcceptanceEntry $entry)
    {
        $this->unchecked = $unchecked;
        $this->entry = $entry;
    }

    public function unchecked(): Check
    {
        return $this->unchecked;
    }

    public function entry(): UncheckedAcceptanceEntry
    {
        return $this->entry;
    }

    /** The skip that stands for it in `checks_skipped[]`. */
    public function skip(): Check
    {
        return new Check($this->unchecked->check(), 'accepted_by_config', $this->unchecked->blocks());
    }

    /** @return Shape */
    public function jsonSerialize(): array
    {
        return ['check' => $this->unchecked->check(), 'reason' => $this->unchecked->reason(), 'blocks' => $this->unchecked->blocks(), 'accepted_by' => $this->entry];
    }
}
