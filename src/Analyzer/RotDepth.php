<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Lockrot\Lock\ProjectConfig;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Score;

/**
 * report-2's root `rot_depth`: where the graded findings sit below composer.json, counted from the
 * findings. No score, gate, sort or baseline reads it.
 *
 * @internal
 */
final class RotDepth implements \JsonSerializable
{
    private int $flagged = 0;
    private int $measured = 0;
    /** @var array<string, int> `unreached` and `no_composer_json` */
    private array $unmeasured = [Finding::UNREACHED => 0, Finding::NO_COMPOSER_JSON => 0];
    /** @var array<string, int> `direct`, `transitive` and `unreached` */
    private array $byReach = [Score::DIRECT => 0, Score::TRANSITIVE => 0, Score::UNREACHED => 0];
    private int $notInComposerJson = 0;
    private int $requireDevNotWalked = 0;
    private int $development = 0;
    /** @var array<int, int> depth => graded findings */
    private array $byDepth = [];
    /** @var list<Finding> */
    private array $deepest = [];

    private function __construct()
    {
    }

    /** @param list<Finding> $findings */
    public static function of(array $findings): self
    {
        $block = new self();
        foreach ($findings as $finding) {
            if ($finding->isGraded()) {
                $block->count($finding);
            }
        }
        $max = $block->maxDepth();
        // At depth 0 the graded findings are the direct ones, and a chain of one names no path.
        $deepest = $max === null || $max === 0 ? [] : array_filter($findings, static fn (Finding $finding): bool => $finding->isGraded() && $finding->depth() === $max);
        usort($deepest, static fn (Finding $a, Finding $b): int => strcmp($a->package(), $b->package()));
        $block->deepest = $deepest;

        return $block;
    }

    private function count(Finding $finding): void
    {
        ++$this->flagged;
        ++$this->byReach[$finding->reach()];
        if ($finding->isDev()) {
            ++$this->development;
        }
        $depth = $finding->depth();
        if ($depth === null) {
            ++$this->unmeasured[$finding->isComposerJsonRead() ? Finding::UNREACHED : Finding::NO_COMPOSER_JSON];
        } else {
            ++$this->measured;
            $this->byDepth[$depth] = ($this->byDepth[$depth] ?? 0) + 1;
        }
        if ($finding->isDirect()) {
            return;
        }
        if ($finding->namedIn() === [ProjectConfig::REQUIRE_DEV]) {
            ++$this->requireDevNotWalked;
        } elseif ($finding->namedIn() === [] && $finding->isComposerJsonRead()) {
            ++$this->notInComposerJson;
        }
    }

    public function flagged(): int
    {
        return $this->flagged;
    }

    private function maxDepth(): ?int
    {
        return $this->byDepth === [] ? null : max(array_keys($this->byDepth));
    }

    /** @return list<Finding> the graded findings at {@see maxDepth()}, by package name, bytewise */
    public function deepest(): array
    {
        return $this->deepest;
    }

    /** @return array{flagged: int, measured: int, unmeasured: array<string, int>, by_reach: array<string, int>, not_in_composer_json: int, require_dev_not_walked: int, production: int, development: int, by_depth: list<array{depth: int, flagged: int}>, max_depth: ?int, deepest: list<array{package: string, chain: list<string>}>} */
    public function jsonSerialize(): array
    {
        $max = $this->maxDepth();
        $byDepth = [];
        foreach ($max === null ? [] : range(0, $max) as $depth) {
            $byDepth[] = ['depth' => $depth, 'flagged' => $this->byDepth[$depth] ?? 0];
        }

        return [
            'flagged' => $this->flagged,
            'measured' => $this->measured,
            'unmeasured' => $this->unmeasured,
            'by_reach' => $this->byReach,
            'not_in_composer_json' => $this->notInComposerJson,
            'require_dev_not_walked' => $this->requireDevNotWalked,
            'production' => $this->flagged - $this->development,
            'development' => $this->development,
            'by_depth' => $byDepth,
            'max_depth' => $max,
            'deepest' => array_map(static fn (Finding $finding): array => ['package' => $finding->package(), 'chain' => $finding->chain()], $this->deepest),
        ];
    }
}
