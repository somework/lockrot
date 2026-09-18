<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal;

use Composer\Semver\VersionParser;
use Lockrot\Data\Forge\RepoRef;
use Lockrot\Data\Forge\RepositoryActivity;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Data\Repository\ReleaseBranch;
use Lockrot\Lock\LockedPackage;
use Lockrot\Signal\PackageFacts;

final class FactsBuilder
{
    public const NOW = '2026-09-14T00:00:00+00:00';

    /** @param array<string, mixed> $o */
    public static function package(array $o = []): LockedPackage
    {
        $time = $o['time'] ?? null;
        $php = $o['php'] ?? null;
        $requiresRaw = $o['requires'] ?? [];
        $requires = [];
        if (\is_array($requiresRaw)) {
            foreach ($requiresRaw as $require) {
                if (\is_string($require)) {
                    $requires[] = $require;
                }
            }
        }
        $source = $o['source'] ?? 'https://github.com/vendor/pkg.git';
        $type = $o['type'] ?? 'library';
        $abandonedInLock = $o['abandonedInLock'] ?? false;

        return new LockedPackage(
            \is_string($o['name'] ?? null) ? $o['name'] : 'vendor/pkg',
            \is_string($o['version'] ?? null) ? $o['version'] : '1.0.0',
            \is_string($time) ? new \DateTimeImmutable($time) : null,
            \is_string($php) ? $php : null,
            $requires,
            \is_string($source) ? $source : null,
            \is_string($type) ? $type : 'library',
            \is_bool($o['fromComposerRepository'] ?? null) ? $o['fromComposerRepository'] : true,
            \is_bool($o['dev'] ?? null) ? $o['dev'] : false,
            \is_bool($abandonedInLock) || \is_string($abandonedInLock) ? $abandonedInLock : false
        );
    }

    /** @param list<array{string, ?string}> $releases [version, time] */
    public static function metadata(array $releases, bool $abandoned = false, ?string $replacement = null, string $type = 'library'): PackageMetadata
    {
        $hasStableRelease = false;
        $lastStableReleaseAt = null;
        $lastStableVersion = null;
        $byBranch = [];
        foreach ($releases as [$version, $time]) {
            if (strpos($version, 'dev-') === 0) {
                continue;
            }
            $hasStableRelease = true;
            $at = $time === null ? null : new \DateTimeImmutable($time);
            if ($at !== null && ($lastStableReleaseAt === null || $at > $lastStableReleaseAt)) {
                $lastStableReleaseAt = $at;
                $lastStableVersion = $version;
            }
            // As PackageMetadata::fromPackages() has it: the newest dated stable release per branch,
            // pre-releases left out, an undated branch keeping its first (highest) tag, and the
            // branch's highest tag (normalized) next to it. Callers list releases highest first.
            $branch = ReleaseBranch::of($version);
            if ($branch !== null && VersionParser::parseStability($version) === 'stable') {
                $seen = $byBranch[$branch] ?? null;
                $highest = $seen === null ? ['normalized' => (new VersionParser())->normalize($version), 'pretty' => $version, 'at' => $at] : $seen['highest'];
                if ($at !== null && ($seen === null || $seen['at'] === null || $at > $seen['at'])) {
                    $byBranch[$branch] = ['version' => $version, 'at' => $at, 'highest' => $highest];
                } elseif ($seen === null) {
                    $byBranch[$branch] = ['version' => $version, 'at' => null, 'highest' => $highest];
                }
            }
        }

        return new PackageMetadata(
            'vendor/pkg',
            $abandoned,
            $replacement,
            $hasStableRelease,
            $lastStableReleaseAt,
            $lastStableVersion,
            \count($releases),
            'https://github.com/vendor/pkg.git',
            $type,
            new \DateTimeImmutable(self::NOW),
            $byBranch
        );
    }

    public static function activity(bool $archived, ?string $pushedAt, string $forge = RepoRef::GITHUB): RepositoryActivity
    {
        $host = [RepoRef::GITHUB => 'github.com', RepoRef::GITLAB => 'gitlab.com', RepoRef::BITBUCKET => 'bitbucket.org'][$forge];

        return new RepositoryActivity(new RepoRef($forge, $host, 'vendor/pkg'), $archived, $pushedAt === null ? null : new \DateTimeImmutable($pushedAt), new \DateTimeImmutable(self::NOW));
    }

    /** @param list<\Lockrot\Data\Advisory\Advisory> $advisories */
    public static function facts(LockedPackage $p, ?PackageMetadata $m = null, ?RepositoryActivity $a = null, array $advisories = []): PackageFacts
    {
        return new PackageFacts($p, $m, $a, $advisories);
    }
}
