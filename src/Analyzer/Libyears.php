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
 * The number and what it is not: docs/verdicts.md#libyears.
 *
 * @internal
 */
final class Libyears
{
    public const BRANCH_SNAPSHOT = 'branch_snapshot';
    public const NO_STABLE_RELEASE_DATE = 'no_stable_release_date';
    public const NOT_FROM_COMPOSER_REPOSITORY = 'not_from_composer_repository';
    public const METADATA_UNAVAILABLE = 'metadata_unavailable';

    /** The block lists the reasons in this order. {@see measure()} checks them in another. */
    public const REASONS = [self::BRANCH_SNAPSHOT, self::NO_STABLE_RELEASE_DATE, self::NOT_FROM_COMPOSER_REPOSITORY, self::METADATA_UNAVAILABLE];

    private const DECIMALS = 2;

    private float $total;
    private float $direct;
    private int $measured;
    /** @var array<string, int> every key of {@see REASONS}, zero included */
    private array $unmeasured;
    /**
     * Null when nothing measured is behind.
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

    public static function measure(LockedPackage $package, ?PackageMetadata $metadata): LibyearsMeasurement
    {
        if (!$package->isFromComposerRepository()) {
            return LibyearsMeasurement::unmeasured(self::NOT_FROM_COMPOSER_REPOSITORY);
        }
        if ($metadata === null) {
            return LibyearsMeasurement::unmeasured(self::METADATA_UNAVAILABLE);
        }
        // A branch is never dated as a release, with a lock `time` or without one.
        $installed = InstalledRelease::of($package, $metadata)->at();
        if ($installed === null) {
            return LibyearsMeasurement::unmeasured($package->isBranchSnapshot() ? self::BRANCH_SNAPSHOT : self::NO_STABLE_RELEASE_DATE);
        }
        $latest = $metadata->lastStableReleaseAt() ?? self::newestTrustedDateAbove($metadata, $package->version());
        if ($latest === null) {
            return LibyearsMeasurement::unmeasured(self::NO_STABLE_RELEASE_DATE);
        }

        return LibyearsMeasurement::of(max(0.0, ($latest->getTimestamp() - $installed->getTimestamp()) / Clock::SECONDS_PER_YEAR));
    }

    /**
     * The newest trusted date of a release above the installed version: a lower bound for the
     * newest release when its own tag has no trusted date, because a tag's commit is never younger
     * than the release it names. Reads the branch view of S8: each branch above the installed one,
     * and the installed branch when its newest dated release is higher. A backport on a lower
     * branch does not count. Null when nothing above is dated.
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
     * Ties for the furthest behind go to the package that sorts first by name, so two runs over
     * the same lock name the same package.
     *
     * @param list<Finding> $findings
     */
    public static function fromFindings(array $findings): self
    {
        $total = 0.0;
        $direct = 0.0;
        $measured = 0;
        $unmeasured = array_fill_keys(self::REASONS, 0);
        /** @var array{Finding, float}|null $worst */
        $worst = null;
        foreach ($findings as $finding) {
            $reason = $finding->libyearsUnmeasured();
            if ($reason !== null) {
                ++$unmeasured[$reason];
                continue;
            }
            $behind = $finding->libyears();
            if ($behind === null) {
                throw new \LogicException(\sprintf('%s has neither libyears nor a reason it has none.', $finding->package()));
            }
            ++$measured;
            $total += $behind;
            if ($finding->isDirect()) {
                $direct += $behind;
            }
            // Only a package that is behind can be the furthest behind. Package names are unique,
            // so strcmp on a tie never compares a name with itself.
            if ($behind > 0.0 && ($worst === null || $behind > $worst[1] || ($behind === $worst[1] && strcmp($finding->package(), $worst[0]->package()) < 0))) {
                $worst = [$finding, $behind];
            }
        }

        return new self($total, $direct, $measured, $unmeasured, $worst);
    }

    /**
     * The words for a finding's {@see Finding::libyearsUnmeasured()} key. The map covers every key
     * of {@see REASONS}.
     */
    public static function reasonWords(Finding $finding): string
    {
        $reason = $finding->libyearsUnmeasured();
        if ($reason === null) {
            throw new \LogicException(\sprintf('%s was measured, so there is no reason to put into words.', $finding->package()));
        }
        $words = [
            self::BRANCH_SNAPSHOT => 'branch snapshot',
            self::NO_STABLE_RELEASE_DATE => 'no release date lockrot trusts',
            self::NOT_FROM_COMPOSER_REPOSITORY => 'not from a Composer repository',
            self::METADATA_UNAVAILABLE => 'metadata unavailable',
        ];

        return $words[$reason];
    }

    /**
     * Unrounded sum over the measured packages. Null when none was measured, which is not zero:
     * nothing could be read.
     */
    public function total(): ?float
    {
        return $this->measured === 0 ? null : $this->total;
    }

    /**
     * Unrounded sum over the measured direct requirements. Null on the same terms as
     * {@see total()}.
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
     * The footer line: docs/verdicts.md#libyears shows it. Items join with ` · ` like the counts
     * line, so the table folds between items, not inside one. `%F`, not `%f`: the decimal point
     * must not follow the process locale, which another plugin in the Composer process can set.
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
