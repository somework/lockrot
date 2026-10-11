<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

use Lockrot\Data\Repository\StableRelease;

/**
 * One direct dependent of a transitive package on its lead move (`next_step.through[]`, C08): does
 * the dependent's newest stable release still require the package? lockrot does not run the
 * solver, so a dependent that dropped it gets a `composer why-not` command, never a claim that it
 * installs.
 *
 * @internal
 */
final class ThroughEntry implements \JsonSerializable
{
    public const NOT_FROM_COMPOSER_REPOSITORY = 'not_from_composer_repository';
    public const RELEASES_UNKNOWN = 'releases_unknown';
    public const INSTALLED_UNLISTED = 'installed_unlisted';
    public const THROUGH_OTHER_PACKAGE = 'through_other_package';
    public const NO_STABLE_RELEASE = 'no_stable_release';

    private string $package;
    private ?string $installedVersion;
    private ?bool $installedRequires;
    private ?StableRelease $newestStable;
    private ?bool $newestRequires;
    private ?string $unmeasured;

    private function __construct(string $package, ?string $installedVersion, ?bool $installedRequires, ?StableRelease $newestStable, ?bool $newestRequires, ?string $unmeasured)
    {
        $this->package = $package;
        $this->installedVersion = $installedVersion;
        $this->installedRequires = $installedRequires;
        $this->newestStable = $newestStable;
        $this->newestRequires = $newestRequires;
        $this->unmeasured = $unmeasured;
    }

    /** @param self::NOT_FROM_COMPOSER_REPOSITORY|self::RELEASES_UNKNOWN $reason */
    public static function unread(string $package, ?string $installedVersion, string $reason): self
    {
        return new self($package, $installedVersion, null, null, null, $reason);
    }

    /**
     * @param ?list<string> $installedRequires the `require` names of the installed release, null when it is not among the releases read
     * @param ?list<string> $newestRequires    the `require` names of $newestStable
     */
    public static function measured(string $package, string $installedVersion, string $required, ?array $installedRequires, ?StableRelease $newestStable, ?array $newestRequires): self
    {
        // An undated newest release is not known to be out on the run date.
        $newestStable = $newestStable === null || $newestStable->at() === null ? null : $newestStable;
        if ($installedRequires === null) {
            return new self($package, $installedVersion, null, $newestStable, null, self::INSTALLED_UNLISTED);
        }
        if (!\in_array($required, $installedRequires, true)) {
            return new self($package, $installedVersion, false, $newestStable, null, self::THROUGH_OTHER_PACKAGE);
        }
        if ($newestStable === null || $newestRequires === null) {
            return new self($package, $installedVersion, true, null, null, self::NO_STABLE_RELEASE);
        }

        return new self($package, $installedVersion, true, $newestStable, \in_array($required, $newestRequires, true), null);
    }

    public function package(): string
    {
        return $this->package;
    }

    public function newestRequires(): ?bool
    {
        return $this->newestRequires;
    }

    /** The newest stable release's version, null when lockrot read none. */
    public function newestVersion(): ?string
    {
        return $this->newestStable === null ? null : $this->newestStable->pretty();
    }

    /** @return array{package: string, installed_version: ?string, installed_requires: ?bool, newest_stable: ?array{version: string, released: ?string}, newest_requires: ?bool, newest_requires_unmeasured: ?string} */
    public function jsonSerialize(): array
    {
        $newest = $this->newestStable;
        $at = $newest === null ? null : $newest->at();

        return [
            'package' => $this->package,
            'installed_version' => $this->installedVersion,
            'installed_requires' => $this->installedRequires,
            'newest_stable' => $newest === null ? null : ['version' => $newest->pretty(), 'released' => $at === null ? null : $at->format(\DATE_ATOM)],
            'newest_requires' => $this->newestRequires,
            'newest_requires_unmeasured' => $this->unmeasured,
        ];
    }
}
