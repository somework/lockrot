<?php

declare(strict_types=1);

namespace Lockrot\Config;

use Lockrot\Data\Advisory\AdvisoryCoverage;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Exception\ConfigException;
use Lockrot\Signal\Thresholds;
use Lockrot\Verdict\FailOn;

/** @internal */
final class LockrotConfig
{
    public const FAIL_ON_NONE = FailOn::NONE;
    public const FORMATS = ['table', 'json', 'github', 'sarif', 'gitlab', 'markdown', 'html'];
    public const DEFAULT_INSTALL_TIME_BUDGET = 5;
    private const INSTALL_TIME_BUDGET_MIN = 1;
    private const INSTALL_TIME_BUDGET_MAX = 120;

    public const INSTALL_TIME_VALUES = ['on', 'off'];
    public const INSTALL_TIME_ON = 'on';

    private ?string $project;
    private string $failOn;
    private string $targetPhp;
    private bool $includeDev;
    private bool $offline;
    private bool $strictNetwork;
    private string $format;
    private ?string $baseline;
    private bool $disabled;
    private bool $installTime;
    private bool $installTimeStrict;
    private int $installTimeBudgetSeconds;
    private Thresholds $thresholds;
    private string $advisoryLookup;
    private string $advisoryLookupSource;

    private function __construct(string $failOn, string $targetPhp, bool $includeDev, bool $offline, bool $strictNetwork, string $format, ?string $baseline, bool $disabled, bool $installTime, bool $installTimeStrict, int $installTimeBudgetSeconds, Thresholds $thresholds, ?string $project = null, string $advisoryLookup = AdvisoryCoverage::SCOPE_ALL, string $advisoryLookupSource = AdvisoryCoverage::SOURCE_DEFAULT)
    {
        $this->project = $project;
        $this->failOn = $failOn;
        $this->targetPhp = $targetPhp;
        $this->includeDev = $includeDev;
        $this->offline = $offline;
        $this->strictNetwork = $strictNetwork;
        $this->format = $format;
        $this->baseline = $baseline;
        $this->disabled = $disabled;
        $this->installTime = $installTime;
        $this->installTimeStrict = $installTimeStrict;
        $this->installTimeBudgetSeconds = $installTimeBudgetSeconds;
        $this->thresholds = $thresholds;
        $this->advisoryLookup = $advisoryLookup;
        $this->advisoryLookupSource = $advisoryLookupSource;
    }

    /**
     * @param array<string, mixed> $extra `extra.lockrot` of composer.json, not the whole `extra`
     * @param array<string, mixed> $env
     * @param array<string, mixed> $cli   the options fail-on, target-php, dev, offline, strict-network, format and baseline
     */
    public static function fromSources(array $extra, array $env, array $cli, string $runtimePhp, ?string $platformPhp): self
    {
        $failOn = self::resolveFailOn($extra, $env, $cli);
        $project = self::resolveProject($extra);
        $targetPhp = self::resolveTargetPhp($extra, $env, $cli, $runtimePhp, $platformPhp);
        $format = self::resolveFormat($extra, $cli);
        $includeDev = ($cli['dev'] ?? null) === true || ($extra['include-dev'] ?? false) === true;
        $advisoryLookup = self::resolveAdvisoryLookup($extra);

        return new self(
            $failOn,
            $targetPhp,
            $includeDev,
            ($cli['offline'] ?? null) === true,
            ($cli['strict-network'] ?? null) === true,
            $format,
            self::resolveBaseline($extra, $cli),
            self::isDisabledByEnvironment($env),
            self::resolveInstallTime($extra),
            ($extra['install-time-strict'] ?? false) === true,
            self::resolveInstallTimeBudget($extra),
            Thresholds::fromArray($extra),
            $project,
            $advisoryLookup ?? AdvisoryCoverage::SCOPE_ALL,
            $advisoryLookup === null ? AdvisoryCoverage::SOURCE_DEFAULT : AdvisoryCoverage::SOURCE_CONFIG
        );
    }

