<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Composer\Package\AliasPackage;
use Composer\Package\Link;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Intervals;
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

    /** @param Data $data */
    private function __construct(array $data, string $targetMinor)
    {
        $this->data = $data;
        $this->targetMinor = $targetMinor;
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
        $packages = [];
        foreach ($lock->lockedRepository()->getPackages() as $package) {
            // A root alias repeats its package's links under another version.
            if (!$package instanceof AliasPackage) {
                $packages[] = $package;
            }
        }
        $rootProvides = $project->platformConstraints(ProjectConfig::PROVIDE) + $project->platformConstraints(ProjectConfig::REPLACE);
        if (array_intersect(array_keys($rootProvides), self::VERSIONED) !== []) {
            return null;
        }
        foreach ($packages as $package) {
            if (array_intersect(array_keys($package->getProvides() + $package->getReplaces()), self::VERSIONED) !== []) {
                return null;
            }
        }

        $parser = new VersionParser();
        $hosts = [];
        $sets = ['with_dev' => null, 'without_dev' => []];
        foreach (array_keys($sets) as $set) {
            $dev = $set === 'with_dev';
            if ($dev && !$lock->hasDevKey()) {
                continue;
            }
            $raw = self::rootLinks($lock, $project, $dev);
            foreach ($packages as $package) {
                $isDev = $lock->isDevEntry($package->getName());
                if ($isDev && !$dev) {
                    continue;
                }
                foreach ([self::LINK_REQUIRE => $package->getRequires(), self::LINK_CONFLICT => $package->getConflicts()] as $kind => $links) {
                    foreach ($links as $target => $link) {
                        $raw[] = [self::req($package->getPrettyName(), $package->getPrettyVersion(), $kind, (string) $target, (string) $link->getPrettyConstraint(), $isDev), $link];
                    }
                }
            }
            $links = [];
            foreach ($raw as [$req, $link]) {
                if (\in_array($req['target'], self::HOST_FEATURES, true)) {
                    if ($req['link'] === self::LINK_REQUIRE) {
                        $hosts[$req['target']] = true;
                    }
                    continue;
                }
                if (!\in_array($req['target'], self::VERSIONED, true)) {
                    continue;
                }
                try {
                    $links[] = [$req, $link instanceof Link ? $link->getConstraint() : $parser->parseConstraints($req['constraint'])];
                } catch (\UnexpectedValueException $e) {
                    return null;
                }
            }
            $sets[$set] = $links;
        }

        $targetPoint = MoveCheckers::platformPhp($targetMinor, $project->platformPhp());
        $windows = [];
        foreach ($sets as $set => $links) {
            $windows[$set] = $links === null ? null : self::setWindow($links, $knownMinors, $targetMinor, $targetPoint);
        }
        ksort($hosts);

        return new self([
            'platform_override' => $lock->platformOverridePhp(),
            'host_requires' => array_map('strval', array_keys($hosts)),
            'with_dev' => $windows['with_dev'],
            'without_dev' => $windows['without_dev'],
            'target' => ['php' => $targetPoint, 'inside' => self::failing($sets['with_dev'] ?? $sets['without_dev'] ?? [], $targetPoint) === []],
        ], $targetMinor);
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
            $links[] = [self::req(null, null, self::LINK_REQUIRE, (string) $target, $constraint, $fromDev), null];
        }
        foreach ($project->platformConstraints(ProjectConfig::CONFLICT) as $target => $constraint) {
            $links[] = [self::req(null, null, self::LINK_CONFLICT, $target, $constraint, false), null];
        }

        return $links;
    }

    /**
     * @param array<string, string> $links
     *
     * @return array<string, array{0: string, 1: bool}> lowercased name => [constraint, from the dev side]
     */
    private static function byName(array $links, bool $dev): array
    {
        $out = [];
        foreach ($links as $name => $constraint) {
            $out[strtolower($name)] = [$constraint, $dev];
        }

        return $out;
    }

    /** @return Req */
    private static function req(?string $name, ?string $version, string $link, string $target, string $constraint, bool $dev): array
    {
        return ['name' => $name, 'version' => $version, 'link' => $link, 'target' => strtolower($target), 'constraint' => $constraint, 'dev' => $dev];
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
            $fewest = $fails === [] ? 0 : min(array_map('count', $fails));
            $closest = array_keys(array_filter($fails, static fn (array $failing): bool => \count($failing) === $fewest));
            $window['closest'] = [
                'minors' => array_map('strval', $closest),
                'set_by' => self::clean(array_merge([], ...array_map(static fn ($minor): array => $fails[$minor], $closest))),
            ];

            return $window;
        }
        $first = (int) array_search($in[0], $minors, true);
        $last = (int) array_search($in[\count($in) - 1], $minors, true);
        $window['gaps'] = $last - $first + 1 !== \count($in);
        $lowest = self::lowestPoint($links, $in[0]);
        [$major, $minor, $patch] = array_map('intval', explode('.', $lowest));
        $below = $patch > 0 ? $major.'.'.$minor.'.'.($patch - 1) : (isset($minors[$first - 1]) ? $point($minors[$first - 1]) : null);
        $window['lower_edge'] = ['php' => $lowest, 'set_by' => $below === null ? [] : self::clean(self::failing($links, $below))];
        if (isset($minors[$last + 1])) {
            $next = $minors[$last + 1];
            $window['upper_edge'] = ['excludes' => $next, 'set_by' => self::clean(self::failing($links, $point($next)))];
        }

        return $window;
    }

    /**
     * The lowest X.Y.Z of the minor that the links admit: X.Y.0, or the start of a requirement or
     * the end of a conflict inside the minor.
     *
     * @param list<PhpLink> $links
     */
    private static function lowestPoint(array $links, string $minor): string
    {
        $parser = new VersionParser();
        $candidates = [$minor.'.0'];
        $inMinor = $parser->parseConstraints('~'.$minor.'.0');
        foreach ($links as [$req, $constraint]) {
            foreach (Intervals::get($constraint)['numeric'] as $interval) {
                $bound = $req['link'] === self::LINK_REQUIRE ? $interval->getStart() : $interval->getEnd();
                $version = $bound->getVersion();
                // An open end (`>=8.5` ends at PHP_INT_MAX) names no point.
                if ($version === \PHP_INT_MAX.'.0.0.0' || preg_match('/^(\d+)\.(\d+)\.(\d+)\.(\d+)/', $version, $m) !== 1) {
                    continue;
                }
                // An exclusive bound or a fourth segment usually moves to the next patch, but
                // `>7.4.3-dev` admits 7.4.3: both are candidates, and failing() decides.
                $exclusive = \in_array($bound->getOperator(), ['>', '<='], true);
                foreach ($exclusive || (int) $m[4] !== 0 ? [0, 1] : [0] as $add) {
                    $candidate = $m[1].'.'.$m[2].'.'.((int) $m[3] + $add);
                    if ($inMinor->matches(new Constraint('==', $parser->normalize($candidate)))) {
                        $candidates[] = $candidate;
                    }
                }
            }
        }
        usort($candidates, 'version_compare');
        foreach ($candidates as $candidate) {
            if (self::failing($links, $candidate) === []) {
                return $candidate;
            }
        }

        return $minor.'.'.MoveCheckers::NEWEST_PATCH;
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
        usort($out, static fn (array $a, array $b): int => [$a['name'] !== null, (string) $a['name'], $a['link'], $a['target'], $a['dev']]
            <=> [$b['name'] !== null, (string) $b['name'], $b['link'], $b['target'], $b['dev']]);

        return $out;
    }

    public function targetMinor(): string
    {
        return $this->targetMinor;
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
