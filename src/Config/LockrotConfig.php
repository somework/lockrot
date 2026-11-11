<?php

declare(strict_types=1);

namespace Lockrot\Config;

use Composer\Semver\VersionParser;
use Lockrot\Analyzer\RunSettings;
use Lockrot\Clock;
use Lockrot\Data\Advisory\AdvisoryCoverage;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Exception\ConfigException;
use Lockrot\Signal\Thresholds;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\ListWords;

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

    /** The refusal of a value that {@see isTargetPhpValue()} does not take: the setting, then the value. */
    private const TARGET_PHP_VALUE = '%s takes a major, a minor or a patch version, such as "8.4" or "8.4.2", with nothing before or after it; got "%s"';
    /** A day after every GA date of the release data: its clock lists every minor that the data dates. */
    private const AFTER_EVERY_GA = '9999-12-31T00:00:00+00:00';
    /** The refusal of a target minor that is no PHP minor: the setting, the minor, the known minors, the newest one, then the value. */
    private const TARGET_PHP_MINOR = '%s names PHP %s, which is not a PHP minor that lockrot knows. The known minors are %s, and lockrot accepts a minor above %s. Got "%s"';

    private ?string $project;
    private FailOn $failOn;
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
    /** @var RunSettings::SOURCE_OPTION|RunSettings::SOURCE_ENV|RunSettings::SOURCE_CONFIG|RunSettings::SOURCE_DEFAULT */
    private string $failOnSource = RunSettings::SOURCE_DEFAULT;
    /** @var RunSettings::SOURCE_OPTION|RunSettings::SOURCE_ENV|RunSettings::SOURCE_CONFIG|RunSettings::SOURCE_PLATFORM|RunSettings::SOURCE_RUNTIME */
    private string $targetPhpSource = RunSettings::SOURCE_RUNTIME;

    private function __construct(FailOn $failOn, string $targetPhp, bool $includeDev, bool $offline, bool $strictNetwork, string $format, ?string $baseline, bool $disabled, bool $installTime, bool $installTimeStrict, int $installTimeBudgetSeconds, Thresholds $thresholds, ?string $project = null, string $advisoryLookup = AdvisoryCoverage::SCOPE_ALL, string $advisoryLookupSource = AdvisoryCoverage::SOURCE_DEFAULT)
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
        [$failOn, $failOnSource] = self::resolveFailOn($extra, $env, $cli);
        $project = self::resolveProject($extra);
        [$targetPhp, $targetPhpSource] = self::resolveTargetPhp($extra, $env, $cli, $runtimePhp, $platformPhp);
        $format = self::resolveFormat($extra, $cli);
        $includeDev = ($cli['dev'] ?? null) === true || ($extra['include-dev'] ?? false) === true;
        $advisoryLookup = self::resolveAdvisoryLookup($extra);

        $config = new self(
            $failOn,
            $targetPhp,
            $includeDev,
            ($cli['offline'] ?? null) === true || self::isNetworkDisabledBy($env['COMPOSER_DISABLE_NETWORK'] ?? null),
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
        $config->failOnSource = $failOnSource;
        $config->targetPhpSource = $targetPhpSource;

        return $config;
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
     * Composer's own reading of COMPOSER_DISABLE_NETWORK, `(bool)` of the value as HttpDownloader
     * takes it: any value but empty or `0`, `prime` included, turns the network off, so the run
     * reads as `--offline`.
     *
     * @param mixed $value the value of COMPOSER_DISABLE_NETWORK, false or null when it is not set
     */
    public static function isNetworkDisabledBy($value): bool
    {
        return \is_string($value) && $value !== '' && $value !== '0';
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
     * The first source that sets fail-on replaces the others whole: sources never merge.
     *
     * @param array<string, mixed> $extra
     * @param array<string, mixed> $env
     * @param array<string, mixed> $cli
     *
     * @return array{FailOn, RunSettings::SOURCE_OPTION|RunSettings::SOURCE_ENV|RunSettings::SOURCE_CONFIG|RunSettings::SOURCE_DEFAULT}
     */
    private static function resolveFailOn(array $extra, array $env, array $cli): array
    {
        // `--fail-on=` arrives as an empty string. If it falls through to the next source, a
        // typo turns a gated build into an ungated one.
        if (($cli['fail-on'] ?? null) === '') {
            throw new ConfigException('--fail-on must not be empty');
        }
        // Each source is checked whenever it is set, not only when it wins, as extra.lockrot is
        // validated in full even where an option overrides it.
        $fromEnv = $env['LOCKROT_FAIL_ON'] ?? null;
        $env = \is_string($fromEnv) && $fromEnv !== '' ? FailOn::parse($fromEnv, 'LOCKROT_FAIL_ON') : null;
        $config = self::configFailOn($extra['fail-on'] ?? null);
        $option = $cli['fail-on'] ?? null;
        if (\is_string($option)) {
            return [FailOn::parse($option, '--fail-on'), RunSettings::SOURCE_OPTION];
        }
        if ($env !== null) {
            return [$env, RunSettings::SOURCE_ENV];
        }

        return $config === null ? [FailOn::none(), RunSettings::SOURCE_DEFAULT] : [$config, RunSettings::SOURCE_CONFIG];
    }

    /**
     * config-2 takes the list as a string or as a JSON array.
     *
     * @param mixed $value
     */
    private static function configFailOn($value): ?FailOn
    {
        if (\is_string($value) && $value !== '') {
            return FailOn::parse($value, 'extra.lockrot.fail-on');
        }
        if (\is_array($value) && $value !== []) {
            $words = [];
            foreach ($value as $word) {
                if (!\is_string($word)) {
                    throw new ConfigException('extra.lockrot.fail-on: every item of the list must be a string');
                }
                $words[] = $word;
            }

            return FailOn::parse($words, 'extra.lockrot.fail-on');
        }

        return null;
    }

    /**
     * @param array<string, mixed> $extra
     * @param array<string, mixed> $env
     * @param array<string, mixed> $cli
     *
     * @return array{string, RunSettings::SOURCE_OPTION|RunSettings::SOURCE_ENV|RunSettings::SOURCE_CONFIG|RunSettings::SOURCE_PLATFORM|RunSettings::SOURCE_RUNTIME} the target minor and its source
     */
    private static function resolveTargetPhp(array $extra, array $env, array $cli, string $runtimePhp, ?string $platformPhp): array
    {
        // As `--fail-on=` in resolveFailOn(): an unset CI variable must not judge another PHP.
        if (($cli['target-php'] ?? null) === '') {
            throw new ConfigException('--target-php must not be empty');
        }
        // Whenever it is set, like LOCKROT_FAIL_ON in resolveFailOn().
        $fromEnv = $env['LOCKROT_TARGET_PHP'] ?? null;
        if (\is_string($fromEnv) && $fromEnv !== '' && !self::isTargetPhpValue($fromEnv)) {
            throw new ConfigException(\sprintf(self::TARGET_PHP_VALUE, 'LOCKROT_TARGET_PHP', $fromEnv));
        }
        if (\is_string($fromEnv) && $fromEnv !== '') {
            self::refuseUnknownMinor('LOCKROT_TARGET_PHP', $fromEnv);
        }
        $fromConfig = $extra['target-php'] ?? null;
        if (\is_string($fromConfig) && $fromConfig !== '') {
            if (!self::isTargetPhpValue($fromConfig)) {
                throw new ConfigException(\sprintf(self::TARGET_PHP_VALUE, 'extra.lockrot.target-php', $fromConfig));
            }
            self::refuseUnknownMinor('extra.lockrot.target-php', $fromConfig);
        }
        [$target, $source] = self::pickWithSource([
            RunSettings::SOURCE_OPTION => $cli['target-php'] ?? null,
            RunSettings::SOURCE_ENV => $env['LOCKROT_TARGET_PHP'] ?? null,
            RunSettings::SOURCE_CONFIG => $extra['target-php'] ?? null,
            RunSettings::SOURCE_PLATFORM => $platformPhp === null ? null : self::asComposerReads($platformPhp),
        ], $runtimePhp, RunSettings::SOURCE_RUNTIME);
        if ($source === RunSettings::SOURCE_OPTION && !self::isTargetPhpValue($target)) {
            throw new ConfigException(\sprintf(self::TARGET_PHP_VALUE, '--target-php', $target));
        }
        self::refuseNoVersion($target);
        if ($source === RunSettings::SOURCE_OPTION) {
            self::refuseUnknownMinor('--target-php', $target);
        }

        return [PhpReleaseDates::minorOf($target), $source];
    }

    /** @throws ConfigException for a value that does not look like a PHP version */
    private static function refuseNoVersion(string $value): void
    {
        if (!self::looksLikePhpVersion($value)) {
            throw new ConfigException('target-php must look like "8.4"; got "'.$value.'"');
        }
    }

    /**
     * The target must be a minor that the release data lists, or one above its newest minor, which
     * can be a PHP that the data predates. The data, not the run's clock, sets the known minors, so
     * a value is valid on every date.
     *
     * @throws ConfigException for a minor that is neither known nor above the newest known one
     */
    private static function refuseUnknownMinor(string $setting, string $value): void
    {
        $minor = PhpReleaseDates::minorOf($value);
        $known = PhpReleaseDates::load()->minors(new Clock(new \DateTimeImmutable(self::AFTER_EVERY_GA)));
        $newest = $known === [] ? null : $known[\count($known) - 1];
        if ($newest === null || \in_array($minor, $known, true) || version_compare($minor, $newest, '>')) {
            return;
        }

        throw new ConfigException(\sprintf(self::TARGET_PHP_MINOR, $setting, $minor, ListWords::of($known), $newest, $value));
    }

    /**
     * Composer accepts `v8.4.1` in `config.platform.php`. A value that it refuses sets no target,
     * and the move check reports it (ruling R8-37). Composer's comparator reads an integer segment
     * without its leading zeros, so `8.04.1` is 8.4.1 and names the minor 8.4.
     */
    private static function asComposerReads(string $platformPhp): ?string
    {
        try {
            $normalized = (new VersionParser())->normalize($platformPhp);
        } catch (\UnexpectedValueException $e) {
            return null;
        }

        return preg_replace_callback(
            '{^\d+(?:\.\d+)*}',
            static fn (array $run): string => implode('.', array_map(static fn (string $segment): string => ltrim($segment, '0') ?: '0', explode('.', $run[0]))),
            $normalized,
            1
        ) ?? $normalized;
    }

    /** The running PHP can carry a suffix after the version, such as a distribution build's. */
    private static function looksLikePhpVersion(string $value): bool
    {
        return preg_match('{^\d+(\.\d+)?}', $value) === 1;
    }

    /** A value that the user types: a major, a minor or a patch, with nothing after it. */
    private static function isTargetPhpValue(string $value): bool
    {
        return preg_match('{^\d+(\.\d+){0,2}$}D', $value) === 1;
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

    /**
     * As {@see pick()}, with the key of the candidate that won, else $defaultSource.
     *
     * @template S of string
     *
     * @param array<S, mixed> $candidates by source, first wins
     * @param S               $defaultSource
     *
     * @return array{string, S}
     */
    private static function pickWithSource(array $candidates, string $default, string $defaultSource): array
    {
        foreach ($candidates as $source => $candidate) {
            if (\is_string($candidate) && $candidate !== '') {
                return [$candidate, $source];
            }
        }

        return [$default, $defaultSource];
    }

    /** The parsed list, {@see FailOn::none()} when no source sets it. */
    public function failOn(): FailOn
    {
        return $this->failOn;
    }
    public function targetPhp(): string
    {
        return $this->targetPhp;
    }

    /**
     * The source that set {@see failOn()}.
     *
     * @return RunSettings::SOURCE_OPTION|RunSettings::SOURCE_ENV|RunSettings::SOURCE_CONFIG|RunSettings::SOURCE_DEFAULT
     */
    public function failOnSource(): string
    {
        return $this->failOnSource;
    }

    /**
     * The source that set {@see targetPhp()}.
     *
     * @return RunSettings::SOURCE_OPTION|RunSettings::SOURCE_ENV|RunSettings::SOURCE_CONFIG|RunSettings::SOURCE_PLATFORM|RunSettings::SOURCE_RUNTIME
     */
    public function targetPhpSource(): string
    {
        return $this->targetPhpSource;
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