    /**
     * No option or environment variable: which repositories learn the project's package names is
     * the project's decision. See docs/verdicts.md#which-advisories-count.
     *
     * @param array<string, mixed> $extra
     */
    private static function resolveAdvisoryLookup(array $extra): ?string
    {
        if (!\array_key_exists('advisory-lookup', $extra)) {
            return null;
        }
        $value = $extra['advisory-lookup'];
        if (!\in_array($value, AdvisoryCoverage::SCOPES, true)) {
            throw new ConfigException(\sprintf('advisory-lookup must be one of %s; got %s', implode(', ', AdvisoryCoverage::SCOPES), \is_string($value) ? '"'.$value.'"' : var_export($value, true)));
        }

        return $value;
    }

    /**
     * Public, so that the install-time hook reads LOCKROT_DISABLE before it parses `extra.lockrot`:
     * else a malformed config prints a "check skipped" line on every install.
     * See docs/configuration.md#environment-overrides.
     *
     * @param array<string, mixed> $env
     */
    public static function isDisabledByEnvironment(array $env): bool
    {
        return ($env['LOCKROT_DISABLE'] ?? null) === '1' || ($env['LOCKROT_DISABLE'] ?? null) === 'true';
    }

    /**
     * No install-time key has a CLI option or an environment override, because the summary is a
     * per-project decision and LOCKROT_DISABLE covers a single command.
     * See docs/install-time.md#install-time-summary.
     *
     * @param array<string, mixed> $extra
     */
    private static function resolveInstallTime(array $extra): bool
    {
        $installTime = self::pick([$extra['install-time'] ?? null], self::INSTALL_TIME_ON);
        if (!\in_array($installTime, self::INSTALL_TIME_VALUES, true)) {
            throw new ConfigException(\sprintf(
                'install-time must be one of %s; got "%s"',
                implode(', ', self::INSTALL_TIME_VALUES),
                $installTime
            ));
        }

        return $installTime === self::INSTALL_TIME_ON;
    }

