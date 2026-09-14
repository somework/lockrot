<?php

declare(strict_types=1);

namespace Lockrot\Lock;

use Composer\Package\CompletePackage;
use Composer\Repository\PlatformRepository;
use Composer\Semver\VersionParser;

final class LockedPackage
{
    private string $name;
    private string $version;
    private ?\DateTimeImmutable $time;
    private ?string $requirePhp;
    /** @var list<string> */
    private array $requires;
    private ?string $sourceUrl;
    private string $type;
    private bool $fromComposerRepository;
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
        ?string $sourceUrl,
        string $type,
        bool $fromComposerRepository,
        bool $dev,
        $abandonedInLock
    ) {
        $this->name = $name;
        $this->version = $version;
        $this->time = $time;
        $this->requirePhp = $requirePhp;
        $this->requires = $requires;
        $this->sourceUrl = $sourceUrl;
        $this->type = $type;
        $this->fromComposerRepository = $fromComposerRepository;
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

        $notificationUrl = $package->getNotificationUrl();

        return new self(
            $package->getName(),
            $package->getPrettyVersion(),
            $time,
            $phpLink !== null ? $phpLink->getPrettyConstraint() : null,
            $requires,
            $package->getSourceUrl(),
            $package->getType(),
            $notificationUrl !== null && $notificationUrl !== '',
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
    public function sourceUrl(): ?string
    {
        return $this->sourceUrl;
    }
    public function type(): string
    {
        return $this->type;
    }
    public function isFromComposerRepository(): bool
    {
        return $this->fromComposerRepository;
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

    public function isBranchSnapshot(): bool
    {
        return VersionParser::parseStability($this->version) === 'dev';
    }
}
