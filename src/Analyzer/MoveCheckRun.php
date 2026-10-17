<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Lockrot\Solver\MoveCheck;

/**
 * `run.move_check`: which Composer checked the run's moves, whether it blocked the versions that
 * an advisory affects, and the result of the baseline solve.
 *
 * @internal
 */
final class MoveCheckRun implements \JsonSerializable
{
    private ?string $composer;
    private bool $blocksAdvisories;
    private MoveCheck $baseline;

    public function __construct(?string $composer, bool $blocksAdvisories, MoveCheck $baseline)
    {
        $this->composer = $composer;
        $this->blocksAdvisories = $blocksAdvisories;
        $this->baseline = $baseline;
    }

    /** @return array{composer: ?string, blocks_advisories: bool, baseline: string, reason: ?string} */
    public function jsonSerialize(): array
    {
        return ['composer' => $this->composer, 'blocks_advisories' => $this->blocksAdvisories, 'baseline' => $this->baseline->result(), 'reason' => $this->baseline->reason()];
    }
}
