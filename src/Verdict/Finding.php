<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

use Lockrot\Signal\Signal;

final class Finding
{
    /**
     * The verdicts under which a security advisory will not be fixed upstream: the package is
     * abandoned, nobody has touched it for years, or the installed branch is the one the upstream
     * moved on from. `pinned` and `old-promise` are not here — a branch snapshot or an open php
     * constraint says nothing about whether a fix is coming.
     */
    public const NO_FIX_VERDICTS = [Verdict::ABANDONED, Verdict::SILENT, Verdict::LEFT_BEHIND];

    /**
     * The signals that can decide each verdict ({@see VerdictEngine}); the evidence line opens with
     * them, so the reason for the label is read before the rest of what was observed.
     */
    private const DECIDING = [
        Verdict::ABANDONED => [Signal::S1, Signal::S3],
        Verdict::SILENT => [Signal::S2, Signal::S4],
        Verdict::PINNED => [Signal::S6],
        Verdict::LEFT_BEHIND => [Signal::S8],
        Verdict::OLD_PROMISE => [Signal::S5],
        Verdict::STALE => [Signal::S2, Signal::S4, Signal::S8],
    ];

    private string $package;
    private string $version;
    private string $verdict;
    /** @var list<Signal> */
    private array $signals;
    /** @var list<string> */
    private array $chain;
    private ?string $allowlistReason;
    private ?\DateTimeImmutable $dataDate;
    private ?string $note;
    /** Whether the package is installed only for development (`packages-dev` in the lock). */
    private bool $dev;
    /**
     * The project's direct requirements from which the package is reachable, sorted by name — the
     * package itself among them when it is direct. Empty exactly when the chain is: nothing in the
     * project reaches the package.
     *
     * @var list<string>
     */
    private array $directDependents;

    /**
     * @param list<Signal> $signals
     * @param list<string> $chain
     * @param list<string> $directDependents
     */
    public function __construct(string $package, string $version, string $verdict, array $signals, array $chain, ?string $allowlistReason, ?\DateTimeImmutable $dataDate, ?string $note = null, bool $dev = false, array $directDependents = [])
    {
        $this->package = $package;
        $this->version = $version;
        $this->verdict = $verdict;
        $this->signals = $signals;
        $this->chain = $chain;
        $this->allowlistReason = $allowlistReason;
        $this->dataDate = $dataDate;
        $this->note = $note;
        $this->dev = $dev;
        $this->directDependents = $directDependents;
    }

    /**
     * The same finding with a different signal list — how the transitive-exposure pass adds S7 to a
     * direct requirement after every verdict is known. A new instance: the verdict, which was decided
     * from the original signals, is deliberately left as it is.
     *
     * @param list<Signal> $signals
     */
    public function withSignals(array $signals): self
    {
        $clone = clone $this;
        $clone->signals = $signals;

        return $clone;
    }

