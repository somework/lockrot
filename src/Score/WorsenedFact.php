<?php

declare(strict_types=1);

namespace Lockrot\Score;

use Lockrot\Baseline\RecordedAdvisory;

/**
 * One counted fact that the baseline entry does not know, `finding.baseline.worsened_by[]`.
 *
 * @internal
 *
 * @phpstan-type Advisory array{severity: string, fix_kind: string}
 * @phpstan-type Shape array{kind: string, id: string, why: string, recorded: ?Advisory, now: ?Advisory}
 */
final class WorsenedFact implements \JsonSerializable
{
    public const FLAG = 'flag';
    public const ADVISORY = 'advisory';

    private string $kind;
    private string $id;
    private string $why;
    private ?RecordedAdvisory $recorded;
    private ?CountedAdvisory $now;

    private function __construct(string $kind, string $id, string $why, ?RecordedAdvisory $recorded, ?CountedAdvisory $now)
    {
        $this->kind = $kind;
        $this->id = $id;
        $this->why = $why;
        $this->recorded = $recorded;
        $this->now = $now;
    }

    public static function flag(string $flag): self
    {
        return new self(self::FLAG, $flag, FactState::NEW_FACT, null, null);
    }

    /**
     * @param CountedAdvisory $now with the severity bucket
     * @param string          $why `new`, `re_rated` or `fix_lost`
     */
    public static function advisory(CountedAdvisory $now, string $why, ?RecordedAdvisory $recorded): self
    {
        return new self(self::ADVISORY, $now->id(), $why, $recorded, $now);
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function why(): string
    {
        return $this->why;
    }

    /** @return Shape */
    public function jsonSerialize(): array
    {
        return [
            'kind' => $this->kind,
            'id' => $this->id,
            'why' => $this->why,
            'recorded' => $this->recorded === null ? null : ['severity' => $this->recorded->severity(), 'fix_kind' => $this->recorded->fixKind()],
            'now' => $this->now === null ? null : ['severity' => $this->now->severity(), 'fix_kind' => $this->now->fixKind()],
        ];
    }
}
