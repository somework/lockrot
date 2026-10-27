<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Composer\Package\AliasPackage;
use Composer\Package\Link;
use Composer\Package\PackageInterface;
use Composer\Semver\Comparator;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\VersionParser;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Security\Holder;
use Lockrot\Solver\MoveChecker;
use Lockrot\Solver\MoveCheckers;

/**
 * `run.php_window`: the PHP minors on which the lock installs, with require-dev and without, and
 * the links that set each edge (docs/verdicts.md#the-php-window).
 *
 * @internal
 *
 * @phpstan-type Req array{name: ?string, version: ?string, link: string, target: string, constraint: string, dev: bool}
 * @phpstan-type Window array{minors: list<string>, gaps: bool, lower_edge: ?array{php: string, set_by: list<Req>}, upper_edge: ?array{excludes: string, set_by: list<Req>}, closest: ?array{minors: list<string>, set_by: list<Req>}}
 * @phpstan-type PhpLink array{0: Req, 1: ConstraintInterface}
 * @phpstan-type Fix array{packages: list<Req>, lock_platform: list<Req>, command: ?list<string>, check: string, reason: ?string, held_by: list<Holder>, platform_step: bool}
 * @phpstan-type Data array{platform_override: ?string, host_requires: list<string>, with_dev: ?Window, without_dev: Window, target: array{php: string, inside: bool}, target_fix: ?Fix}
 */
final class PhpWindow implements \JsonSerializable
{
    public const LINK_REQUIRE = 'require';
    public const LINK_CONFLICT = 'conflict';
    /** Composer gives `php-64bit` the version of `php` on a 64-bit PHP. */
    public const VERSIONED = ['php', 'php-64bit'];
    /** Host features: whether Composer's platform holds them depends on the PHP build of the host. */
    public const HOST_FEATURES = ['php-debug', 'php-ipv6', 'php-zts'];

    /** @var Data */
    private array $data;
    private string $targetMinor;
    /** @var list<string> */
    private array $knownMinors;
    /** @var list<Req> */
    private array $targetFailing;
    /** @var list<Req> */
    private array $lockPlatform;
    /** @var list<string> */
    private array $skippedNames;
    private ?string $projectPlatformPhp;

    /**
     * @param Data         $data
     * @param list<string> $knownMinors
     * @param list<Req>    $targetFailing the links that fail at `target.php`, on the set that `target.inside` reads
     * @param list<Req>    $lockPlatform  those of them that only the lock's platform holds
     * @param list<string> $skippedNames  {@see LockFile::skippedNames()}
     */
    private function __construct(array $data, string $targetMinor, array $knownMinors, array $targetFailing, array $lockPlatform, array $skippedNames, ?string $projectPlatformPhp)
    {
        $this->lockPlatform = $lockPlatform;
        $this->data = $data;
        $this->targetMinor = $targetMinor;
        $this->knownMinors = $knownMinors;
        $this->targetFailing = $targetFailing;
        $this->skippedNames = $skippedNames;
        $this->projectPlatformPhp = $projectPlatformPhp;
    }

