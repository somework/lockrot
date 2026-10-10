<?php

declare(strict_types=1);

namespace Lockrot\Score;

use Lockrot\Verdict\ScoreModel;

/**
 * What one part, maintenance or security, adds to the score, and the grade that the part gives alone.
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

    /** The whole points of the part alone, its half point dropped. */
    public function aloneTotal(): int
    {
        return intdiv($this->contributionHalves, 2);
    }

    /** The grade that the part gives alone, null below the lowest band. */
    public function aloneVerdict(): ?string
    {
        return ScoreModel::band($this->aloneTotal());
    }

    /** @return Shape|SecurityShape */
    public function jsonSerialize(): array
    {
        $out = ['status' => $this->status, 'contribution' => HalfPoints::json($this->contributionHalves), 'alone' => ['total' => $this->aloneTotal(), 'verdict' => $this->aloneVerdict()]];

        return $this->of === null ? $out : $out + ['of' => $this->of, 'tied' => $this->tied];
    }
}
