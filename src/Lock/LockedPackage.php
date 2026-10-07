<?php

declare(strict_types=1);

namespace Lockrot\Lock;

use Composer\Package\CompletePackage;
use Composer\Package\Link;
use Composer\Repository\PlatformRepository;
use Composer\Semver\VersionParser;
use Lockrot\Data\Forge\SupportSource;

/** @internal */
final class LockedPackage
{
    private string $name;
    private string $version;
    private ?\DateTimeImmutable $time;
    private ?string $requirePhp;
    /** @var list<string> */
    private array $requires;
    private ?string $repositoryUrl;
    private string $type;
    private OriginFacts $originFacts;
    private PackageOrigin $origin;
    private bool $dev;
    /** @var bool|string */
    private $abandonedInLock;
    /** @var list<string> */
    private array $aliasVersions = [];
    private ?string $normalizedVersion = null;
    /** @var array<string, string> */
    private array $requireConstraints = [];
    /** @var array<string, string> */
    private array $conflicts = [];
    /** @var array<string, string> */
    private array $replaces = [];
    /** @var array<string, string> */
    private array $provides = [];

    /**
     * @param list<string> $requires
     * @param bool|string $abandonedInLock
     */
    public function __construct(
        string $name,
        string $version,
        ?\DateTimeImmutable $time,
        ?string $requirePhp,
        array $requires,
        ?string $repositoryUrl,
        string $type,
        OriginFacts $originFacts,
        bool $dev,
        $abandonedInLock
    ) {
        $this->name = $name;
        $this->version = $version;
        $this->time = $time;
        $this->requirePhp = $requirePhp;
        $this->requires = $requires;
        $this->repositoryUrl = $repositoryUrl;
        $this->type = $type;
        $this->originFacts = $originFacts;
        $this->origin = PackageOrigin::of($name, $version, $originFacts, ConfiguredRepositories::none());
        $this->dev = $dev;
        $this->abandonedInLock = $abandonedInLock;
    }

    public static function fromPackage(CompletePackage $package, bool $dev): self
    {
        $releaseDate = $package->getReleaseDate();
        $time = null;
        if ($releaseDate instanceof \DateTimeImmutable) {
            $time = $releaseDate;
        } elseif ($releaseDate instanceof \DateTime) {
            $time = \DateTimeImmutable::createFromMutable($releaseDate);
        }

        $links = $package->getRequires();
        $phpLink = $links['php'] ?? null;

        $requires = [];
        foreach (array_keys($links) as $target) {
            if (!PlatformRepository::isPlatformPackage($target)) {
                $requires[] = $target;
            }
        }

        $repositoryUrl = $package->getSourceUrl();
        if ($repositoryUrl === null || $repositoryUrl === '') {
            $repositoryUrl = SupportSource::url($package->getSupport());
        }

        $locked = new self(
            $package->getName(),
            $package->getPrettyVersion(),
            $time,
            $phpLink !== null ? $phpLink->getPrettyConstraint() : null,
            $requires,
            $repositoryUrl,
            $package->getType(),
            OriginFacts::fromPackage($package),
            $dev,
            $package->isAbandoned() ? ($package->getReplacementPackage() ?? true) : false
        );
        $locked->normalizedVersion = $package->getVersion();
        $locked->requireConstraints = self::constraints($links);
        $locked->conflicts = self::constraints($package->getConflicts());
        $locked->replaces = self::constraints($package->getReplaces());
        $locked->provides = self::constraints($package->getProvides());

        return $locked;
    }

    /**
     * @param array<string, Link> $links
     *
     * @return array<string, string> lowercased target => the constraint as written, platform packages left out
     */
    private static function constraints(array $links): array
    {
        $constraints = [];
        foreach ($links as $link) {
            if (!PlatformRepository::isPlatformPackage($link->getTarget())) {
                $constraints[$link->getTarget()] = $link->getPrettyConstraint();
            }
        }

        return $constraints;
    }

    public function name(): string
    {
        return $this->name;
    }
    public function version(): string
    {
        return $this->version;
    }
    public function time(): ?\DateTimeImmutable
    {
        return $this->time;
    }
    public function requirePhp(): ?string
    {
        return $this->requirePhp;
    }
    /** @return list<string> */
    public function requires(): array
    {
        return $this->requires;
    }
    /** @return array<string, string> lowercased package name => constraint, platform packages left out */
    public function requireConstraints(): array
    {
        return $this->requireConstraints;
    }

    /** @return array<string, string> lowercased package name => constraint */
    public function conflicts(): array
    {
        return $this->conflicts;
    }

    /** @return array<string, string> lowercased package name => constraint */
    public function replaces(): array
    {
        return $this->replaces;
    }

    /** @return array<string, string> lowercased package name => constraint */
    public function provides(): array
    {
        return $this->provides;
    }

    /**
     * The lock entry's `source` URL, else its `support.source` reduced to the repository.
     */
    public function repositoryUrl(): ?string
    {
        return $this->repositoryUrl;
    }
    public function type(): string
    {
        return $this->type;
    }
    public function isFromComposerRepository(): bool
    {
        return $this->origin->isComposerRepository();
    }

    public function origin(): PackageOrigin
    {
        return $this->origin;
    }

    public function withRepositories(ConfiguredRepositories $repositories): self
    {
        $copy = clone $this;
        $copy->origin = PackageOrigin::of($this->name, $this->version, $this->originFacts, $repositories);

        return $copy;
    }

    /** The version Composer matches advisories on: the lock's `version_normalized`, null for a package built without a loader. */
    public function normalizedVersion(): ?string
    {
        return $this->normalizedVersion;
    }

    /**
     * The normalised versions of the aliases that Composer's loader builds for the entry
     * (`extra.branch-alias`, `default-branch`). `composer audit` matches an advisory against them too.
     *
     * @param list<string> $versions
     */
    public function withAliasVersions(array $versions): self
    {
        $copy = clone $this;
        $copy->aliasVersions = $versions;

        return $copy;
    }

    /** @return list<string> */
    public function aliasVersions(): array
    {
        return $this->aliasVersions;
    }

    public function withDev(bool $dev): self
    {
        $copy = clone $this;
        $copy->dev = $dev;

        return $copy;
    }

    public function isDev(): bool
    {
        return $this->dev;
    }
    /** @return bool|string */
    public function abandonedInLock()
    {
        return $this->abandonedInLock;
    }

    /** Whether the version names a branch (`dev-main`, `2.x-dev`) rather than a release. */
    public function isBranchSnapshot(): bool
    {
        return VersionParser::parseStability($this->version) === 'dev';
    }
}
