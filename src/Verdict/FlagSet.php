<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

use Lockrot\Allowlist\AllowlistEntry;
use Lockrot\Signal\Signal;

/**
 * The flags of one finding, derived from its signals. S2 and S4 collapse into one liveness word
 * (stale, silent). S1 or S3 make it `abandoned`, which hides the word that S2 and S4 give. An
 * allowlist entry accepts maintenance flags, and they stay listed. A marking that Composer's abandoned
 * ignore list removes is no S1, so it raises no flag here.
 *
 * @internal
 *
 * @phpstan-import-type Advisory from Score
 */
final class FlagSet
{
    public const ABANDONED = 'abandoned';
    public const SILENT = 'silent';
    public const PINNED = 'pinned';
    public const LEFT_BEHIND = 'left-behind';
    public const OLD_PROMISE = 'old-promise';
    public const STALE = 'stale';
    public const VULNERABLE = 'vulnerable';

    /** `degree.reasons` of `abandoned`, in this order. */
    public const MARKED = 'marked';
    public const ARCHIVED = 'archived';

    /** An entry that lists a liveness word also accepts the words after it (rule `counted`). */
    private const LIVENESS = [self::ABANDONED, self::SILENT, self::STALE];

    private const RAISES = [Signal::S5 => self::OLD_PROMISE, Signal::S6 => self::PINNED, Signal::S8 => self::LEFT_BEHIND];

    /** @var array<string, string> the level of each signal that fired, by id */
    private array $signals;
    /** @var list<string> */
    private array $acceptSet;
    /** @var list<Advisory> */
    private array $advisories;
    /** @var list<string> */
    private array $fired;
    private ?string $hidden;

    /**
     * @param array<string, string> $signals
     * @param list<string>          $acceptSet
     * @param list<Advisory>        $advisories
     */
    private function __construct(array $signals, array $acceptSet, array $advisories)
    {
        $this->signals = $signals;
        $this->acceptSet = $acceptSet;
        $this->advisories = $advisories;
        $word = self::livenessWord($signals);
        $abandoned = isset($signals[Signal::S1]) || isset($signals[Signal::S3]);
        $this->hidden = $abandoned ? $word : null;
        $fired = [$abandoned ? self::ABANDONED : $word];
        foreach (self::RAISES as $id => $flag) {
            $fired[] = isset($signals[$id]) ? $flag : null;
        }
        $fired[] = $advisories === [] ? null : self::VULNERABLE;
        $this->fired = array_values(array_intersect(ScoreModel::FLAG_ORDER, $fired));
    }

    /**
     * @param list<Signal>   $signals    the finding's signals: S1 to S6 and S8 raise flags, the others none
     * @param list<Advisory> $advisories the counted advisories: they raise `vulnerable`, which no entry accepts
     */
    public static function fromSignals(array $signals, ?AllowlistEntry $entry, array $advisories): self
    {
        $levels = [];
        foreach ($signals as $signal) {
            $levels[$signal->id()] = $signal->level();
        }

        return new self($levels, self::acceptedBy($entry), $advisories);
    }

    /** @return list<string> every flag that fired, accepted ones included, in flag order */
    public function fired(): array
    {
        return $this->fired;
    }

    /** @return list<string> the fired maintenance flags the entry accepts, in flag order */
    public function accepted(): array
    {
        return array_values(array_intersect($this->fired, $this->acceptSet));
    }

    /** @return list<string> the fired maintenance flags the entry does not accept, in flag order */
    public function countedMaintenance(): array
    {
        return array_values(array_diff(array_intersect($this->fired, array_keys(ScoreModel::POINTS)), $this->acceptSet));
    }

    /** @return list<string> every flag the entry accepts, fired or not, in flag order */
    public function acceptSet(): array
    {
        return $this->acceptSet;
    }

    /** @return list<Advisory> */
    public function advisories(): array
    {
        return $this->advisories;
    }

    /** The liveness word that S2 and S4 give while `abandoned` shows, null otherwise. */
    public function hidden(): ?string
    {
        return $this->hidden;
    }

    /** @return list<string> `degree.reasons` of `abandoned`: `marked` for S1, `archived` for S3 */
    public function reasons(): array
    {
        return array_keys(array_filter([self::MARKED => isset($this->signals[Signal::S1]), self::ARCHIVED => isset($this->signals[Signal::S3])]));
    }

    /** S2's level, null when S2 did not fire. */
    public function releaseLevel(): ?string
    {
        return $this->signals[Signal::S2] ?? null;
    }

    /**
     * The flags once the signals that raise $flag are gone, derived again under the same entry.
     * Removing `vulnerable` removes every advisory.
     */
    public function without(string $flag): self
    {
        $signals = array_diff_key($this->signals, array_flip(ScoreModel::RAISED_BY[$flag]));

        return new self($signals, $this->acceptSet, $flag === self::VULNERABLE ? [] : $this->advisories);
    }

    public function withoutAdvisory(string $id): self
    {
        return new self($this->signals, $this->acceptSet, array_values(array_filter($this->advisories, static fn (array $advisory): bool => $advisory['id'] !== $id)));
    }

    /** The same facts with $flag counted, not accepted: what the flag adds if counted. */
    public function counting(string $flag): self
    {
        return new self($this->signals, array_values(array_diff($this->acceptSet, [$flag])), $this->advisories);
    }

    /** @param array<string, string> $signals */
    private static function livenessWord(array $signals): ?string
    {
        $release = $signals[Signal::S2] ?? null;
        $push = $signals[Signal::S4] ?? null;
        if ($release === Signal::LEVEL_HIGH && $push === Signal::LEVEL_HIGH) {
            return self::SILENT;
        }

        return $release !== null || $push !== null ? self::STALE : null;
    }

    /** @return list<string> */
    private static function acceptedBy(?AllowlistEntry $entry): array
    {
        if ($entry === null) {
            return [];
        }
        $listed = $entry->flags() ?? array_keys(ScoreModel::POINTS);
        $accepted = $listed;
        foreach ($listed as $flag) {
            $at = array_search($flag, self::LIVENESS, true);
            if ($at !== false) {
                $accepted = array_merge($accepted, \array_slice(self::LIVENESS, $at));
            }
        }

        return array_values(array_intersect(array_keys(ScoreModel::POINTS), $accepted));
    }
}