    /**
     * Null when the root or a package provides or replaces a versioned php package, or when a php
     * link does not parse: the window never stops a run.
     *
     * @param string       $targetMinor the run's target PHP minor
     * @param list<string> $knownMinors {@see \Lockrot\Data\Php\PhpReleaseDates::minors()}
     * @param ?string      $solvePhp    the php that the move check solves at, from Composer's merged
     *                                  config; null reads composer.json's `config.platform.php`
     */
    public static function of(LockFile $lock, ProjectConfig $project, string $targetMinor, array $knownMinors, ?string $solvePhp = null): ?self
    {
        $rootProvides = $project->platformConstraints(ProjectConfig::PROVIDE) + $project->platformConstraints(ProjectConfig::REPLACE);
        if (array_intersect(array_keys($rootProvides), self::VERSIONED) !== []) {
            return null;
        }
        $all = [];
        $withoutDev = [];
        foreach ($lock->lockedRepository()->getPackages() as $package) {
            // An alias package repeats the links of the package it aliases under another version: a
            // root alias, an `extra.branch-alias` or a `default-branch`.
            if (!$package instanceof AliasPackage) {
                if (array_intersect(array_keys($package->getProvides() + $package->getReplaces()), self::VERSIONED) !== []) {
                    return null;
                }
                $all[] = $package;
                if (!$lock->isDevEntry($package->getName())) {
                    $withoutDev[] = $package;
                }
            }
        }

        $hosts = [];
        $sets = ['with_dev' => null, 'without_dev' => []];
        foreach (['with_dev' => $all, 'without_dev' => $withoutDev] as $set => $packages) {
            if ($set === 'with_dev' && !$lock->hasDevKey()) {
                continue;
            }
            $read = self::phpLinks(array_merge(self::rootLinks($lock, $project, $set === 'with_dev'), self::packageLinks($lock, $packages)));
            if ($read === null) {
                return null;
            }
            [$sets[$set], $found] = $read;
            $hosts = array_merge($hosts, $found);
        }
        $hosts = array_unique($hosts);
        sort($hosts);

        $targetPoint = $solvePhp ?? MoveCheckers::platformPhp($targetMinor, $project->platformPhp());
        $windows = [];
        foreach ($sets as $set => $links) {
            $windows[$set] = $links === null ? null : self::setWindow($links, $knownMinors, $targetMinor, $targetPoint);
        }
        $targetFailing = self::failing($sets['with_dev'] ?? $sets['without_dev'], $targetPoint);
        $lockOnly = self::lockOnlyLinks($lock, $project, $sets['with_dev'] !== null);

        return new self([
            'platform_override' => $lock->platformOverridePhp(),
            'host_requires' => $hosts,
            'with_dev' => $windows['with_dev'],
            'without_dev' => $windows['without_dev'],
            'target' => ['php' => $targetPoint, 'inside' => $targetFailing === []],
            'target_fix' => null,
        ], $targetMinor, $knownMinors, $targetFailing, array_values(array_filter($targetFailing, static fn (array $req): bool => \in_array($req, $lockOnly, true))), $lock->skippedNames(), $project->platformPhp());
    }

    /**
     * `target_fix`: one update of the packages that exclude the target, and Composer's answer to
     * it. It stays null while the target is inside the window or below its lowest edge.
     *
     * @param ?MoveChecker $checker the run's move check, null when the run checks no move
     */
    public function withTargetFix(?MoveChecker $checker): self
    {
        if ($this->data['target']['inside'] || $this->targetBelowLowest()) {
            return $this;
        }
        $configured = ($checker === null ? null : $checker->configuredPhp()) ?? $this->projectPlatformPhp;
        $platformStep = $configured !== null && MoveCheckers::platformPhp($this->targetMinor, $configured) !== $configured;
        $copy = clone $this;
        $copy->data['target_fix'] = TargetFix::of($this->targetFailing, $this->lockPlatform, $this->skippedNames, $platformStep, $checker);

        return $copy;
    }

    /**
     * Whether the target lies below the lowest edge (SPEC §5.9): the set that `target.inside` reads
     * keeps no window minor of the target, and `target.php` lies below its `lower_edge.php`.
     */
    public function targetBelowLowest(): bool
    {
        $set = $this->data['with_dev'] ?? $this->data['without_dev'];
        $lowest = $set['lower_edge'];

        return $lowest !== null && !\in_array($this->targetMinor, $set['minors'], true)
            && Comparator::lessThan(self::normalized($this->data['target']['php']), self::normalized($lowest['php']));
    }

    private static function normalized(string $php): string
    {
        return (new VersionParser())->normalize($php);
    }

    /**
     * The root's php links per name, as `Installer::doInstall` reads them: composer.json first,
     * then the lock's `platform` for a name that composer.json lacks. With require-dev, a
     * `require-dev` link and `platform-dev` replace the `require` link and `platform` of the
     * same name, as Composer's `array_merge` does.
     *
     * @return list<array{0: Req, 1: null}>
     */
    private static function rootLinks(LockFile $lock, ProjectConfig $project, bool $dev): array
    {
        $named = self::byName($project->platformConstraints(ProjectConfig::REQUIRE), false);
        $lockNamed = self::byName($lock->platform(), false);
        if ($dev) {
            $named = array_merge($named, self::byName($project->platformConstraints(ProjectConfig::REQUIRE_DEV), true));
            $lockNamed = array_merge($lockNamed, self::byName($lock->platformDev(), true));
        }
        $links = [];
        foreach ($named + $lockNamed as $target => [$constraint, $fromDev]) {
            $links[] = [self::req(null, null, self::LINK_REQUIRE, $target, $constraint, $fromDev), null];
        }
        foreach ($project->platformConstraints(ProjectConfig::CONFLICT) as $target => $constraint) {
            $links[] = [self::req(null, null, self::LINK_CONFLICT, $target, $constraint, false), null];
        }

        return $links;
    }

