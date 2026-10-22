<?php

declare(strict_types=1);

namespace Lockrot\Signal\Rule;

use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use Lockrot\Data\Repository\ReleaseBranch;
use Lockrot\Data\Repository\StableRelease;
use Lockrot\Lock\LockedPackage;
use Lockrot\Signal\Data\CappedData;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\PhpCap;
use Lockrot\Signal\PhpFloor;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalRule;

/**
 * S11: the installed release's `require.php` stops below R, the newest PHP minor that the root
 * php allows (docs/verdicts.md#capped). It reads `require.php` only: a cap from `php-64bit` or a
 * conflict shows in `run.php_window` alone.
 *
 * @internal
 */
final class CappedRule implements SignalRule
{
    private PhpCap $cap;
    private PhpFloor $floor;

    public function __construct(PhpCap $cap, PhpFloor $floor)
    {
        $this->cap = $cap;
        $this->floor = $floor;
    }

    public function evaluate(PackageFacts $facts): ?Signal
    {
        $package = $facts->package();
        $requires = $package->requirePhp();
        if ($requires === null) {
            return null;
        }
        $stopsAt = $this->cap->stopsAt($requires);
        $newest = $this->cap->newest();
        if ($stopsAt === null || $newest === null) {
            return null;
        }
        $data = new CappedData($newest, $this->cap->source(), $requires, $stopsAt, $this->floor->admitsTarget($requires) === false, $this->admitsNewest($facts));

        return new Signal(Signal::LEVEL_WARN, \sprintf('php "%s" stops at %s; %s, is excluded', $requires, $stopsAt, $data->newestWords()), $data);
    }

    /**
     * The newest release that admits R on the lowest branch that has one. A release that states no
     * php is no answer and does not count.
     *
     * @return ?array{version: string, branch: string, blocking: ?string}
     */
    private function admitsNewest(PackageFacts $facts): ?array
    {
        $metadata = $facts->metadata();
        $releases = $metadata === null ? null : $metadata->releasesAbove();
        $admitting = [];
        foreach (self::above($facts->package(), $releases ?? []) as $release) {
            $branch = ReleaseBranch::of($release->normalized());
            if ($branch !== null && $this->cap->admitsNewest($release->php()) === true) {
                $admitting[] = [$release, $branch];
            }
        }
        if ($admitting === []) {
            return null;
        }
        [$newest, $lowest] = $admitting[0];
        foreach ($admitting as [$release, $branch]) {
            $newest = $branch === $lowest ? $release : $newest;
        }

        return ['version' => $newest->pretty(), 'branch' => ReleaseBranch::label($lowest), 'blocking' => $this->floor->blocking($newest->php())];
    }

    /**
     * The releases above the installed one, ascending. Above a snapshot they count from `X.Y.0` of
     * an `X.Y.x-dev` or branch-aliased snapshot, else from the lock entry's `time`. Counting every
     * release could name a downgrade, as v0.6.0 below php-http/guzzle5-adapter 2.0.0.
     *
     * @param list<StableRelease> $releases {@see \Lockrot\Data\Repository\PackageMetadata::releasesAbove()}
     *
     * @return array<int, StableRelease>
     */
    private static function above(LockedPackage $package, array $releases): array
    {
        try {
            $installed = (new VersionParser())->normalize($package->version());
        } catch (\UnexpectedValueException $e) {
            return [];
        }
        if (VersionParser::parseStability($installed) !== 'dev') {
            return $releases;
        }
        $from = self::aliasFrom(array_merge([$installed], $package->aliasVersions()));
        $after = $package->time();

        return array_filter($releases, static function (StableRelease $release) use ($from, $after): bool {
            if ($from !== null) {
                return Comparator::greaterThanOrEqualTo($release->normalized(), $from);
            }

            return $after !== null && $release->at() !== null && $release->at() > $after;
        });
    }

    /**
     * `X.Y` of an `X.Y.x-dev` version or branch alias, null for a `dev-<name>` snapshot without one.
     *
     * @param list<string> $versions normalized: Composer writes `4.x-dev` as `4.9999999.9999999.9999999-dev`
     */
    private static function aliasFrom(array $versions): ?string
    {
        foreach ($versions as $version) {
            if (preg_match('/(\d+)\.(\d+)\.9999999\.9999999-dev/', $version, $m) === 1) {
                return $m[1].'.'.($m[2] === '9999999' ? '0' : $m[2]);
            }
        }

        return null;
    }
}
