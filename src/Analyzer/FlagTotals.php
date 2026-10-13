<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Lockrot\Verdict\Finding;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\ScoreModel;

/**
 * report-2's root `flags` block. Per flag: the graded findings that count it, the findings it
 * leads, the fired flags the allowlist accepts and the counting findings by grade. `vulnerable`
 * leads nothing and no entry accepts it.
 *
 * @internal
 */
final class FlagTotals implements \JsonSerializable
{
    /** @var array<string, int> by flag, in {@see ScoreModel::FLAG_ORDER} */
    private array $carrying;
    /** @var array<string, int> */
    private array $leading;
    /** @var array<string, int> */
    private array $acceptedAll;
    /** @var array<string, int> */
    private array $acceptedGraded;
    /** @var array<string, array<string, int>> */
    private array $byVerdict;

    /**
     * @param array<string, int>                $carrying
     * @param array<string, int>                $leading
     * @param array<string, int>                $acceptedAll
     * @param array<string, int>                $acceptedGraded
     * @param array<string, array<string, int>> $byVerdict
     */
    private function __construct(array $carrying, array $leading, array $acceptedAll, array $acceptedGraded, array $byVerdict)
    {
        $this->carrying = $carrying;
        $this->leading = $leading;
        $this->acceptedAll = $acceptedAll;
        $this->acceptedGraded = $acceptedGraded;
        $this->byVerdict = $byVerdict;
    }

    /** @param list<Finding> $findings */
    public static function of(array $findings): self
    {
        $carrying = array_fill_keys(ScoreModel::FLAG_ORDER, 0);
        $leading = $carrying;
        $acceptedAll = $carrying;
        $acceptedGraded = $carrying;
        $byVerdict = array_fill_keys(ScoreModel::FLAG_ORDER, array_fill_keys(ScoreModel::GRADES, 0));
        foreach ($findings as $finding) {
            foreach ($finding->flagIds() as $flag) {
                ++$carrying[$flag];
                ++$byVerdict[$flag][$finding->verdict()];
            }
            $lead = $finding->lead();
            if ($lead !== null) {
                ++$leading[$lead];
            }
            foreach ($finding->flags()->accepted() as $flag) {
                ++$acceptedAll[$flag];
                $acceptedGraded[$flag] += $finding->isGraded() ? 1 : 0;
            }
        }

        return new self($carrying, $leading, $acceptedAll, $acceptedGraded, $byVerdict);
    }

    /** The graded findings that count the flag, one of {@see ScoreModel::FLAG_ORDER}. */
    public function carrying(string $flag): int
    {
        return $this->carrying[$flag];
    }

    /** @return array<string, array{carrying: int, leading: ?int, accepted: ?array{all: int, in_graded: int}, by_verdict: array<string, int>}> */
    public function jsonSerialize(): array
    {
        $out = [];
        foreach (ScoreModel::FLAG_ORDER as $flag) {
            $maintenance = $flag !== FlagSet::VULNERABLE;
            $out[$flag] = [
                'carrying' => $this->carrying[$flag],
                'leading' => $maintenance ? $this->leading[$flag] : null,
                'accepted' => $maintenance ? ['all' => $this->acceptedAll[$flag], 'in_graded' => $this->acceptedGraded[$flag]] : null,
                'by_verdict' => $this->byVerdict[$flag],
            ];
        }

        return $out;
    }
}
