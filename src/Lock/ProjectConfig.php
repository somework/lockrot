<?php

declare(strict_types=1);

namespace Lockrot\Lock;

use Composer\Repository\PlatformRepository;
use Lockrot\Allowlist\ProjectIgnoreList;
use Lockrot\Config\ConfigSchema;
use Lockrot\Config\UnknownKeys;
use Lockrot\Exception\ConfigException;
use Lockrot\Json\JsonReader;
use Lockrot\Verdict\FailOn;

/** @internal */
final class ProjectConfig
{
    public const REQUIRE = 'require';
    public const REQUIRE_DEV = 'require-dev';
    public const CONFLICT = 'conflict';
    public const PROVIDE = 'provide';
    public const REPLACE = 'replace';

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
    /** @var array<string, array<string, string>> section => lowercased platform package name => constraint */
    private array $platformConstraints = [];
    /** @var array<string, array<string, string>> section => lowercased name => the key as written */
    private array $keys = [];
    private bool $read = true;

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

    /** No composer.json: a lock-only run. {@see isRead()} is false. */
    public static function empty(): self
    {
        $config = new self([], [], [], null);
        $config->read = false;

        return $config;
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
            $config->constraints[$section] = self::constraintsOf($json[$section] ?? null, false);
        }
        foreach ([self::REQUIRE, self::REQUIRE_DEV, self::CONFLICT, self::PROVIDE, self::REPLACE] as $section) {
            $config->platformConstraints[$section] = self::constraintsOf($json[$section] ?? null, true);
        }
        foreach ([self::REQUIRE, self::REQUIRE_DEV] as $section) {
            foreach (\is_array($json[$section] ?? null) ? $json[$section] : [] as $key => $constraint) {
                if (\is_string($constraint)) {
                    $config->keys[$section][strtolower((string) $key)] = (string) $key;
                }
            }
        }

        return $config;
    }

    /**
     * @param mixed $links
     * @return array<string, string>
     */
    private static function constraintsOf($links, bool $platform): array
    {
        $constraints = [];
        foreach (\is_array($links) ? $links : [] as $name => $constraint) {
            $name = strtolower((string) $name);
            if (\is_string($constraint) && PlatformRepository::isPlatformPackage($name) === $platform) {
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
        // lockrot's own checks speak before the schema's enum and pattern errors. The schema does
        // not read a value that they refused, and one run names every refusal.
        $refusals = [];
        $schemaInput = $lockrotExtra;
        $failOn = $lockrotExtra['fail-on'] ?? null;
        if (\is_string($failOn) || (\is_array($failOn) && JsonReader::isList($failOn) && array_filter($failOn, 'is_string') === $failOn)) {
            try {
                FailOn::parse($failOn, 'extra.lockrot.fail-on');
            } catch (ConfigException $e) {
                $refusals[] = $e;
                unset($schemaInput['fail-on']);
            }
        }
        $rows = \is_array($lockrotExtra['ignore'] ?? null) ? $lockrotExtra['ignore'] : [];
        foreach (ProjectIgnoreList::flagRefusals($rows) as $index => $e) {
            $refusals[] = $e;
            if (\is_array($rows[$index])) {
                unset($rows[$index]['flags']);
                $schemaInput['ignore'] = $rows;
            }
        }
        try {
            ConfigSchema::validate($schemaInput);
        } catch (ConfigException $e) {
            $refusals[] = $e;
        }
        if ($refusals !== []) {
            throw self::withUnknownKeys(self::joined($refusals), $lockrotExtra);
        }

        return $lockrotExtra;
    }

    /** @param non-empty-list<ConfigException> $refusals */
    private static function joined(array $refusals): ConfigException
    {
        return ConfigException::withDetails(
            implode("\n", array_map(static fn (ConfigException $e): string => $e->getMessage(), $refusals)),
            array_merge(...array_map(static fn (ConfigException $e): array => $e->details(), $refusals))
        );
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

        return ConfigException::withDetails($schemaError->getMessage()."\n  - ".implode("\n  - ", $warnings), $schemaError->details(), $schemaError);
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

    public function isRead(): bool
    {
        return $this->read;
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

    /**
     * The root's platform links of one section (`php`, `ext-json`), named as {@see constraints()} names them.
     *
     * @param self::REQUIRE|self::REQUIRE_DEV|self::CONFLICT|self::PROVIDE|self::REPLACE $section
     *
     * @return array<string, string> platform package name => constraint as written
     */
    public function platformConstraints(string $section): array
    {
        return $this->platformConstraints[$section] ?? [];
    }

    /**
     * The literal sections that list a package or platform name, as Composer reads them: the name
     * in any case, the later of two keys, a string constraint only. Composer's `require` command
     * picks its section and removes a key by this spelling.
     *
     * @return array<self::REQUIRE|self::REQUIRE_DEV, string> section => the key as written, `require` first
     */
    public function listedIn(string $name): array
    {
        $listed = [];
        foreach ([self::REQUIRE, self::REQUIRE_DEV] as $section) {
            $key = $this->keys[$section][strtolower($name)] ?? null;
            if ($key !== null) {
                $listed[$section] = $key;
            }
        }

        return $listed;
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
