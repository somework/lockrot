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
    public const REQUIRE = 'require';
    public const REQUIRE_DEV = 'require-dev';
    public const CONFLICT = 'conflict';

    private ?string $name;
    /** @var list<string> */
    private array $requires;
    /** @var list<string> */
    private array $devRequires;
    /** @var array<string, mixed> */
    private array $lockrotExtra;
    private ?string $platformPhp;
    private ?string $requirePhp;
    private ConfiguredRepositories $repositories;
    /** @var array<string, array<string, string>> section => lowercased package name => constraint */
    private array $constraints = [];

    /**
     * @param list<string> $requires
     * @param list<string> $devRequires
     * @param array<string, mixed> $lockrotExtra
     */
    private function __construct(array $requires, array $devRequires, array $lockrotExtra, ?string $platformPhp, ?string $name = null, ?string $requirePhp = null, ?ConfiguredRepositories $repositories = null)
    {
        $this->name = $name;
        $this->requires = $requires;
        $this->devRequires = $devRequires;
        $this->lockrotExtra = $lockrotExtra;
        $this->platformPhp = $platformPhp;
        $this->requirePhp = $requirePhp;
        $this->repositories = $repositories ?? ConfiguredRepositories::none();
    }

    public static function empty(): self
    {
        return new self([], [], [], null);
    }

    /**
     * A missing composer.json gives an empty config, because lockrot needs only the lock. An
     * unreadable or malformed one is a ConfigException, never an empty config.
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

        $config = new self(
            self::packageNames($require),
            self::packageNames($json['require-dev'] ?? null),
            self::lockrotExtraFrom($extraRoot),
            \is_string($platform) ? $platform : null,
            \is_string($name) && $name !== '' ? $name : null,
            \is_string($requirePhp) && $requirePhp !== '' ? $requirePhp : null,
            ConfiguredRepositories::fromManifest($json['repositories'] ?? null)
        );
        foreach ([self::REQUIRE, self::REQUIRE_DEV, self::CONFLICT] as $section) {
            $config->constraints[$section] = self::constraintsOf($json[$section] ?? null);
        }

        return $config;
    }

    /**
     * @param mixed $links
     * @return array<string, string>
     */
    private static function constraintsOf($links): array
    {
        $constraints = [];
        foreach (\is_array($links) ? $links : [] as $name => $constraint) {
            $name = strtolower((string) $name);
            if (\is_string($constraint) && !PlatformRepository::isPlatformPackage($name)) {
                $constraints[$name] = $constraint;
            }
        }

        return $constraints;
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
     * The schema's error, then the unknown-key lines that a valid config prints as warnings. A
     * misspelt required key (`reasn`) fails the schema as a missing `reason`, and its unknown-key
     * line says why.
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

    /**
     * The root's links of one section, as Composer reads them: names in lower case, platform
     * packages left out.
     *
     * @param self::REQUIRE|self::REQUIRE_DEV|self::CONFLICT $section
     *
     * @return array<string, string> package name => constraint as written
     */
    public function constraints(string $section): array
    {
        return $this->constraints[$section] ?? [];
    }

    /** @return array<string, mixed> */
    public function lockrotExtra(): array
    {
        return $this->lockrotExtra;
    }

    /**
     * The `name` from composer.json, or null when it has none. It is the report's `run.root_package`
     * and, unless `extra.lockrot.project` is set, its `run.project`
     * (docs/schema.md#what-the-run-was-told).
     */
    public function name(): ?string
    {
        return $this->name;
    }

    public function repositories(): ConfiguredRepositories
    {
        return $this->repositories;
    }

    public function platformPhp(): ?string
    {
        return $this->platformPhp;
    }

    /**
     * The project's own `require.php` as written (`>=7.2.5`, `^8.2`), or null when the manifest
     * makes no promise. A branch that S8 tells the project to follow must admit it
     * ({@see \Lockrot\Signal\PhpFloor}, docs/verdicts.md#within-reach). Composer resolves only
     * against the platform, so a lock can hold what the requirement forbids.
     */
    public function requirePhp(): ?string
    {
        return $this->requirePhp;
    }
}
