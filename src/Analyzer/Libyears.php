<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Lockrot\Clock;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Lock\LockedPackage;
use Lockrot\Verdict\Finding;

/**
 * How far behind the lock is, in libyears: for each package, the years between the release of the
 * version installed and the package's newest stable release, summed over the analysed set.
 *
 * The verdicts are precise and are not quoted; "wallabag is 152 libyears behind" is. This is a
 * showcase number laid over the verdicts, not one of them: it does not enter a priority, a
 * `--fail-on` or the baseline, and it is not a measure of rot or of security — a package three
 * healthy patches behind adds as much as an abandoned one, and a `finished` psr/log 1.1.4 adds
 * three years for a 3.x it will never need. Nothing about "now" enters either: two dates the run
 * already holds, so the number is stable on recorded fixtures and needs no clock.
 *
 * The per-package rule is {@see behind()}; the block a report carries is {@see fromFindings()},
 * derived from the findings so that every number in it can be checked against them by arithmetic.
 * Not php-libyear's number: that tool reads composer.json and sums the direct requirements only;
 * lockrot sums the whole lock, and {@see direct()} is the bridge between the two.
 */
final class Libyears
{
    /** A dev pin (`dev-main`, `2.x-dev`) has a commit date, not a release date; the `pinned` verdict covers it. */
    public const BRANCH_SNAPSHOTS = 'branch_snapshots';
    /** No stable release at all, or the newest one undated (a subtree split's tags share a commit); the lock's own `time` missing too. */
    public const NO_STABLE_RELEASE_DATE = 'no_stable_release_date';
    /** No metadata was asked for: a `path`, `vcs` or `package` repository entry. */
    public const NOT_FROM_COMPOSER_REPOSITORY = 'not_from_composer_repository';
    /** Metadata was asked for and did not come: not listed, offline, budget, transport. */
    public const METADATA_UNAVAILABLE = 'metadata_unavailable';

    /** The reasons a package goes unmeasured, in the order the block lists them. */
    public const REASONS = [self::BRANCH_SNAPSHOTS, self::NO_STABLE_RELEASE_DATE, self::NOT_FROM_COMPOSER_REPOSITORY, self::METADATA_UNAVAILABLE];

    /** Decimals the block and each finding print; the sums are taken before rounding. */
    private const DECIMALS = 2;

    private float $total;
    private float $direct;
    private int $measured;
    /** @var array<string, int> every key of {@see REASONS}, zero included */
    private array $unmeasured;
    private ?Finding $worst;

    /** @param array<string, int> $unmeasured */
    private function __construct(float $total, float $direct, int $measured, array $unmeasured, ?Finding $worst)
    {
        $this->total = $total;
        $this->direct = $direct;
        $this->measured = $measured;
        $this->unmeasured = $unmeasured;
        $this->worst = $worst;
    }

    /**
     * The per-package rule: the newest stable release's date minus the installed version's, in
     * years of 365.25 days, never below zero — a lock on a pre-release above the last stable, or on
     * a tag the repository no longer lists, is measured and is not behind. Null when the package
     * is not measured, in the order {@see reasonFor()} files it: outside every Composer repository,
     * without metadata, a branch snapshot, or without one of the two dates. The date is the
     * repository's for the newest release ({@see PackageMetadata::lastStableReleaseAt()}, dated by
     * the monorepo parent where the package's own tags cannot be) and the lock's for the installed one.
     */
    public static function behind(LockedPackage $package, ?PackageMetadata $metadata): ?float
    {
        if (!$package->isFromComposerRepository() || $metadata === null || $package->isBranchSnapshot()) {
            return null;
        }
        $latest = $metadata->lastStableReleaseAt();
        $installed = $package->time();
        if ($latest === null || $installed === null) {
            return null;
        }

        return max(0.0, ($latest->getTimestamp() - $installed->getTimestamp()) / Clock::SECONDS_PER_YEAR);
    }

