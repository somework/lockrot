<?php

declare(strict_types=1);

namespace Lockrot\Security;

/**
 * The fix of one advisory: an S9 row's `fix` (SPEC-0.14 5.3). A fix of kind `unknown` or `none`
 * names no release, so its branch, versions, php and {@see onInstalledBranch()} are null together
 * and {@see reason()} says why.
 *
 * @internal
 */
final class Fix
{
    public const UPDATE = 'update';
    public const UPGRADE = 'upgrade';
    public const RAISE_PHP = 'raise-php';
    public const BLOCKED = 'blocked';
    public const UNKNOWN = 'unknown';
    public const NONE = 'none';

    public const RELEASES_UNKNOWN = 'releases_unknown';
    public const NOT_FROM_COMPOSER_REPOSITORY = 'not_from_composer_repository';
    public const AFFECTED_RANGE_UNKNOWN = 'affected_range_unknown';
    public const NO_RELEASE_OUTSIDE_RANGE = 'no_release_outside_range';

    public const BLOCKED_BY_TARGET = 'target';

    /** @var self::UPDATE|self::UPGRADE|self::RAISE_PHP|self::BLOCKED|self::UNKNOWN|self::NONE */
    private string $kind;
    private ?Candidate $candidate;
    private ?string $newest;
    private ?bool $onInstalledBranch;
    private ?string $reason;

    /** @param self::UPDATE|self::UPGRADE|self::RAISE_PHP|self::BLOCKED|self::UNKNOWN|self::NONE $kind */
    private function __construct(string $kind, ?Candidate $candidate, ?string $newest, ?bool $onInstalledBranch, ?string $reason)
    {
        $this->kind = $kind;
        $this->candidate = $candidate;
        $this->newest = $newest;
        $this->onInstalledBranch = $onInstalledBranch;
        $this->reason = $reason;
    }

    /**
     * @param string  $newest          the newest release of the candidate's branch
     * @param ?string $installedBranch the installed branch key, null for a branch snapshot
     */
    public static function to(Candidate $candidate, string $newest, ?string $installedBranch): self
    {
        return new self($candidate->kind(), $candidate, $newest, $candidate->branchKey() === $installedBranch, null);
    }

    /** @param self::RELEASES_UNKNOWN|self::NOT_FROM_COMPOSER_REPOSITORY|self::AFFECTED_RANGE_UNKNOWN $reason */
    public static function unknown(string $reason): self
    {
        return new self(self::UNKNOWN, null, null, null, $reason);
    }

    public static function none(): self
    {
        return new self(self::NONE, null, null, null, self::NO_RELEASE_OUTSIDE_RANGE);
    }

    /**
     * The ease order of SPEC-0.14 5.3: update, upgrade, raise-php with no holder, raise-php with
     * holders, blocked. Lower is easier.
     *
     * @param self::UPDATE|self::UPGRADE|self::RAISE_PHP|self::BLOCKED $kind
     */
    public static function easeRank(string $kind, bool $held): int
    {
        switch ($kind) {
            case self::UPDATE:
                return 0;
            case self::UPGRADE:
                return 1;
            case self::RAISE_PHP:
                return $held ? 3 : 2;
            default:
                return 4;
        }
    }

    /** @return self::UPDATE|self::UPGRADE|self::RAISE_PHP|self::BLOCKED|self::UNKNOWN|self::NONE */
    public function kind(): string
    {
        return $this->kind;
    }

    public function candidate(): ?Candidate
    {
        return $this->candidate;
    }

    /** The branch of the fixing release, `3.x`. */
    public function toBranch(): ?string
    {
        return $this->candidate === null ? null : $this->candidate->branch();
    }

    /** The lowest fixing release on {@see toBranch()}, as the repository writes it. */
    public function version(): ?string
    {
        return $this->candidate === null ? null : $this->candidate->release()->pretty();
    }

    /** That branch's newest release: what `composer update` installs on it. */
    public function newest(): ?string
    {
        return $this->newest;
    }

    public function onInstalledBranch(): ?bool
    {
        return $this->onInstalledBranch;
    }

    public function php(): ?string
    {
        return $this->candidate === null ? null : $this->candidate->php();
    }

    /** @return list<Holder> */
    public function heldBy(): array
    {
        return $this->candidate === null ? [] : $this->candidate->heldBy();
    }

    public function blockedBy(): ?string
    {
        return $this->candidate === null ? null : $this->candidate->blockedBy();
    }

    /** Set for `unknown` and `none`, else null. An open set: a change of the fix model can add one. */
    public function reason(): ?string
    {
        return $this->reason;
    }
}
