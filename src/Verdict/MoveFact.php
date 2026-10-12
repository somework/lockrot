<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

/**
 * One entry of a move's `clears[]`, `unverified[]`, `leaves[]` or `adds[]`: a flag or an advisory,
 * with the basis that the move clears or adds it on, or the reason lockrot could not check it.
 *
 * @internal
 */
final class MoveFact implements \JsonSerializable
{
    public const FLAG = 'flag';
    public const ADVISORY = 'advisory';

    public const BRANCH_RELEASING = 'branch_releasing';
    public const RELEASED_AFTER_GA = 'released_after_ga';
    public const OUTSIDE_RANGE = 'outside_range';
    public const TAGS_ONLY = 'tags_only';
    public const RELEASED_BEFORE_GA = 'released_before_ga';
    public const BRANCH_NOT_RELEASING = 'branch_not_releasing';
    public const UNDATED = 'undated';
    public const NO_RELEASE_DATA = 'no_release_data';

    /** @var self::FLAG|self::ADVISORY */
    private string $kind;
    private string $id;
    /** `basis`, `reason` or null for a `leaves[]` entry */
    private ?string $key;
    private ?string $value;

    /** @param self::FLAG|self::ADVISORY $kind */
    private function __construct(string $kind, string $id, ?string $key, ?string $value)
    {
        $this->kind = $kind;
        $this->id = $id;
        $this->key = $key;
        $this->value = $value;
    }

    /**
     * A `clears[]` or `adds[]` entry.
     *
     * @param self::FLAG|self::ADVISORY $kind
     */
    public static function on(string $kind, string $id, string $basis): self
    {
        return new self($kind, $id, 'basis', $basis);
    }

    /**
     * An `unverified[]` entry.
     *
     * @param self::FLAG|self::ADVISORY $kind
     */
    public static function unchecked(string $kind, string $id, string $reason): self
    {
        return new self($kind, $id, 'reason', $reason);
    }

    /**
     * A `leaves[]` entry.
     *
     * @param self::FLAG|self::ADVISORY $kind
     */
    public static function left(string $kind, string $id): self
    {
        return new self($kind, $id, null, null);
    }

    /** @return self::FLAG|self::ADVISORY */
    public function kind(): string
    {
        return $this->kind;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function isFlag(): bool
    {
        return $this->kind === self::FLAG;
    }

    /** The basis of a `clears[]` or `adds[]` entry, null on the other lists. */
    public function basis(): ?string
    {
        return $this->key === 'basis' ? $this->value : null;
    }

    /** @return array<string, ?string> `kind`, `id`, then `basis` or `reason` */
    public function jsonSerialize(): array
    {
        $out = ['kind' => $this->kind, 'id' => $this->id];
        if ($this->key !== null) {
            $out[$this->key] = $this->value;
        }

        return $out;
    }
}
