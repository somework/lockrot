<?php

declare(strict_types=1);

namespace Lockrot\Score;

use Lockrot\Verdict\ScoreModel;

/**
 * What one part, maintenance or security, adds to the score, and the grade it would give alone.
 * The security part also counts the advisories and names the ones tied with the deciding one.
 *
 * @internal
 *
 * @phpstan-type Shape array{status: string, contribution: int|float, alone: array{total: int, verdict: ?string}}
 * @phpstan-type SecurityShape array{status: string, contribution: int|float, alone: array{total: int, verdict: ?string}, of: int, tied: list<string>}
 */
final class Part implements \JsonSerializable
{
    public const COUNTED = 'counted';

    private string $status;
    private int $contributionHalves;
    private ?int $of;
    /** @var list<string> */
    private array $tied;

    /** @param list<string> $tied */
    private function __construct(string $status, int $contributionHalves, ?int $of, array $tied)
    {
        $this->status = $status;
        $this->contributionHalves = $contributionHalves;
        $this->of = $of;
        $this->tied = $tied;
    }

    public static function maintenance(string $status, int $contributionHalves): self
    {
        return new self($status, $contributionHalves, null, []);
    }

    /**
     * @param int          $of   the counted advisories
     * @param list<string> $tied the ids of the other counted advisories with the deciding one's points
     */
    public static function security(string $status, int $contributionHalves, int $of, array $tied): self
    {
        return new self($status, $contributionHalves, $of, $tied);
    }

    public function isCounted(): bool
    {
        return $this->status === self::COUNTED;
    }

    /** The counted advisories of the security part, null for maintenance. */
    public function of(): ?int
    {
        return $this->of;
    }

    /** @return Shape|SecurityShape */
    public function jsonSerialize(): array
    {
        $alone = intdiv($this->contributionHalves, 2);
        $out = ['status' => $this->status, 'contribution' => HalfPoints::json($this->contributionHalves), 'alone' => ['total' => $alone, 'verdict' => ScoreModel::band($alone)]];

        return $this->of === null ? $out : $out + ['of' => $this->of, 'tied' => $this->tied];
    }
}
