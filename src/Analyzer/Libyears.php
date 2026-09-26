<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use Lockrot\Clock;
use Lockrot\Data\Repository\InstalledRelease;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Data\Repository\ReleaseBranch;
use Lockrot\Lock\LockedPackage;
use Lockrot\Verdict\Finding;

/**
 * How far behind the lock is, in libyears: for each package, the years between the release of the
 * version installed and the package's newest stable release, summed over the analysed set.
 *
 * A verdict is a judgement about one package; this is one sentence about the whole lock —
 * "wallabag is 163.7 libyears behind". It is laid over the verdicts, not one of them: it does not enter a priority, a
 * `--fail-on` or the baseline, and it is not a measure of rot or of security — a package three
 * healthy patches behind adds as much as an abandoned one, and a `finished` psr/log 1.1.4 adds
 * three years for a 3.x it will never need. Nothing about "now" enters either: two dates the run
 * already holds, so the number is stable on recorded fixtures and needs no clock.
 *
 * The per-package rule is {@see behind()}; the block a report carries is {@see fromFindings()},
 * derived from the findings so that every number in it can be checked against them by arithmetic.
 * Not php-libyear's number: that tool reads composer.json and sums the direct requirements only;
 * lockrot sums the whole lock, and {@see direct()} is the bridge between the two.
 *
 * @internal
 */
final class Libyears
{
    /** A dev pin (`dev-main`, `2.x-dev`) has a commit date, not a release date; the `pinned` verdict covers it. */
    public const BRANCH_SNAPSHOT = 'branch_snapshot';
    /**
     * No date lockrot trusts for one of the two ends: no stable release at all, no dated release
     * above the installed version (the newest tags share a commit, as a subtree split's do, and
     * nothing dated sits between), the installed version dated only by such a shared commit (a
     * split whose newest release the monorepo parent dated and whose installed version it does
     * not), or the lock entry without a `time`.
     */
    public const NO_STABLE_RELEASE_DATE = 'no_stable_release_date';
    /** No metadata was asked for: a `path`, `vcs` or `package` repository entry. */
    public const NOT_FROM_COMPOSER_REPOSITORY = 'not_from_composer_repository';
    /** Metadata was asked for and did not come: not listed, offline, budget, transport. */
    public const METADATA_UNAVAILABLE = 'metadata_unavailable';

    /** The reasons a package goes unmeasured, in the order the block lists them. */
    public const REASONS = [self::BRANCH_SNAPSHOT, self::NO_STABLE_RELEASE_DATE, self::NOT_FROM_COMPOSER_REPOSITORY, self::METADATA_UNAVAILABLE];

    /** Decimals the block and each finding print; the sums are taken before rounding. */
    private const DECIMALS = 2;

    private float $total;
    private float $direct;
    private int $measured;
    /** @var array<string, int> every key of {@see REASONS}, zero included */
    private array $unmeasured;
    /**
     * The finding furthest behind and its value, kept as the pair they were set as; null when
     * nothing was measured, or nothing measured is behind.
     *
     * @var array{Finding, float}|null
     */
    private ?array $worst;

    /**
     * @param array<string, int>        $unmeasured
     * @param array{Finding, float}|null $worst
     */
    private function __construct(float $total, float $direct, int $measured, array $unmeasured, ?array $worst)
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
     * without metadata, a branch snapshot, or without a date to trust for one of the two ends. The
     * date is the repository's for the newest release ({@see PackageMetadata::lastStableReleaseAt()})
     * and the lock's for the installed one.
     */
    public static function behind(LockedPackage $package, ?PackageMetadata $metadata): ?float
    {
        if (!$package->isFromComposerRepository() || $metadata === null) {
            return null;
        }
        $installed = InstalledRelease::of($package, $metadata)->at();
        if ($installed === null) {
            return null;
        }
        $latest = $metadata->lastStableReleaseAt() ?? self::newestTrustedDateAbove($metadata, $package->version());
        if ($latest === null) {
            return null;
        }

        return max(0.0, ($latest->getTimestamp() - $installed->getTimestamp()) / Clock::SECONDS_PER_YEAR);
    }

    /**
     * When the repository dates no newest release lockrot trusts — the highest tag shares its
     * commit with other tags, as a subtree split's do (scheb/2fa-backup-code: v8.3.0 to v8.6.1 all
     * on one commit, all "2026-01-24") — the newest *trusted* date of a release above the installed
     * version still bounds the answer from below: a tag's commit is never younger than the release
     * it names, so whatever released above the installed version, it released no earlier than this.
     * Read off the branch view S8 already keeps: every branch above the installed one, and the
     * installed branch itself when its newest dated release is a higher version. A branch below
     * (a backport released later) is not "ahead" and does not count. Null when nothing above is dated.
     */
    private static function newestTrustedDateAbove(PackageMetadata $metadata, string $installedVersion): ?\DateTimeImmutable
    {
        $branch = ReleaseBranch::of($installedVersion);
        if ($branch === null) {
            return null;
        }
        $parser = new VersionParser();
        $newest = null;
        foreach ($metadata->latestStableByBranch() as $key => $release) {
            if ($release['at'] === null) {
                continue;
            }
            $key = (string) $key;
            if ($key === $branch) {
                try {
                    if (!Comparator::greaterThan($parser->normalize($release['version']), $parser->normalize($installedVersion))) {
                        continue;
                    }
                } catch (\UnexpectedValueException $e) {
                    continue;
                }
            } elseif (!ReleaseBranch::isAbove($key, $branch)) {
                continue;
            }
            if ($newest === null || $release['at'] > $newest) {
                $newest = $release['at'];
            }
        }

        return $newest;
    }

