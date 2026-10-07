<?php

declare(strict_types=1);

namespace Lockrot\Lock;

use Composer\Package\BasePackage;
use Composer\Semver\VersionParser;
use Lockrot\Data\Forge\RepoLocator;

/**
 * @phpstan-type Definition array{name: string, version: string, dist: mixed, source: mixed}
 *
 * The manifest's `repositories`, in the order Composer consults them, read only to say which one
 * served a lock entry without a notification-url. Composer's global configuration is not read.
 * Reading never throws: a manifest that Composer refuses cannot have produced the lock, so
 * it serves nothing (docs/schema.md#where-a-package-came-from).
 *
 * @internal
 */
final class ConfiguredRepositories
{
    private const VCS_TYPES = ['vcs', 'git', 'github', 'gitlab', 'bitbucket', 'git-bitbucket', 'hg', 'svn', 'fossil', 'perforce'];

    /** Root repository types of Composer 2.2 to 2.10. A plugin's types exist only after the root repositories. */
    private const TYPES = ['vcs', 'git', 'github', 'gitlab', 'bitbucket', 'git-bitbucket', 'hg', 'svn', 'fossil', 'perforce', 'composer', 'package', 'artifact', 'path'];

    /** @var list<array{type: string, url: string, only: ?string, exclude: ?string, canonical: bool, packages: list<Definition>}> */
    private array $repositories;
    private bool $refused;

    /** @param list<array{type: string, url: string, only: ?string, exclude: ?string, canonical: bool, packages: list<Definition>}> $repositories */
    private function __construct(array $repositories, bool $refused)
    {
        $this->repositories = $repositories;
        $this->refused = $refused;
    }

    public static function none(): self
    {
        return new self([], false);
    }

    /** @param mixed $repositories the manifest's `repositories` as written: a list, or an object keyed by name */
    public static function fromManifest($repositories): self
    {
        if (!\is_array($repositories)) {
            return self::none();
        }
        $read = [];
        $last = [];
        foreach ($repositories as $name => $repository) {
            if ($repository === false || (\is_array($repository) && \count($repository) === 1 && current($repository) === false)) {
                continue;
            }
            $entry = self::read($repository);
            if ($entry === null) {
                return new self([], true);
            }
            // Composer keeps a repository named packagist in the default's place, after every other one.
            if ($name === 'packagist' || $name === 'packagist.org') {
                $last[] = $entry;
            } else {
                $read[] = $entry;
            }
        }

        return new self(array_merge($read, $last), false);
    }

    /**
     * The kind of the first listed repository that served the entry, in the order that Composer
     * walks them. The result is `unknown` when an earlier repository could have served the name and
     * left no trace. The result is null when none did and the entry has no path dist.
     */
    public function kindServing(string $name, string $version, OriginFacts $facts): ?string
    {
        $path = $facts->distType() === 'path';
        if ($this->refused) {
            return $path ? PackageOrigin::PATH : PackageOrigin::UNKNOWN;
        }
        foreach ($this->repositories as $repository) {
            if (!self::admits($repository, $name)) {
                continue;
            }
            $kind = $path ? self::servingPathDist($repository, $name, $version, $facts) : self::serving($repository, $name, $version, $facts);
            if ($kind !== null) {
                return $kind;
            }
        }

        return $path ? PackageOrigin::PATH : null;
    }

    /** @param array{type: string, url: string, canonical: bool, packages: list<Definition>} $repository */
    private static function serving(array $repository, string $name, string $version, OriginFacts $facts): ?string
    {
        $type = $repository['type'];
        if ($type === 'package') {
            if (self::inline($repository['packages'], $name, $version, $facts)) {
                return PackageOrigin::PACKAGE;
            }

            // A canonical inline repository that has the name is the only place Composer takes it from.
            return $repository['canonical'] && self::defines($repository['packages'], $name) ? PackageOrigin::UNKNOWN : null;
        }
        if (\in_array($type, self::VCS_TYPES, true)) {
            return self::sameRepository($repository['url'], $facts->sourceUrl()) ? PackageOrigin::VCS : null;
        }
        if ($type === 'artifact') {
            $inside = self::inside($repository['url'], $facts->distUrl());

            return $inside === null ? PackageOrigin::UNKNOWN : ($inside ? PackageOrigin::ARTIFACT : null);
        }
        // A path repository leaves a path dist, and packagist.org a notification-url.
        return $type === 'path' || self::isPackagist($repository['url']) ? null : PackageOrigin::UNKNOWN;
    }

    /** @param array{type: string, packages: list<Definition>} $repository */
    private static function servingPathDist(array $repository, string $name, string $version, OriginFacts $facts): ?string
    {
        if ($repository['type'] === 'path') {
            return PackageOrigin::PATH;
        }

        return $repository['type'] === 'package' && self::inline($repository['packages'], $name, $version, $facts) ? PackageOrigin::PACKAGE : null;
    }

    /**
     * One repository as Composer builds it, or null where Composer refuses it.
     *
     * @param mixed $repository
     *
     * @return array{type: string, url: string, only: ?string, exclude: ?string, canonical: bool, packages: list<Definition>}|null
     */
    private static function read($repository): ?array
    {
        if (!\is_array($repository) || !\in_array($repository['type'] ?? null, self::TYPES, true)) {
            return null;
        }
        $url = $repository['url'] ?? null;
        $canonical = $repository['canonical'] ?? true;
        $packages = self::definitions($repository['type'] === 'package' ? $repository['package'] ?? null : []);
        $only = self::filter($repository, 'only');
        $exclude = self::filter($repository, 'exclude');
        $url = \is_string($url) ? $url : ($repository['type'] === 'package' && $url === null ? '' : null);
        if ($url === null || !\is_bool($canonical) || $packages === null || $only === false || $exclude === false || ($only !== null && $exclude !== null)) {
            return null;
        }

        return ['type' => $repository['type'], 'url' => $url, 'only' => $only, 'exclude' => $exclude, 'canonical' => $canonical, 'packages' => $packages];
    }

