<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Lockrot\Legacy\PriorityBasis013;

/**
 * A finding that reached a grade value under the rules of 0.13 and no longer does: one entry of
 * the `verdict_rules_changed` note's `findings`.
 *
 * @internal
 */
final class ChangedVerdict implements \JsonSerializable
{
    private string $package;
    private string $version;
    private string $legacyVerdict;
    private PriorityBasis013 $legacyBasis;
    private string $verdict;
    private int $scoreTotal;
    private string $scoreText;

    public function __construct(string $package, string $version, string $legacyVerdict, PriorityBasis013 $legacyBasis, string $verdict, int $scoreTotal, string $scoreText)
    {
        $this->package = $package;
        $this->version = $version;
        $this->legacyVerdict = $legacyVerdict;
        $this->legacyBasis = $legacyBasis;
        $this->verdict = $verdict;
        $this->scoreTotal = $scoreTotal;
        $this->scoreText = $scoreText;
    }

    public function package(): string
    {
        return $this->package;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function legacyBasis(): PriorityBasis013
    {
        return $this->legacyBasis;
    }

    public function scoreText(): string
    {
        return $this->scoreText;
    }

    /** @return array{package: string, version: string, legacy: array{verdict: string, priority: string, basis: PriorityBasis013}, verdict: string, score_total: int, score_text: string} */
    public function jsonSerialize(): array
    {
        return [
            'package' => $this->package,
            'version' => $this->version,
            'legacy' => ['verdict' => $this->legacyVerdict, 'priority' => $this->legacyBasis->priority(), 'basis' => $this->legacyBasis],
            'verdict' => $this->verdict,
            'score_total' => $this->scoreTotal,
            'score_text' => $this->scoreText,
        ];
    }
}
