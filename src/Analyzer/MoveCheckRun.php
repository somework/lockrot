<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Lockrot\Solver\ComposerSolver;
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
    /** @var list<string> */
    private array $blockingDisabledBy;
    private MoveCheck $baseline;

    /** @param list<string> $blockingDisabledBy */
    public function __construct(?string $composer, bool $blocksAdvisories, array $blockingDisabledBy, MoveCheck $baseline)
    {
        $this->composer = $composer;
        $this->blocksAdvisories = $blocksAdvisories;
        $this->blockingDisabledBy = $blockingDisabledBy;
        $this->baseline = $baseline;
    }

    public function baseline(): MoveCheck
    {
        return $this->baseline;
    }

    /**
     * `ignored_platform` is {@see ComposerSolver::IGNORED_PLATFORM}: the machine that runs lockrot decides no move.
     *
     * @return array{composer: ?string, blocks_advisories: bool, blocking_disabled_by: list<string>, ignored_platform: non-empty-list<string>, baseline: string, reason: ?string}
     */
    public function jsonSerialize(): array
    {
        return ['composer' => $this->composer, 'blocks_advisories' => $this->blocksAdvisories, 'blocking_disabled_by' => $this->blockingDisabledBy, 'ignored_platform' => ComposerSolver::IGNORED_PLATFORM, 'baseline' => $this->baseline->result(), 'reason' => $this->baseline->reason()];
    }
}
