<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

use Composer\Package\Loader\ValidatingArrayLoader;
use Lockrot\Analyzer\Libyears;
use Lockrot\Analyzer\LibyearsMeasurement;
use Lockrot\Data\Repository\ReleaseBranch;
use Lockrot\Lock\PackageOrigin;
use Lockrot\Signal\Signal;

/** @internal */
final class Finding
{
    /**
     * The verdicts under which a security advisory will not be fixed upstream: the package is
     * abandoned, nobody has touched it for years, or the installed branch is the one the upstream
     * moved on from. `pinned` and `old-promise` are not here — a branch snapshot or an open php
     * constraint says nothing about whether a fix is coming.
     */
    public const NO_FIX_VERDICTS = [Verdict::ABANDONED, Verdict::SILENT, Verdict::LEFT_BEHIND];

    /** The note of a finding whose lock entry carries no notification-url, so no repository was asked about it. */
    public const NOTE_NOT_IN_REPOSITORY = 'not from a Composer repository, not checked';

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
        Verdict::STALE => [Signal::S2, Signal::S4],
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
     * How many years the installed version is behind the package's newest stable release, unrounded,
     * or why it was not measured ({@see Libyears::measure()}). Kept unrounded so the report's totals
     * sum what was measured, not what was printed.
     */
    private LibyearsMeasurement $libyears;
    /** Where the lock entry came from; `from_composer_repository` is read from its kind. */
    private PackageOrigin $origin;
    /** The registry that named the replacement, which decides where it is linked. */
    private ?string $replacementNamedBy;