    /**
     * The root links that only the lock's `platform` or `platform-dev` holds. Composer writes the
     * lock's platform from composer.json on each update, so composer.json dropped these links after
     * the last update. On a lock-only run
     * the lock's platform is the only record of the root, and none is listed.
     *
     * @return list<Req>
     */
    private static function lockOnlyLinks(LockFile $lock, ProjectConfig $project, bool $dev): array
    {
        if (!$project->isRead()) {
            return [];
        }
        $named = self::byName($project->platformConstraints(ProjectConfig::REQUIRE), false);
        $lockNamed = self::byName($lock->platform(), false);
        if ($dev) {
            $named = array_merge($named, self::byName($project->platformConstraints(ProjectConfig::REQUIRE_DEV), true));
            $lockNamed = array_merge($lockNamed, self::byName($lock->platformDev(), true));
        }
        $links = [];
        foreach (array_diff_key($lockNamed, $named) as $target => [$constraint, $fromDev]) {
            $links[] = self::req(null, null, self::LINK_REQUIRE, $target, $constraint, $fromDev);
        }

        return $links;
    }

    /**
     * @param list<PackageInterface> $packages
     *
     * @return list<array{0: Req, 1: Link}>
     */
    private static function packageLinks(LockFile $lock, array $packages): array
    {
        $links = [];
        foreach ($packages as $package) {
            $dev = $lock->isDevEntry($package->getName());
            foreach ([self::LINK_REQUIRE => $package->getRequires(), self::LINK_CONFLICT => $package->getConflicts()] as $kind => $packageLinks) {
                foreach ($packageLinks as $link) {
                    $links[] = [self::req($package->getPrettyName(), $package->getPrettyVersion(), $kind, $link->getTarget(), $link->getPrettyConstraint(), $dev), $link];
                }
            }
        }

        return $links;
    }

    /**
     * The links to `php` and `php-64bit` with their constraints, and the host features that a
     * requirement names. Null when a constraint does not parse.
     *
     * @param list<array{0: Req, 1: ?Link}> $links
     *
     * @return ?array{0: list<PhpLink>, 1: list<string>}
     */
    private static function phpLinks(array $links): ?array
    {
        $parser = new VersionParser();
        $php = [];
        $hosts = [];
        foreach ($links as [$req, $link]) {
            if (\in_array($req['target'], self::HOST_FEATURES, true)) {
                if ($req['link'] === self::LINK_REQUIRE) {
                    $hosts[] = $req['target'];
                }
            } elseif (\in_array($req['target'], self::VERSIONED, true)) {
                try {
                    $php[] = [$req, $link === null ? $parser->parseConstraints($req['constraint']) : $link->getConstraint()];
                } catch (\UnexpectedValueException $e) {
                    return null;
                }
            }
        }

        return [$php, $hosts];
    }

    /**
     * @param array<string, string> $links
     *
     * @return array<string, array{0: string, 1: bool}> name => [constraint, from the dev side]
     */
    private static function byName(array $links, bool $dev): array
    {
        return array_map(static fn (string $constraint): array => [$constraint, $dev], $links);
    }

    /** @return Req */
    private static function req(?string $name, ?string $version, string $link, string $target, string $constraint, bool $dev): array
    {
        return ['name' => $name, 'version' => $version, 'link' => $link, 'target' => $target, 'constraint' => $constraint, 'dev' => $dev];
    }

    /**
     * The requirements that fail at the point and the conflicts that match it: Composer's answer
     * at one PHP version is "installs" exactly when this list is empty.
     *
     * @param list<PhpLink> $links
     *
     * @return list<Req>
     */
    private static function failing(array $links, string $point): array
    {
        $at = new Constraint('==', (new VersionParser())->normalize($point));
        $out = [];
        foreach ($links as [$req, $constraint]) {
            if ($constraint->matches($at) === ($req['link'] === self::LINK_CONFLICT)) {
                $out[] = $req;
            }
        }

        return $out;
    }