    /**
     * @param mixed $packages an inline repository's `package`: one definition, or a list of them
     *
     * @return list<Definition>|null null where one is no definition that Composer loads
     */
    private static function definitions($packages): ?array
    {
        if (!\is_array($packages)) {
            return null;
        }
        $packages = \is_string($packages['name'] ?? null) ? [$packages] : $packages;
        $definitions = [];
        foreach ($packages as $package) {
            $name = \is_array($package) ? $package['name'] ?? null : null;
            $version = \is_array($package) ? $package['version'] ?? null : null;
            if (!\is_array($package) || !\is_string($name) || !\is_scalar($version)) {
                return null;
            }
            $definitions[] = ['name' => $name, 'version' => (string) $version, 'dist' => $package['dist'] ?? null, 'source' => $package['source'] ?? null];
        }

        return $definitions;
    }

    /**
     * @param array<mixed> $repository
     *
     * @return string|false|null the filter as a pattern, false where Composer refuses it
     */
    private static function filter(array $repository, string $key)
    {
        if (!isset($repository[$key])) {
            return null;
        }
        if (!\is_array($repository[$key])) {
            return false;
        }
        $names = [];
        foreach ($repository[$key] as $name) {
            if (!\is_string($name)) {
                return false;
            }
            $names[] = $name;
        }

        return BasePackage::packageNamesToRegexp($names);
    }

    /** @param array{only: ?string, exclude: ?string} $repository */
    private static function admits(array $repository, string $name): bool
    {
        return ($repository['only'] === null || preg_match($repository['only'], $name) === 1)
            && ($repository['exclude'] === null || preg_match($repository['exclude'], $name) !== 1);
    }

    /** @param list<Definition> $packages */
    private static function defines(array $packages, string $name): bool
    {
        foreach ($packages as $package) {
            if (strcasecmp($package['name'], $name) === 0) {
                return true;
            }
        }

        return false;
    }

    /** @param list<Definition> $packages */
    private static function inline(array $packages, string $name, string $version, OriginFacts $facts): bool
    {
        foreach ($packages as $package) {
            if (strcasecmp($package['name'], $name) === 0
                && self::sameVersion($package['version'], $version)
                && self::pair($package['dist']) === [$facts->distType(), $facts->distUrl()]
                && self::pair($package['source']) === [$facts->sourceType(), $facts->sourceUrl()]) {
                return true;
            }
        }

        return false;
    }

    private static function sameVersion(string $defined, string $locked): bool
    {
        $parser = new VersionParser();
        try {
            return $parser->normalize($defined) === $parser->normalize($locked);
        } catch (\UnexpectedValueException $e) {
            return $defined === $locked;
        }
    }

    /**
     * @param mixed $reference an inline definition's `dist` or `source`
     *
     * @return array{0: mixed, 1: mixed}
     */
    private static function pair($reference): array
    {
        return \is_array($reference) ? [$reference['type'] ?? null, $reference['url'] ?? null] : [null, null];
    }

    private static function isPackagist(string $url): bool
    {
        $host = parse_url($url, \PHP_URL_HOST);
        $host = \is_string($host) ? strtolower($host) : '';

        return $host === 'packagist.org' || substr($host, -\strlen('.packagist.org')) === '.packagist.org';
    }

    private static function sameRepository(string $configured, ?string $source): bool
    {
        if ($source === null) {
            return false;
        }
        $remote = self::remoteKey($configured);
        if ($remote !== null) {
            return $remote === self::remoteKey($source);
        }

        // GitDriver records a local repository as configured, less a trailing /.git. It leaves ~ and
        // variables unexpanded.
        return strpbrk($configured, '~$') === false && self::localKey($configured) === self::localKey($source);
    }

    /** The host without its port, and the path: one key for https and ssh alike. Null for a local path. */
    private static function remoteKey(string $url): ?string
    {
        $parts = preg_match('{^[A-Za-z]:[\\\\/]}', $url) === 1 ? null : RepoLocator::hostAndPath($url);
        if ($parts === null) {
            return null;
        }
        $host = (string) preg_replace('{:\d+$}', '', strtolower($parts[0]));
        $path = (string) preg_replace('{\.git$}', '', trim(strtolower($parts[1]), '/'));

        return ($host === 'www.github.com' ? 'github.com' : $host).'/'.$path;
    }

    private static function localKey(string $path): string
    {
        return (string) preg_replace('{/\.git$}', '', rtrim(str_replace('\\', '/', $path), '/'));
    }

    /** Whether a local dist file lies inside $directory. Null when $directory names `~` or a variable, which lockrot does not expand. */
    private static function inside(string $directory, ?string $file): ?bool
    {
        if (strpbrk($directory, '~$') !== false) {
            return null;
        }
        if ($file === null || strpos($file, '://') !== false) {
            return false;
        }
        $directory = rtrim(self::localPath($directory), '/');
        $file = self::localPath($file);
        // Composer lists an artifact directory's files as found under it, never through a `..`.
        if (strpos('/'.$file.'/', '/../') !== false) {
            return false;
        }
        if ($directory === '' || $directory === '.') {
            return preg_match('{^(/|[A-Za-z]:/)}', $file) !== 1;
        }

        return strpos($file, $directory.'/') === 0;
    }

    private static function localPath(string $path): string
    {
        return (string) preg_replace('{^(\./)+}', '', str_replace('\\', '/', $path));
    }
}
