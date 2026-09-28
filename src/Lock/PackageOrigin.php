<?php

declare(strict_types=1);

namespace Lockrot\Lock;

use Lockrot\Data\Repository\RepositoryUrl;

/**
 * Where a lock entry came from, as a finding's `origin`: a kind, the registry where lockrot can name
 * it, and the package's page there. {@see of()} is the one place the kind is decided, and the kind is
 * what `from_composer_repository` is read from ({@see isComposerRepository()}).
 *
 * @internal
 */
final class PackageOrigin
{
    public const PACKAGIST = 'packagist';
    public const COMPOSER = 'composer';
    public const PATH = 'path';
    public const VCS = 'vcs';
    public const ARTIFACT = 'artifact';
    public const PACKAGE = 'package';
    public const UNKNOWN = 'unknown';
    /** What the schemas list in `x-known-values`; not the order {@see of()} decides in. */
    public const KINDS = [self::PACKAGIST, self::COMPOSER, self::PATH, self::VCS, self::ARTIFACT, self::PACKAGE, self::UNKNOWN];

    /** The registries lockrot names, by the host a notification-url reports to; any other host is not written. */
    public const REGISTRIES = ['packagist.org', 'repo.packagist.com', 'wp-packages.org', 'packages.drupal.org'];

    /** The registries that keep a public page per package name. */
    private const PACKAGE_PAGES = [
        'packagist.org' => 'https://packagist.org/packages/',
        'wp-packages.org' => 'https://wp-packages.org/packages/',
    ];

    /** Composer's own package-name rule (ValidatingArrayLoader, unchanged since 2.2): only such a name goes into a URL. */
    private const PACKAGE_NAME = '{^[a-z0-9](?:[_.-]?[a-z0-9]++)*+/[a-z0-9](?:(?:[_.]|-{1,2})?[a-z0-9]++)*+$}iD';

    private string $kind;
    private ?string $registry;
    private ?string $packageUrl;
    private bool $local;

    private function __construct(string $kind, ?string $registry, ?string $packageUrl, bool $local)
    {
        $this->kind = $kind;
        $this->registry = $registry;
        $this->packageUrl = $packageUrl;
        $this->local = $local;
    }

    public static function of(string $name, string $version, OriginFacts $facts, ConfiguredRepositories $repositories): self
    {
        $local = self::installsFromTheMachine($facts);
        $notificationUrl = $facts->notificationUrl();
        if ($notificationUrl !== null && $notificationUrl !== '') {
            $registry = self::registryOf($notificationUrl);

            return new self($registry === 'packagist.org' ? self::PACKAGIST : self::COMPOSER, $registry, self::packagePage($registry, $name), $local);
        }
        $kind = $repositories->kindServing($name, $version, $facts) ?? self::UNKNOWN;

        return new self($kind, null, null, $local || $kind === self::PATH || $kind === self::ARTIFACT);
    }

    /** The registry a notification-url reports to, when it is one lockrot names ({@see REGISTRIES}). */
    public static function registryOf(?string $notificationUrl): ?string
    {
        $host = parse_url((string) $notificationUrl, \PHP_URL_HOST);
        $host = \is_string($host) ? strtolower($host) : null;

        return \in_array($host, self::REGISTRIES, true) ? $host : null;
    }

    /**
     * The replacement's page on the registry that named it, or null. Only packagist.org: the
     * maintainer sets `abandoned` and its replacement there, while WP Packages never marks a
     * package abandoned and Private Packagist keeps no public page.
     */
    public static function replacementPage(?string $namedBy, string $replacement): ?string
    {
        return $namedBy === 'packagist.org' ? self::packagePage($namedBy, $replacement) : null;
    }

    /** A finding's origin when its caller gives none: a Composer repository lockrot does not name. */
    public static function unattributed(): self
    {
        return new self(self::COMPOSER, null, null, false);
    }

    /** Whether the kind is one whose entries lockrot asks a repository about: exactly the lock entries with a notification-url. */
    public static function isComposerRepositoryKind(string $kind): bool
    {
        return $kind === self::PACKAGIST || $kind === self::COMPOSER;
    }

    public function isComposerRepository(): bool
    {
        return self::isComposerRepositoryKind($this->kind);
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function registry(): ?string
    {
        return $this->registry;
    }

    public function packageUrl(): ?string
    {
        return $this->packageUrl;
    }

    /** Whether Composer installed the package from the machine it ran on rather than from a server. */
    public function isLocal(): bool
    {
        return $this->local;
    }

    /** @return array{kind: string, registry: ?string, package_url: ?string, local: bool} */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'registry' => $this->registry, 'package_url' => $this->packageUrl, 'local' => $this->local];
    }

    /** A `path` dist, or a dist or source that is a path or a `file://` URL. */
    private static function installsFromTheMachine(OriginFacts $facts): bool
    {
        return $facts->distType() === 'path' || RepositoryUrl::isLocalPath($facts->distUrl()) || RepositoryUrl::isLocalPath($facts->sourceUrl());
    }

    private static function packagePage(?string $registry, string $name): ?string
    {
        $page = self::PACKAGE_PAGES[$registry ?? ''] ?? null;
        if ($page === null || preg_match(self::PACKAGE_NAME, $name) !== 1) {
            return null;
        }
        [$vendor, $package] = explode('/', strtolower($name));

        return $page.rawurlencode($vendor).'/'.rawurlencode($package);
    }
}
