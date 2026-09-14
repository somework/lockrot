<?php

declare(strict_types=1);

namespace Lockrot\Lock;

use Composer\Package\CompletePackage;
use Composer\Repository\PlatformRepository;
use Composer\Semver\VersionParser;

final class LockedPackage
{
    private const PACKAGIST_NOTIFICATION_URL = 'https://packagist.org/downloads/';

    private string $name;
    private string $version;
    private ?\DateTimeImmutable $time;
    private ?string $requirePhp;
    /** @var list<string> */
    private array $requires;
    private ?string $sourceUrl;
    private string $type;
    private bool $onPackagist;
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
        bool $onPackagist,
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
        $this->onPackagist = $onPackagist;
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

        return new self(
            $package->getName(),
            $package->getPrettyVersion(),
            $time,
            $phpLink !== null ? $phpLink->getPrettyConstraint() : null,
            $requires,
            $package->getSourceUrl(),
            $package->getType(),
            $package->getNotificationUrl() === self::PACKAGIST_NOTIFICATION_URL,
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
    public function isOnPackagist(): bool
    {
        return $this->onPackagist;
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
