<?php

declare(strict_types=1);

namespace Lockrot\Data\Packagist;

use Composer\Semver\VersionParser;

final class Release
{
    private string $version;
    private ?\DateTimeImmutable $time;
    private bool $dev;
    private ?string $requirePhp;

    public function __construct(string $version, ?\DateTimeImmutable $time, bool $dev, ?string $requirePhp)
    {
        $this->version = $version;
        $this->time = $time;
        $this->dev = $dev;
        $this->requirePhp = $requirePhp;
    }

    /** @param array<string, mixed> $version */
    public static function fromP2Version(array $version): self
    {
        $versionRaw = $version['version'] ?? '';
        $pretty = \is_string($versionRaw) ? $versionRaw : '';
        $time = null;
        $timeRaw = $version['time'] ?? null;
        if (\is_string($timeRaw) && $timeRaw !== '') {
            try {
                $time = new \DateTimeImmutable($timeRaw);
            } catch (\Exception $e) {
                $time = null;
            }
        }
        $require = $version['require'] ?? null;
        $php = \is_array($require) ? ($require['php'] ?? null) : null;

        return new self($pretty, $time, VersionParser::parseStability($pretty) === 'dev', \is_string($php) ? $php : null);
    }

    public function version(): string
    {
        return $this->version;
    }
    public function time(): ?\DateTimeImmutable
    {
        return $this->time;
    }
    public function isDev(): bool
    {
        return $this->dev;
    }
    public function requirePhp(): ?string
    {
        return $this->requirePhp;
    }
}