    /**
     * The edges are read on the known minors, never on raw intervals: PHP 6 and PHP 7.5 to 7.9 do
     * not exist, so `~7.4.0 || ~8.0.0` has no gap.
     *
     * @param list<PhpLink> $links
     * @param list<string> $minors
     *
     * @return Window
     */
    private static function setWindow(array $links, array $minors, string $target, string $targetPoint): array
    {
        $point = static fn (string $minor): string => $minor === $target ? $targetPoint : $minor.'.'.MoveCheckers::NEWEST_PATCH;
        $in = array_values(array_filter($minors, static fn (string $minor): bool => self::failing($links, $point($minor)) === []
            || ($minor === $target && self::belowFloor($links, $minor, $targetPoint))));
        $window = ['minors' => $in, 'gaps' => false, 'lower_edge' => null, 'upper_edge' => null, 'closest' => null];
        if ($in === []) {
            $fails = [];
            foreach ($minors as $minor) {
                $fails[$minor] = self::failing($links, $point($minor));
            }
            $fewest = $fails === [] ? null : min(array_map('count', $fails));
            $closest = array_keys(array_filter($fails, static fn (array $failing): bool => \count($failing) === $fewest));
            $window['closest'] = [
                'minors' => $closest,
                'set_by' => self::clean(array_merge([], ...array_map(static fn ($minor): array => $fails[$minor], $closest))),
            ];

            return $window;
        }
        $position = array_flip($minors);
        $first = $position[$in[0]];
        $last = $position[$in[\count($in) - 1]];
        $window['gaps'] = $last - $first + 1 !== \count($in);
        $window['lower_edge'] = self::lowerEdge($links, $minors, $first, $point);
        if (isset($minors[$last + 1])) {
            $next = $minors[$last + 1];
            $window['upper_edge'] = ['excludes' => $next, 'set_by' => self::clean(self::failing($links, $point($next)))];
        }

        return $window;
    }

    /**
     * The lowest X.Y.Z of the first admitted minor, Z from 0 to {@see MoveCheckers::NEWEST_PATCH},
     * and the links that fail at the point just below it: the patch before, or the minor before.
     *
     * @param list<PhpLink>            $links
     * @param list<string>             $minors
     * @param callable(string): string $point  the point at which a minor is read
     *
     * @return array{php: string, set_by: list<Req>}
     */
    private static function lowerEdge(array $links, array $minors, int $first, callable $point): array
    {
        $minor = $minors[$first];
        $patch = self::lowestPatch($links, $minor);
        if ($patch === null) {
            // Only the target point admits the minor: config.platform.php names a patch past the range.
            return ['php' => $point($minor), 'set_by' => self::clean(self::failing($links, $minor.'.'.MoveCheckers::NEWEST_PATCH))];
        }
        $below = $patch > 0 ? $minor.'.'.($patch - 1) : (isset($minors[$first - 1]) ? $point($minors[$first - 1]) : null);

        return ['php' => $minor.'.'.$patch, 'set_by' => $below === null ? [] : self::clean(self::failing($links, $below))];
    }

    /**
     * Whether $point lies below a patch floor inside $minor: a higher patch of the minor installs the
     * lock, so the minor is in the window and its lowest patch is the lower edge (SPEC §5.9).
     *
     * @param list<PhpLink> $links
     */
    private static function belowFloor(array $links, string $minor, string $point): bool
    {
        $patch = self::lowestPatch($links, $minor);

        return $patch !== null && Comparator::lessThan(self::normalized($point), self::normalized($minor.'.'.$patch));
    }

    /**
     * The lowest Z from 0 to {@see MoveCheckers::NEWEST_PATCH} at which X.Y.Z installs the lock.
     *
     * @param list<PhpLink> $links
     */
    private static function lowestPatch(array $links, string $minor): ?int
    {
        for ($patch = 0; $patch <= MoveCheckers::NEWEST_PATCH; ++$patch) {
            if (self::failing($links, $minor.'.'.$patch) === []) {
                return $patch;
            }
        }

        return null;
    }

    /**
     * @param list<Req> $reqs
     *
     * @return list<Req> each once, the root first, then by name, link, target and dev
     */
    private static function clean(array $reqs): array
    {
        $out = [];
        foreach ($reqs as $req) {
            if (!\in_array($req, $out, true)) {
                $out[] = $req;
            }
        }
        usort($out, static fn (array $a, array $b): int => [$a['name'] !== null, $a['name'], $a['link'], $a['target'], $a['dev']]
            <=> [$b['name'] !== null, $b['name'], $b['link'], $b['target'], $b['dev']]);

        return $out;
    }

    public function targetMinor(): string
    {
        return $this->targetMinor;
    }

    public function platformOverride(): ?string
    {
        return $this->data['platform_override'];
    }

    /** @return array{php: string, inside: bool} */
    public function target(): array
    {
        return $this->data['target'];
    }

    public function isTargetInside(): bool
    {
        return $this->data['target']['inside'];
    }

    /** @return list<string> the known minors that the window was read on, oldest first */
    public function knownMinors(): array
    {
        return $this->knownMinors;
    }

    /** @return Data */
    public function toArray(): array
    {
        return $this->data;
    }

    /** @return Data */
    public function jsonSerialize(): array
    {
        return $this->data;
    }
}
