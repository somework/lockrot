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
use Lockrot\Signal\AgeMeasure;
use Lockrot\Verdict\Finding;

/**
 * The number and what it is not: docs/verdicts.md#libyears.
 *
 * @internal
 */
final class Libyears
{
    public const NOT_FROM_COMPOSER_REPOSITORY = AgeMeasure::NOT_FROM_COMPOSER_REPOSITORY;
    public const UNAVAILABLE = AgeMeasure::UNAVAILABLE;
    public const NOT_FOUND = AgeMeasure::NOT_FOUND;
    public const BRANCH_SNAPSHOT = AgeMeasure::BRANCH_SNAPSHOT;
    public const SHARED_COMMIT = AgeMeasure::SHARED_COMMIT;
    public const UNDATED = AgeMeasure::UNDATED;
    public const NO_STABLE_RELEASE = AgeMeasure::NO_STABLE_RELEASE;
    public const UNDATED_RELEASES = AgeMeasure::UNDATED_RELEASES;

    /**
     * The block lists the reasons in this order. An unmeasured end takes the id of its age reading
     * ({@see AgeMeasure}), so one cause has one id.
     */
    public const REASONS = [self::NOT_FROM_COMPOSER_REPOSITORY, self::UNAVAILABLE, self::NOT_FOUND, self::BRANCH_SNAPSHOT, self::SHARED_COMMIT, self::UNDATED, self::NO_STABLE_RELEASE, self::UNDATED_RELEASES];

    private float $total;
    private float $direct;
    private int $measured;
    private int $atLeast;
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
    private function __construct(float $total, float $direct, int $measured, int $atLeast, array $unmeasured, ?array $worst)
    {
        $this->total = $total;
        $this->direct = $direct;
        $this->measured = $measured;
        $this->atLeast = $atLeast;
        $this->unmeasured = $unmeasured;
        $this->worst = $worst;
    }

    /** @param ?string $whyNoMetadata `unavailable` or `not_found` when the caller knows why the metadata is missing, as for {@see AgeMeasure::release()} */
    public static function measure(LockedPackage $package, ?PackageMetadata $metadata, ?string $whyNoMetadata = null): LibyearsMeasurement
    {
        if (!$package->isFromComposerRepository()) {
            return LibyearsMeasurement::unmeasured(self::NOT_FROM_COMPOSER_REPOSITORY);
        }
        if ($metadata === null) {
            return LibyearsMeasurement::unmeasured($whyNoMetadata ?? self::UNAVAILABLE);
        }
        if ($package->isBranchSnapshot()) {
            return LibyearsMeasurement::unmeasured(self::BRANCH_SNAPSHOT);
        }
        $release = InstalledRelease::of($package, $metadata);
        $installed = $release->at();
        if ($installed === null) {
            return LibyearsMeasurement::unmeasured($release->kind() === InstalledRelease::SHARED_COMMIT ? self::SHARED_COMMIT : self::UNDATED);
        }
        if (!$metadata->hasStableRelease()) {
            return LibyearsMeasurement::unmeasured(self::NO_STABLE_RELEASE);
        }
        $newest = $metadata->lastStableReleaseAt();
        if ($newest !== null) {
            return LibyearsMeasurement::of(self::yearsBetween($installed, $newest));
        }
        $above = self::newestTrustedDateAbove($metadata, $package->version());

        return $above === null ? LibyearsMeasurement::unmeasured(self::UNDATED_RELEASES) : LibyearsMeasurement::atLeast(self::yearsBetween($installed, $above));
    }

    private static function yearsBetween(\DateTimeImmutable $installed, \DateTimeImmutable $newest): float
    {
        return max(0.0, ($newest->getTimestamp() - $installed->getTimestamp()) / Clock::SECONDS_PER_YEAR);
    }

    /**
     * The newest trusted date of a release above the installed version. It is a lower bound for
     * the newest release when that tag has no trusted date. A tag's commit is never younger than
     * the release it names. Reads the branch view of S8: each branch above the installed one, and
     * the installed branch when its newest dated release is higher. A backport on a lower branch
     * does not count. Null when nothing above is dated.
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
        $atLeast = 0;
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
            $atLeast += $finding->isLibyearsAtLeast() ? 1 : 0;
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

        return new self($total, $direct, $measured, $atLeast, $unmeasured, $worst);
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

    /** The measured findings whose value is a lower bound ({@see Finding::isLibyearsAtLeast()}). */
    public function atLeastCount(): int
    {
        return $this->atLeast;
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
}