    /**
     * @param list<Signal> $signals
     * @param list<string> $chain
     * @param list<string> $directDependents
     */
    public function __construct(string $package, string $version, string $verdict, array $signals, array $chain, ?string $allowlistReason, ?\DateTimeImmutable $dataDate, ?string $note = null, bool $dev = false, array $directDependents = [], ?LibyearsMeasurement $libyears = null, ?PackageOrigin $origin = null, ?string $replacementNamedBy = null)
    {
        $origin ??= PackageOrigin::unattributed();
        $fromComposerRepository = $origin->isComposerRepository();
        // The note says no repository was asked; a caller that forgot the flag would contradict it.
        if ($note === self::NOTE_NOT_IN_REPOSITORY && $fromComposerRepository) {
            throw new \InvalidArgumentException(\sprintf('%s is noted as not from a Composer repository, so it cannot be from one.', $package));
        }
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
        // Without a measurement no dates were compared, or no repository was asked; the analyzer always passes one.
        $libyears = $libyears ?? LibyearsMeasurement::unmeasured($fromComposerRepository ? Libyears::NO_STABLE_RELEASE_DATE : Libyears::NOT_FROM_COMPOSER_REPOSITORY);
        // Libyears::measure() reads the flag first, so its first reason and the flag always agree.
        $unmeasuredAsNotAsked = $libyears->unmeasuredReason() === Libyears::NOT_FROM_COMPOSER_REPOSITORY;
        if ($unmeasuredAsNotAsked === $fromComposerRepository) {
            throw new \InvalidArgumentException(\sprintf('%s: a package goes unmeasured as not from a Composer repository exactly when it is not from one.', $package));
        }
        $this->libyears = $libyears;
        $this->origin = $origin;
        $this->replacementNamedBy = $replacementNamedBy;
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

    public function isFromComposerRepository(): bool
    {
        return $this->origin->isComposerRepository();
    }

    public function origin(): PackageOrigin
    {
        return $this->origin;
    }

    /** @return list<string> */
    public function directDependents(): array
    {
        return $this->directDependents;
    }

    /** Years behind the newest stable release, unrounded; null when not measured ({@see Libyears}). */
    public function libyears(): ?float
    {
        return $this->libyears->years();
    }

    /** Why {@see libyears()} is null, one of {@see Libyears::REASONS}; null exactly when it is a number. */
    public function libyearsUnmeasured(): ?string
    {
        return $this->libyears->unmeasuredReason();
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
        return $this->priorityBasis()->priority();
    }

    /** The walk {@see priority()} is the end of: the verdict's base level and each step taken from it. */
    public function priorityBasis(): PriorityBasis
    {
        return Priority::basis($this->verdict, $this->isDirect(), $this->dev, $this->hasUnfixableAdvisory(), $this->chain !== []);
    }

    /**
     * A security advisory affects the installed version (S9), the verdict is one under which nobody
     * publishes fixes ({@see self::NO_FIX_VERDICTS}), and the fix is not already out: on a
     * left-behind branch, the branch's highest release does not carry it (a fix in a higher branch
     * is exactly what the branch will not get); on an abandoned or silent package, no release does.
     * What raises the priority one step and adds `no fix expected` to the evidence.
     */
    public function hasUnfixableAdvisory(): bool
    {
        return ($list = $this->noFixExpected()) !== null && $list !== [];
    }

    /**
     * The advisories on the finding no fix is expected for, in the sense {@see hasUnfixableAdvisory()}
     * gives it, in S9's order, each with why ({@see NoFix}). Null when the verdict makes no fix
     * prediction, which is every verdict but the no-fix ones; empty when it does and every advisory
     * is fixed by a release the verdict lets the project reach, or there is none.
     *
     * @return ?list<array{id: string, reason: string}>
     */
    public function noFixExpected(): ?array
    {
        if (!\in_array($this->verdict, self::NO_FIX_VERDICTS, true) || !Verdict::flagged($this->verdict)) {
            return null;
        }
        // Only S9 knows whether a null fixed_by was looked for; without the key nothing says it was.
        $releasesRead = ($this->advisoryData()['releases_read'] ?? false) === true;
        $list = [];
        foreach ($this->advisoryRows() as $row) {
            $fixed = $this->verdict === Verdict::LEFT_BEHIND ? ($row['fixed_on_branch'] ?? false) === true : ($row['fixed_by'] ?? null) !== null;
            if (!$fixed) {
                $list[] = ['id' => $row['id'], 'reason' => $this->noFixReason($row, $releasesRead)];
            }
        }

        return $list;
    }

    /**
     * The first {@see NoFix} reason that applies to an advisory no reachable release fixes.
     *
     * @param array<mixed, mixed> $row
     */
    private function noFixReason(array $row, bool $releasesRead): string
    {
        if ($this->verdict === Verdict::LEFT_BEHIND && ($row['fixed_by'] ?? null) !== null) {
            return NoFix::NOT_ON_INSTALLED_BRANCH;
        }
        if (!$releasesRead) {
            return NoFix::RELEASES_UNKNOWN;
        }
        if (($row['affected_versions'] ?? null) === null) {
            return NoFix::AFFECTED_RANGE_UNKNOWN;
        }

        return NoFix::NO_RELEASE_FIXES;
    }

    /**
     * `no fix expected`, qualified with where the fix is when that is known: `no fix expected on
     * 3.x` reads next to `3 fixed by v8.1.7` on a left-behind branch, and `no fix expected; migrate
     * to symfony/mailer` on an abandoned package whose repository names a replacement — the
     * advisory is not going to be fixed here, and the package that took over is where to go. Null
     * when every advisory is fixed by a release the finding's verdict lets the project reach.
     */
    private function noFixClause(): ?string
    {
        $list = $this->noFixExpected();
        if ($list === null || $list === []) {
            return null;
        }
        if (\in_array(NoFix::NOT_ON_INSTALLED_BRANCH, array_column($list, 'reason'), true)) {
            $branch = ReleaseBranch::of($this->version);

            return $branch === null ? 'no fix expected' : 'no fix expected on '.ReleaseBranch::label($branch);
        }
        // The successor, not the raw marker: only a package name is somewhere to migrate to, and
        // the repository's free text is already in the evidence above this clause.
        if (($replacement = $this->successor()) !== null) {
            return 'no fix expected; migrate to '.$replacement;
        }

        return 'no fix expected';
    }

    /**
     * The package the repository names as this one's replacement, when it names a package: the
     * `replacement` of an abandoned finding on Packagist is free text — `symfony/mailer` for
     * swiftmailer, but also `Symfony` for sensio/framework-extra-bundle and `EnglishInflector from
     * the String component` for doctrine/inflector — and only a Composer package name is something a
     * reader can migrate to, count, or link. Free text stays in the evidence, where it is read as
     * text. A name that is this package's own is not a successor either — a repository that names
     * itself says there is nowhere to go, and `migrate to` would point back at the abandoned
     * package. Null on every finding but an abandoned one with such a name. What the `migrate to`
     * clause ({@see noFixClause()}), the JSON `replacement` and the report's `with_replacement`
     * count all read, so the three cannot disagree.
     */
    public function successor(): ?string
    {
        if ($this->verdict !== Verdict::ABANDONED) {
            return null;
        }
        $replacement = $this->replacement();
        if ($replacement === null || strpos($replacement, '/') === false || ValidatingArrayLoader::hasPackageNamingError($replacement) !== null) {
            return null;
        }
        // Composer reads a package name without case, and so do the repositories that write them.
        if (strcasecmp($replacement, $this->package) === 0) {
            return null;
        }
        return $replacement;
    }

    /** The successor's page on the registry that named it ({@see PackageOrigin::replacementPage()}), or null. */
    private function replacementUrl(): ?string
    {
        $successor = $this->successor();

        return $successor === null ? null : PackageOrigin::replacementPage($this->replacementNamedBy, $successor);
    }

    /** The replacement S1 carries — the repository's, or the lock's — null when none is named. */
    private function replacement(): ?string
    {
        foreach ($this->signals as $signal) {
            if ($signal->id() === Signal::S1) {
                $replacement = $signal->data()['replacement'] ?? null;

                return \is_string($replacement) && $replacement !== '' ? $replacement : null;
            }
        }

        return null;
    }

    /**
     * `require ^8.2 to follow` after S8, for a `left-behind` package the project requires itself:
     * the constraint S8 suggests ({@see \Lockrot\Signal\Rule\LeftBehindRule}) is the one line in
     * composer.json that moves the project onto the branch fixes land on. A transitive package is
     * not the project's to require — its parent is — so the clause stays off; the constraint is
     * still on the signal's data for whoever does own the requirement.
     */
    private function followClause(Signal $s8): ?string
    {
        if ($this->verdict !== Verdict::LEFT_BEHIND || !$this->isDirect()) {
            return null;
        }
        $constraint = $s8->data()['suggested_constraint'] ?? null;

        return \is_string($constraint) && $constraint !== '' ? 'require '.$constraint.' to follow' : null;
    }

    /**
     * S9's advisory rows; one that is not an array, or has no string id, is nothing the no-fix list
     * could name and counts as no advisory.
     *
     * @return list<array{id: string}&array<mixed, mixed>>
     */
    private function advisoryRows(): array
    {
        $rows = [];
        $list = $this->advisoryData()['advisories'] ?? [];
        foreach (\is_array($list) ? $list : [] as $row) {
            if (\is_array($row) && \is_string($row['id'] ?? null)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @return array<string, mixed> S9's data, empty when the finding carries no S9 */
    private function advisoryData(): array
    {
        foreach ($this->signals as $signal) {
            if ($signal->id() === Signal::S9) {
                return $signal->data();
            }
        }

        return [];
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
            if ($signal->id() === Signal::S8 && ($clause = $this->followClause($signal)) !== null) {
                $parts[] = $clause;
            }
            if ($signal->id() === Signal::S9 && ($clause = $this->noFixClause()) !== null) {
                $parts[] = $clause;
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
        $libyears = $this->libyears->years();

        return [
            'package' => $this->package, 'version' => $this->version, 'verdict' => $this->verdict,
            'priority' => $this->priority(), 'direct' => $this->isDirect(), 'dev' => $this->dev,
            'from_composer_repository' => $this->isFromComposerRepository(),
            'origin' => $this->origin->toArray(),
            'replacement' => $this->successor(),
            'replacement_url' => $this->replacementUrl(),
            'signals' => $signals, 'chain' => $this->chain, 'direct_dependents' => $this->directDependents,
            'evidence' => $this->evidence(),
            'allowlist_reason' => $this->allowlistReason, 'note' => $this->note,
            'data_date' => $this->dataDate === null ? null : $this->dataDate->format(\DATE_ATOM),
            'libyears' => $libyears === null ? null : round($libyears, 2),
            'libyears_unmeasured' => $this->libyears->unmeasuredReason(),
            'priority_basis' => $this->priorityBasis()->toArray(),
            'no_fix_expected' => $this->noFixExpected(),
        ];
    }
}
