<?php

declare(strict_types=1);

namespace Lockrot\Score;

use Lockrot\Verdict\ScoreModel;

/**
 * Which counted maintenance flags a baseline entry covers: the recorded lead and every flag after it
 * in flag order (SPEC §6.4, "Coverage"). It is 0.13's verdict rule written over flags.
 *
 * @internal
 */
final class Coverage
{
    private ?string $recordedLead;

    private function __construct(?string $recordedLead)
    {
        $this->recordedLead = $recordedLead;
    }

    /**
     * @param ?string $recordedLead the entry's first flag in flag order, null for an entry without a flag
     *
     * @throws \InvalidArgumentException for a lead that is not a maintenance flag
     */
    public static function under(?string $recordedLead): self
    {
        if ($recordedLead !== null && !isset(ScoreModel::POINTS[$recordedLead])) {
            throw new \InvalidArgumentException('not a maintenance flag: '.$recordedLead);
        }

        return new self($recordedLead);
    }

    public function recordedLead(): ?string
    {
        return $this->recordedLead;
    }

    public function covers(string $flag): bool
    {
        return $this->recordedLead !== null
            && array_search($this->recordedLead, ScoreModel::FLAG_ORDER, true) <= array_search($flag, ScoreModel::FLAG_ORDER, true);
    }

    /**
     * @param list<string> $counted the counted maintenance flags
     *
     * @return list<string> a prefix of $counted in flag order: the covered flags are a tail of it
     */
    public function uncovered(array $counted): array
    {
        return array_values(array_filter($counted, fn (string $flag): bool => !$this->covers($flag)));
    }
}
