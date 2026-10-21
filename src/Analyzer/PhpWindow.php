<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Composer\Package\AliasPackage;
use Composer\Package\Link;
use Composer\Package\PackageInterface;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\VersionParser;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
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
 * @phpstan-type Data array{platform_override: ?string, host_requires: list<string>, with_dev: ?Window, without_dev: Window, target: array{php: string, inside: bool}}
 */
final class PhpWindow implements \JsonSerializable
{
    public const LINK_REQUIRE = 'require';
    public const LINK_CONFLICT = 'conflict';
    /** Composer gives `php-64bit` the version of `php` on a 64-bit PHP. */
    public const VERSIONED = ['php', 'php-64bit'];
    /** Host features: Composer gives them no version that a range can intersect. */
    public const HOST_FEATURES = ['php-debug', 'php-ipv6', 'php-zts'];

    /** @var Data */
    private array $data;
    private string $targetMinor;
    /** @var list<string> */
    private array $knownMinors;

    /**
     * @param Data         $data
     * @param list<string> $knownMinors
     */
    private function __construct(array $data, string $targetMinor, array $knownMinors)
    {
        $this->data = $data;
        $this->targetMinor = $targetMinor;
        $this->knownMinors = $knownMinors;
    }

    /**
     * Null when the root or a package provides or replaces a versioned php package, or when a php
     * link does not parse: the window never stops a run.
     *
     * @param string       $targetMinor the run's target PHP minor
     * @param list<string> $knownMinors {@see \Lockrot\Data\Php\PhpReleaseDates::minors()}
     */
    public static function of(LockFile $lock, ProjectConfig $project, string $targetMinor, array $knownMinors): ?self
    {
        $rootProvides = $project->platformConstraints(ProjectConfig::PROVIDE) + $project->platformConstraints(ProjectConfig::REPLACE);
        if (array_intersect(array_keys($rootProvides), self::VERSIONED) !== []) {
            return null;
        }
        $all = [];
        $withoutDev = [];
        foreach ($lock->lockedRepository()->getPackages() as $package) {
            // A root alias repeats its package's links under another version.
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

        $targetPoint = MoveCheckers::platformPhp($targetMinor, $project->platformPhp());
        $windows = [];
        foreach ($sets as $set => $links) {
            $windows[$set] = $links === null ? null : self::setWindow($links, $knownMinors, $targetMinor, $targetPoint);
        }

        return new self([
            'platform_override' => $lock->platformOverridePhp(),
            'host_requires' => $hosts,
            'with_dev' => $windows['with_dev'],
            'without_dev' => $windows['without_dev'],
            'target' => ['php' => $targetPoint, 'inside' => self::failing($sets['with_dev'] ?? $sets['without_dev'], $targetPoint) === []],
        ], $targetMinor, $knownMinors);
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
        $in = array_values(array_filter($minors, static fn (string $minor): bool => self::failing($links, $point($minor)) === []));
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
        $below = isset($minors[$first - 1]) ? $point($minors[$first - 1]) : null;
        foreach (range(0, MoveCheckers::NEWEST_PATCH) as $patch) {
            $php = $minor.'.'.$patch;
            if (self::failing($links, $php) === []) {
                return ['php' => $php, 'set_by' => $below === null ? [] : self::clean(self::failing($links, $below))];
            }
            $below = $php;
        }

        // Only the target point admits the minor: config.platform.php names a patch past the range.
        return ['php' => $point($minor), 'set_by' => self::clean(self::failing($links, $minor.'.'.MoveCheckers::NEWEST_PATCH))];
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