    public function package(): string
    {
        return $this->package;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function verdict(): string
    {
        return $this->verdict;
    }

    /** @return list<Signal> */
    public function signals(): array
    {
        return $this->signals;
    }

    /** @return list<string> */
    public function chain(): array
    {
        return $this->chain;
    }

    public function allowlistReason(): ?string
    {
        return $this->allowlistReason;
    }

    public function dataDate(): ?\DateTimeImmutable
    {
        return $this->dataDate;
    }

    public function note(): ?string
    {
        return $this->note;
    }

    public function isDirect(): bool
    {
        return \count($this->chain) === 1;
    }

    public function isDev(): bool
    {
        return $this->dev;
    }

    /** @return list<string> */
    public function directDependents(): array
    {
        return $this->directDependents;
    }

    /**
     * The direct requirements the chain does not already name: the other ways the project reaches
     * the package. For a direct package that is every other root that also reaches it; for a
     * transitive one every root but the one its chain starts from. Removing the chain's root from
     * composer.json would leave the package installed through any of these.
     *
     * @return list<string>
     */
    public function otherDirectDependents(): array
    {
        $root = $this->chain[0] ?? null;

        return array_values(array_filter($this->directDependents, static fn (string $name): bool => $name !== $root));
    }

    /** Derived, never stored: the priority is a view of the verdict, the chain, the dev flag and S9. */
    public function priority(): string
    {
        return Priority::of($this->verdict, $this->isDirect(), $this->dev, $this->hasUnfixableAdvisory());
    }

    /**
     * A security advisory affects the installed version (S9) and no fix will come: the verdict is
     * one under which nobody publishes fixes ({@see self::NO_FIX_VERDICTS}), or S8 says the
     * installed branch is one the upstream left — at either level, since a branch quiet for three
     * years while a higher one releases is not where the fix lands whatever the verdict ended up
     * as. What raises the priority one step and adds `no fix expected` to the evidence.
     */
    public function hasUnfixableAdvisory(): bool
    {
        $hasAdvisory = false;
        $noFix = \in_array($this->verdict, self::NO_FIX_VERDICTS, true);
        foreach ($this->signals as $signal) {
            if ($signal->id() === Signal::S9) {
                $hasAdvisory = true;
            } elseif ($signal->id() === Signal::S8) {
                $noFix = true;
            }
        }

        return $hasAdvisory && $noFix && Verdict::flagged($this->verdict);
    }

    /**
     * What was observed about the package itself — every signal but S7, the ones that decided the
     * verdict first ({@see self::DECIDING}), the rest in signal order — and, when nothing was, the
     * note that says why (not from a Composer repository, metadata unavailable, …).
     */
    public function ownEvidence(): string
    {
        $parts = [];
        foreach ($this->ownSignalsDecidingFirst() as $signal) {
            $parts[] = $signal->summary();
            if ($signal->id() === Signal::S9 && $this->hasUnfixableAdvisory()) {
                $parts[] = 'no fix expected';
            }
        }
        if ($parts === []) {
            return $this->note ?? '';
        }

        return implode('; ', $parts);
    }

    /** @return list<Signal> */
    private function ownSignalsDecidingFirst(): array
    {
        $deciding = self::DECIDING[$this->verdict] ?? [];
        $first = [];
        $rest = [];
        foreach ($this->signals as $signal) {
            if ($signal->id() === Signal::S7) {
                continue;
            }
            if (\in_array($signal->id(), $deciding, true)) {
                $first[] = $signal;
            } else {
                $rest[] = $signal;
            }
        }

        return array_merge($first, $rest);
    }

    /**
     * {@see ownEvidence()} followed by what the package pulls in (S7), when it is a direct
     * requirement that does. The note is kept ahead of S7 rather than replaced by it: a path or VCS
     * package that pulls in flagged packages is still "not from a Composer repository, not checked".
     */
    public function evidence(): string
    {
        $parts = [];
        $own = $this->ownEvidence();
        if ($own !== '') {
            $parts[] = $own;
        }
        foreach ($this->signals as $signal) {
            if ($signal->id() === Signal::S7) {
                $parts[] = $signal->summary();
            }
        }

        return implode('; ', $parts);
    }

    /**
     * {@see evidence()} followed by `allowlisted: <reason>` when an allowlist entry decided the
     * verdict — the one line the table, markdown, github, gitlab and SARIF formats print.
     */
    public function evidenceLine(): string
    {
        if ($this->allowlistReason === null) {
            return $this->evidence();
        }
        $evidence = $this->evidence();

        return ($evidence === '' ? '' : $evidence.'; ').'allowlisted: '.$this->allowlistReason;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $signals = [];
        foreach ($this->signals as $signal) {
            $signals[] = ['id' => $signal->id(), 'level' => $signal->level(), 'summary' => $signal->summary(), 'data' => $signal->data()];
        }

        return [
            'package' => $this->package, 'version' => $this->version, 'verdict' => $this->verdict,
            'priority' => $this->priority(), 'direct' => $this->isDirect(), 'dev' => $this->dev,
            'signals' => $signals, 'chain' => $this->chain, 'direct_dependents' => $this->directDependents,
            'evidence' => $this->evidence(),
            'allowlist_reason' => $this->allowlistReason, 'note' => $this->note,
            'data_date' => $this->dataDate === null ? null : $this->dataDate->format(\DATE_ATOM),
        ];
    }
}
