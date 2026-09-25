<?php

declare(strict_types=1);

namespace Lockrot\Lock;

use Composer\Repository\PlatformRepository;
use Lockrot\Config\ConfigSchema;
use Lockrot\Config\UnknownKeys;
use Lockrot\Exception\ConfigException;
use Lockrot\Json\JsonReader;

/** @internal */
final class ProjectConfig
{
    private ?string $name;
    /** @var list<string> */
    private array $requires;
    /** @var list<string> */
    private array $devRequires;
    /** @var array<string, mixed> */
    private array $lockrotExtra;
    private ?string $platformPhp;
    private ?string $requirePhp;

    /**
     * @param list<string> $requires
     * @param list<string> $devRequires
     * @param array<string, mixed> $lockrotExtra
     */
    private function __construct(array $requires, array $devRequires, array $lockrotExtra, ?string $platformPhp, ?string $name = null, ?string $requirePhp = null)
    {
        $this->name = $name;
        $this->requires = $requires;
        $this->devRequires = $devRequires;
        $this->lockrotExtra = $lockrotExtra;
        $this->platformPhp = $platformPhp;
        $this->requirePhp = $requirePhp;
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

        $name = $json['name'] ?? null;
        $require = $json['require'] ?? null;
        $requirePhp = \is_array($require) ? ($require['php'] ?? null) : null;

        return new self(
            self::packageNames($require),
            self::packageNames($json['require-dev'] ?? null),
            self::lockrotExtraFrom($extraRoot),
            \is_string($platform) ? $platform : null,
            \is_string($name) && $name !== '' ? $name : null,
            \is_string($requirePhp) && $requirePhp !== '' ? $requirePhp : null
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
        try {
            ConfigSchema::validate($lockrotExtra);
        } catch (ConfigException $e) {
            throw self::withUnknownKeys($e, $lockrotExtra);
        }

        return $lockrotExtra;
    }

    /**
     * The schema's error followed by the unknown-key lines a valid config would have printed as
     * warnings. A misspelt required key (`reasn`) fails the schema as a missing `reason`; the line
     * naming `reasn` and suggesting `reason` is what says why.
     *
     * @param array<string, mixed> $lockrotExtra
     */
    private static function withUnknownKeys(ConfigException $schemaError, array $lockrotExtra): ConfigException
    {
        $warnings = UnknownKeys::warnings($lockrotExtra);
        if ($warnings === []) {
            return $schemaError;
        }

        return new ConfigException($schemaError->getMessage()."\n  - ".implode("\n  - ", $warnings), $schemaError->getCode(), $schemaError);
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

    /**
     * The project's own `name` from composer.json, or null where it has none — an application is
     * not required to name itself. It is the one thing that says which project a report is about:
     * the lock is called composer.lock in every project there is.
     */
    public function name(): ?string
    {
        return $this->name;
    }

    public function platformPhp(): ?string
    {
        return $this->platformPhp;
    }

    /**
     * The project's own `require.php` as written — `>=7.2.5`, `^8.2` — or null where the manifest
     * makes no promise. It is the lowest PHP the project says it runs on, which is what a branch
     * S8 tells the project to follow has to admit ({@see \Lockrot\Signal\PhpFloor}). Composer
     * itself never resolves against it (only against the platform), so a project can lock what
     * its own requirement forbids; the report's job is to not suggest doing so.
     */
    public function requirePhp(): ?string
    {
        return $this->requirePhp;
    }
}
