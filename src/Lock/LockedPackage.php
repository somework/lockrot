<?php

declare(strict_types=1);

namespace Lockrot\Lock;

use Composer\Package\CompletePackage;
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

        return new self(
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