    /**
     * The block for a set of findings: the sums of the unrounded values, how many were measured and
     * how many were not and why, and the worst one — the greatest value above zero, ties going to
     * the package that sorts first by name, so two runs over the same lock name the same package.
     *
     * @param list<Finding> $findings
     */
    public static function fromFindings(array $findings): self
    {
        $total = 0.0;
        $direct = 0.0;
        $measured = 0;
        $unmeasured = array_fill_keys(self::REASONS, 0);
        /** @var array{Finding, float}|null $worst the finding and its value, set together */
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
            // Only a package that is behind can be the worst: a lock with every package on its
            // newest release names nobody. Two findings never share a package name — the lock is
            // keyed by it — so on a tie the comparison decides between two different names, never
            // between a name and itself.
            if ($behind > 0.0 && ($worst === null || $behind > $worst[1] || ($behind === $worst[1] && strcmp($finding->package(), $worst[0]->package()) < 0))) {
                $worst = [$finding, $behind];
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
            return self::BRANCH_SNAPSHOT;
        }

        return self::NO_STABLE_RELEASE_DATE;
    }

    /**
     * {@see reasonFor()} in the words a reader gets, rather than the key the report counts under.
     * Every key has one: the map is over {@see REASONS}.
     */
    public static function reasonWords(Finding $finding): string
    {
        $words = [
            self::BRANCH_SNAPSHOT => 'branch snapshot',
            self::NO_STABLE_RELEASE_DATE => 'no release date lockrot trusts',
            self::NOT_FROM_COMPOSER_REPOSITORY => 'not from a Composer repository',
            self::METADATA_UNAVAILABLE => 'metadata unavailable',
        ];

        return $words[self::reasonFor($finding)];
    }

    /**
     * The sum over every measured package, unrounded; null when no package could be measured.
     * Zero is an answer — every package measured and none behind — and null is the absence of one,
     * so a reader adding the field up over several projects does not count a lock nothing could be
     * read from as a lock with nothing to fix.
     */
    public function total(): ?float
    {
        return $this->measured === 0 ? null : $this->total;
    }

    /**
     * The sum over the measured direct requirements, unrounded — what php-libyear would count.
     * Null on the same terms as {@see total()}.
     */
    public function direct(): ?float
    {
        return $this->measured === 0 ? null : $this->direct;
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
        return $this->worst === null ? null : $this->worst[0];
    }

    private function unmeasuredCount(): int
    {
        return array_sum($this->unmeasured);
    }

    /**
     * The footer line: `libyears: 163.7 behind across 195 of 200 packages · 106.7 from direct
     * requirements · furthest behind smalot/pdfparser v1.1.0 at 4.7`. Every number carries its
     * noun: the scope says how many packages the sum covers and how many the run analysed, the
     * direct share says whose number it is (php-libyear's, near enough), and the package furthest
     * behind is named as that, not as "worst" — it may well be an `ok` package, and the table above
     * the line lists flagged rows. When every measured package is on its newest release the line
     * stops at the scope; when nothing could be measured it says so and how many packages there
     * were; on an empty run there is nothing to measure. Joined with ` · ` like the counts line, so
     * the table folds it between items rather than inside one. `%F`, not `%f`: the decimal point
     * does not follow the process locale, which another plugin in the same Composer process may
     * have set.
     */
    public function line(): string
    {
        $packages = $this->measured + $this->unmeasuredCount();
        if ($this->measured === 0) {
            if ($packages === 0) {
                return 'libyears: nothing to measure';
            }

            return $packages === 1 ? 'libyears: the one package could not be measured' : \sprintf('libyears: none of the %d packages could be measured', $packages);
        }
        $head = \sprintf('libyears: %.1F behind across %s', $this->total, $this->scope($packages));
        if ($this->worst === null) {
            return $head;
        }
        [$worst, $behind] = $this->worst;

        return implode(' · ', [
            $head,
            \sprintf('%.1F from direct requirements', $this->direct),
            \sprintf('furthest behind %s %s at %.1F', $worst->package(), $worst->version(), $behind),
        ]);
    }

    /** `191 of 200 packages`, or `all 200 packages` when every analysed package was measured. */
    private function scope(int $packages): string
    {
        if ($this->measured === $packages) {
            return $packages === 1 ? 'the one package' : \sprintf('all %d packages', $packages);
        }

        return \sprintf('%d of %d packages', $this->measured, $packages);
    }

    /** @return array<string, mixed> the block `--format=json` writes, each number rounded once */
    public function toArray(): array
    {
        return [
            'total' => $this->measured === 0 ? null : round($this->total, self::DECIMALS),
            'direct_requirements' => $this->measured === 0 ? null : round($this->direct, self::DECIMALS),
            'measured' => $this->measured,
            'unmeasured' => $this->unmeasured,
            'furthest_behind' => $this->worst === null ? null : [
                'package' => $this->worst[0]->package(),
                'version' => $this->worst[0]->version(),
                'libyears' => round($this->worst[1], self::DECIMALS),
            ],
        ];
    }
}
