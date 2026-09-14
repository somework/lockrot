<?php

declare(strict_types=1);

namespace Lockrot\Lock;

use Composer\Repository\PlatformRepository;
use Lockrot\Config\ConfigSchema;
use Lockrot\Exception\ConfigException;
use Lockrot\Json\JsonReader;

final class ProjectConfig
{
    /** @var list<string> */
    private array $requires;
    /** @var list<string> */
    private array $devRequires;
    /** @var array<string, mixed> */
    private array $lockrotExtra;
    private ?string $platformPhp;

    /**
     * @param list<string> $requires
     * @param list<string> $devRequires
     * @param array<string, mixed> $lockrotExtra
     */
    private function __construct(array $requires, array $devRequires, array $lockrotExtra, ?string $platformPhp)
    {
        $this->requires = $requires;
        $this->devRequires = $devRequires;
        $this->lockrotExtra = $lockrotExtra;
        $this->platformPhp = $platformPhp;
    }

    public static function empty(): self
    {
        return new self([], [], [], null);
    }

    /**
     * A missing composer.json is legitimate (lockrot only needs composer.lock), but a composer.json
     * that is present and unreadable or malformed is an error worth reporting rather than silently
     * treating as "no direct requires, no extra.lockrot config" — same contract as LockFile.
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            return self::empty();
        }

        return self::fromArray(JsonReader::readObject($path));
    }

    /** @param array<string, mixed> $json */
    public static function fromArray(array $json): self
    {
        $extraRoot = $json['extra'] ?? null;
        $config = $json['config'] ?? null;
        $platformRoot = \is_array($config) ? ($config['platform'] ?? null) : null;
        $platform = \is_array($platformRoot) ? ($platformRoot['php'] ?? null) : null;

        return new self(
            self::packageNames($json['require'] ?? null),
            self::packageNames($json['require-dev'] ?? null),
            self::lockrotExtraFrom($extraRoot),
            \is_string($platform) ? $platform : null
        );
    }

    /**
     * @param mixed $extraRoot
     * @return array<string, mixed>
     */
    private static function lockrotExtraFrom($extraRoot): array
    {
        if (!\is_array($extraRoot) || !isset($extraRoot['lockrot'])) {
            return [];
        }

        $lockrotRaw = $extraRoot['lockrot'];
        if (!\is_array($lockrotRaw) || JsonReader::isList($lockrotRaw)) {
            throw new ConfigException('extra.lockrot must be an object');
        }

        $lockrotExtra = JsonReader::stringKeyed($lockrotRaw);
        ConfigSchema::validate($lockrotExtra);

        return $lockrotExtra;
    }

    /**
     * @param mixed $require
     * @return list<string>
     */
    private static function packageNames($require): array
    {
        $names = [];
        foreach (\is_array($require) ? array_keys($require) : [] as $name) {
            $name = (string) $name;
            if (!PlatformRepository::isPlatformPackage($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /** @return list<string> */
    public function directRequires(): array
    {
        return $this->requires;
    }

    /** @return list<string> */
    public function directDevRequires(): array
    {
        return $this->devRequires;
    }

    /** @return array<string, mixed> */
    public function lockrotExtra(): array
    {
        return $this->lockrotExtra;
    }

    public function platformPhp(): ?string
    {
        return $this->platformPhp;
    }
}