    /**
     * The block for a set of findings: the sums of the unrounded values, how many were measured and
     * how many were not and why, and the worst one — the greatest value, ties going to the package
     * that sorts first by name, so two runs over the same lock name the same package.
     *
     * @param list<Finding> $findings
     */
    public static function fromFindings(array $findings): self
    {
        $total = 0.0;
        $direct = 0.0;
        $measured = 0;
        $unmeasured = array_fill_keys(self::REASONS, 0);
        $worst = null;
        foreach ($findings as $finding) {
            $behind = $finding->libyears();
            if ($behind === null) {
                ++$unmeasured[self::reasonFor($finding)];
                continue;
            }
            ++$measured;
            $total += $behind;
            if ($finding->isDirect()) {
                $direct += $behind;
            }
            if ($worst === null || self::worseThan($finding, $behind, $worst)) {
                $worst = $finding;
            }
        }

        return new self($total, $direct, $measured, $unmeasured, $worst);
    }

    /**
     * Why a finding carries no value, read off what the finding already says: the note names a
     * package no repository was asked about or one whose metadata did not come
     * ({@see Analyzer::buildFinding()} sets it in exactly those cases), a dev version is a snapshot,
     * and what is left had metadata and no pair of dates to compare.
     */
    private static function reasonFor(Finding $finding): string
    {
        $note = $finding->note();
        if ($note === Analyzer::NOTE_NOT_IN_REPOSITORY) {
            return self::NOT_FROM_COMPOSER_REPOSITORY;
        }
        if ($note !== null) {
            return self::METADATA_UNAVAILABLE;
        }
        if (LockedPackage::isSnapshotVersion($finding->version())) {
            return self::BRANCH_SNAPSHOTS;
        }

        return self::NO_STABLE_RELEASE_DATE;
    }

    private static function worseThan(Finding $candidate, float $behind, Finding $worst): bool
    {
        $current = (float) $worst->libyears();

        return $behind > $current || ($behind === $current && strcmp($candidate->package(), $worst->package()) < 0);
    }

    /** The sum over every measured package, unrounded. */
    public function total(): float
    {
        return $this->total;
    }

    /** The sum over the measured direct requirements, unrounded — what php-libyear would count. */
    public function direct(): float
    {
        return $this->direct;
    }

    public function measured(): int
    {
        return $this->measured;
    }

    /** @return array<string, int> by reason, every key of {@see REASONS}, zero included */
    public function unmeasured(): array
    {
        return $this->unmeasured;
    }

    public function worst(): ?Finding
    {
        return $this->worst;
    }

    private function unmeasuredCount(): int
    {
        return array_sum($this->unmeasured);
    }

    /**
     * The footer line: `libyears: 151.5 across 191 measured packages · direct 94.5 · worst
     * smalot/pdfparser v1.1.0 (4.7) · 9 not measured`, the last item only when something was not
     * measured; `libyears: nothing measured (9 not measured)` when nothing was. Joined with ` · `
     * like the counts line, so the table folds it between items rather than inside one.
     */
    public function line(): string
    {
        $skipped = $this->unmeasuredCount();
        if ($this->worst === null) {
            return 'libyears: nothing measured'.($skipped === 0 ? '' : \sprintf(' (%d not measured)', $skipped));
        }
        $parts = [
            \sprintf('libyears: %.1f across %d measured %s', $this->total, $this->measured, $this->measured === 1 ? 'package' : 'packages'),
            \sprintf('direct %.1f', $this->direct),
            \sprintf('worst %s %s (%.1f)', $this->worst->package(), $this->worst->version(), (float) $this->worst->libyears()),
        ];
        if ($skipped > 0) {
            $parts[] = \sprintf('%d not measured', $skipped);
        }

        return implode(' · ', $parts);
    }

    /** @return array<string, mixed> the block `--format=json` writes, each number rounded once */
    public function toArray(): array
    {
        return [
            'total' => round($this->total, self::DECIMALS),
            'direct' => round($this->direct, self::DECIMALS),
            'measured' => $this->measured,
            'unmeasured' => $this->unmeasured,
            'worst' => $this->worst === null ? null : [
                'package' => $this->worst->package(),
                'version' => $this->worst->version(),
                'libyears' => round((float) $this->worst->libyears(), self::DECIMALS),
            ],
        ];
    }
}