    /**
     * Takes integers only, like {@see Thresholds::fromArray()}: a digit string such as "5" is rejected.
     *
     * @param array<string, mixed> $extra
     */
    private static function resolveInstallTimeBudget(array $extra): int
    {
        $value = $extra['install-time-budget'] ?? self::DEFAULT_INSTALL_TIME_BUDGET;
        if (!\is_int($value) || $value < self::INSTALL_TIME_BUDGET_MIN || $value > self::INSTALL_TIME_BUDGET_MAX) {
            throw new ConfigException(\sprintf(
                'install-time-budget must be an integer between %d and %d; got %s',
                self::INSTALL_TIME_BUDGET_MIN,
                self::INSTALL_TIME_BUDGET_MAX,
                var_export($value, true)
            ));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $extra
     * @param array<string, mixed> $env
     * @param array<string, mixed> $cli
     *
     * @return string a verdict, a priority or `none`, as {@see FailOn::fromString()} accepts them
     */
    private static function resolveFailOn(array $extra, array $env, array $cli): string
    {
        // `--fail-on=` arrives as an empty string. If it falls through to the next source, a
        // typo turns a gated build into an ungated one.
        if (($cli['fail-on'] ?? null) === '') {
            throw new ConfigException('--fail-on must not be empty');
        }
        // Checked whenever it is set, not only when it wins, as extra.lockrot is validated in full
        // even where an option overrides it.
        $fromEnv = $env['LOCKROT_FAIL_ON'] ?? null;
        if (\is_string($fromEnv) && $fromEnv !== '' && !\in_array($fromEnv, FailOn::allowed(), true)) {
            throw new ConfigException(\sprintf('LOCKROT_FAIL_ON must be one of %s; got "%s"', implode(', ', FailOn::allowed()), $fromEnv));
        }

        return FailOn::fromString(self::pick([$cli['fail-on'] ?? null, $env['LOCKROT_FAIL_ON'] ?? null, $extra['fail-on'] ?? null], self::FAIL_ON_NONE))->value();
    }

    /**
     * @param array<string, mixed> $extra
     * @param array<string, mixed> $env
     * @param array<string, mixed> $cli
     */
    private static function resolveTargetPhp(array $extra, array $env, array $cli, string $runtimePhp, ?string $platformPhp): string
    {
        // Whenever it is set, like LOCKROT_FAIL_ON in resolveFailOn().
        $fromEnv = $env['LOCKROT_TARGET_PHP'] ?? null;
        if (\is_string($fromEnv) && $fromEnv !== '' && !self::looksLikePhpVersion($fromEnv)) {
            throw new ConfigException('LOCKROT_TARGET_PHP must look like "8.4"; got "'.$fromEnv.'"');
        }
        $target = self::pick([$cli['target-php'] ?? null, $env['LOCKROT_TARGET_PHP'] ?? null, $extra['target-php'] ?? null, $platformPhp], $runtimePhp);
        if (!self::looksLikePhpVersion($target)) {
            throw new ConfigException('target-php must look like "8.4"; got "'.$target.'"');
        }

        return PhpReleaseDates::minorOf($target);
    }

    private static function looksLikePhpVersion(string $value): bool
    {
        return preg_match('{^\d+(\.\d+)?}', $value) === 1;
    }

    /**
     * @param array<string, mixed> $extra
     * @param array<string, mixed> $cli
     */
    private static function resolveFormat(array $extra, array $cli): string
    {
        $format = self::pick([$cli['format'] ?? null, $extra['format'] ?? null], 'table');
        if (!\in_array($format, self::FORMATS, true)) {
            throw new ConfigException('format must be one of '.implode(', ', self::FORMATS).'; got "'.$format.'"');
        }

        return $format;
    }

    /**
     * The key renames the report's `run.project` and nothing else, as `run.root_package` always
     * holds the manifest's name. It is read from the manifest rather than from a flag, because it
     * describes the project, not the run. See docs/configuration.md#extralockrot-keys.
     *
     * @param array<string, mixed> $extra
     */
    private static function resolveProject(array $extra): ?string
    {
        $project = $extra['project'] ?? null;

        return \is_string($project) && $project !== '' ? $project : null;
    }

    /**
     * No environment override, because the findings that a project accepted belong to the project,
     * not to the machine that runs it. See docs/baseline.md.
     *
     * @param array<string, mixed> $extra
     * @param array<string, mixed> $cli
     */
    private static function resolveBaseline(array $extra, array $cli): ?string
    {
        // `--baseline=` arrives as an empty string. If it falls through to the default file, it gates
        // the build against a file that nobody named. The config schema's minLength rejects an empty
        // `extra.lockrot.baseline` earlier, so it falls through here.
        if (($cli['baseline'] ?? null) === '') {
            throw new ConfigException('--baseline must not be empty');
        }

        foreach ([$cli['baseline'] ?? null, $extra['baseline'] ?? null] as $candidate) {
            if (\is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /** @param list<mixed> $candidates */
    private static function pick(array $candidates, string $default): string
    {
        foreach ($candidates as $candidate) {
            if (\is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return $default;
    }

    /** A verdict, a priority or `none`, as {@see FailOn::fromString()} reads it. */
    public function failOn(): string
    {
        return $this->failOn;
    }
    public function targetPhp(): string
    {
        return $this->targetPhp;
    }
    public function includeDev(): bool
    {
        return $this->includeDev;
    }
    public function offline(): bool
    {
        return $this->offline;
    }
    public function strictNetwork(): bool
    {
        return $this->strictNetwork;
    }
    public function format(): string
    {
        return $this->format;
    }
    public function project(): ?string
    {
        return $this->project;
    }

    /** The configured baseline path, or null when the default file name applies. */
    public function baseline(): ?string
    {
        return $this->baseline;
    }
    public function isDisabled(): bool
    {
        return $this->disabled;
    }
    public function installTime(): bool
    {
        return $this->installTime;
    }
    public function installTimeStrict(): bool
    {
        return $this->installTimeStrict;
    }
    public function installTimeBudgetSeconds(): int
    {
        return $this->installTimeBudgetSeconds;
    }
    public function thresholds(): Thresholds
    {
        return $this->thresholds;
    }

    /** One of {@see AdvisoryCoverage::SCOPES}. */
    public function advisoryLookup(): string
    {
        return $this->advisoryLookup;
    }

    /** `config` when `extra.lockrot.advisory-lookup` sets the scope, else `default`. */
    public function advisoryLookupSource(): string
    {
        return $this->advisoryLookupSource;
    }
}
