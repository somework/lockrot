<?php

declare(strict_types=1);

namespace Lockrot\Lock;

use Composer\Package\PackageInterface;

/**
 * The lock entry's own record of where it was fetched from, as {@see PackageOrigin} reads it. Kept
 * in memory only: these URLs can carry credentials or machine paths, and none of them is written out.
 *
 * @internal
 */
final class OriginFacts
{
    private ?string $notificationUrl;
    private ?string $distType;
    private ?string $distUrl;
    private ?string $sourceType;
    private ?string $sourceUrl;

    public function __construct(?string $notificationUrl, ?string $distType, ?string $distUrl, ?string $sourceType, ?string $sourceUrl)
    {
        $this->notificationUrl = $notificationUrl;
        $this->distType = $distType;
        $this->distUrl = $distUrl;
        $this->sourceType = $sourceType;
        $this->sourceUrl = $sourceUrl;
    }

    public static function fromPackage(PackageInterface $package): self
    {
        return new self($package->getNotificationUrl(), $package->getDistType(), $package->getDistUrl(), $package->getSourceType(), $package->getSourceUrl());
    }

    public function notificationUrl(): ?string
    {
        return $this->notificationUrl;
    }

    public function distType(): ?string
    {
        return $this->distType;
    }

    public function distUrl(): ?string
    {
        return $this->distUrl;
    }

    public function sourceType(): ?string
    {
        return $this->sourceType;
    }

    public function sourceUrl(): ?string
    {
        return $this->sourceUrl;
    }
}
