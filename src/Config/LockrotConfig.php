<?php

declare(strict_types=1);

namespace Lockrot\Config;

use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Exception\ConfigException;
use Lockrot\Signal\Thresholds;
use Lockrot\Verdict\Verdict;

final class LockrotConfig
{
    public const FAIL_ON_NONE = 'none';
    public const FORMATS = ['table', 'json', 'github', 'sarif', 'gitlab', 'markdown'];
    public const DEFAULT_INSTALL_TIME_BUDGET = 5;
    private const INSTALL_TIME_BUDGET_MIN = 1;
    private const INSTALL_TIME_BUDGET_MAX = 120;

    /**
     * The spec's third value, `summary`, is not implemented in 0.1: the compact block *is* the only
     * install-time output there is, so `summary` would be indistinguishable from `on` (SPEC F6,
     * amended).
     */
    public const INSTALL_TIME_VALUES = ['on', 'off'];
    public const INSTALL_TIME_ON = 'on';

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

    private function __construct(string $failOn, string $targetPhp, bool $includeDev, bool $offline, bool $strictNetwork, string $format, ?string $baseline, bool $disabled, bool $installTime, bool $installTimeStrict, int $installTimeBudgetSeconds, Thresholds $thresholds)
    {
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
    }

    /**
     * @param array<string, mixed> $extra composer.json extra.lockrot
     * @param array<string, mixed> $env environment variables
     * @param array<string, mixed> $cli command-line options (fail-on, target-php, dev, offline, strict-network, format, baseline)
     */
    public static function fromSources(array $extra, array $env, array $cli, string $runtimePhp, ?string $platformPhp): self
    {
        $failOn = self::resolveFailOn($extra, $env, $cli);
        $targetPhp = self::resolveTargetPhp($extra, $env, $cli, $runtimePhp, $platformPhp);
        $format = self::resolveFormat($extra, $cli);
        $includeDev = ($cli['dev'] ?? null) === true || ($extra['include-dev'] ?? false) === true;

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
            Thresholds::fromArray($extra)
        );
    }

    /**
     * LOCKROT_DISABLE=1 (or `true`) silences all of lockrot for one command. Exposed on its own so
     * the install-time hook can honour it before it parses `extra.lockrot` at all — otherwise a
     * malformed config would still print a "check skipped" line on every install.
     *
     * @param array<string, mixed> $env
     */
    public static function isDisabledByEnvironment(array $env): bool
    {
        return ($env['LOCKROT_DISABLE'] ?? null) === '1' || ($env['LOCKROT_DISABLE'] ?? null) === 'true';
    }

    /**
     * Neither install-time key — nor install-time-budget, below — has a CLI option or an environment
     * override: the install-time summary is a per-project decision, and LOCKROT_DISABLE already
     * covers the "not right now" case.
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
     * See the docblock on {@see resolveInstallTime()}: no CLI option or environment override.
     * Mirrors {@see Thresholds::fromArray()}'s integer-only rule (a digit string like "5" is
     * rejected, not silently accepted).
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
     */
    private static function resolveFailOn(array $extra, array $env, array $cli): string
    {
        $failOn = self::pick([$cli['fail-on'] ?? null, $env['LOCKROT_FAIL_ON'] ?? null, $extra['fail-on'] ?? null], self::FAIL_ON_NONE);
        if ($failOn !== self::FAIL_ON_NONE && (!Verdict::isValid($failOn) || !Verdict::flagged($failOn))) {
            $allowed = implode(', ', array_values(array_filter(Verdict::all(), static fn (string $verdict): bool => Verdict::flagged($verdict))));
            throw new ConfigException(\sprintf('fail-on must be one of none, %s; got "%s"', $allowed, $failOn));
        }

        return $failOn;
    }

    /**
     * @param array<string, mixed> $extra
     * @param array<string, mixed> $env
     * @param array<string, mixed> $cli
     */
    private static function resolveTargetPhp(array $extra, array $env, array $cli, string $runtimePhp, ?string $platformPhp): string
    {
        $target = self::pick([$cli['target-php'] ?? null, $env['LOCKROT_TARGET_PHP'] ?? null, $extra['target-php'] ?? null, $platformPhp], $runtimePhp);
        if (preg_match('{^\d+(\.\d+)?}', $target) !== 1) {
            throw new ConfigException('target-php must look like "8.4"; got "'.$target.'"');
        }

        return PhpReleaseDates::minorOf($target);
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
     * The baseline file's path, or null for the default `lockrot-baseline.json` next to
     * composer.json. No environment override: which findings a project has accepted is a property
     * of the project, not of the machine the run happens on.
     *
     * @param array<string, mixed> $extra
     * @param array<string, mixed> $cli
     */
    private static function resolveBaseline(array $extra, array $cli): ?string
    {
        // `--baseline=` arrives as an empty string. Falling through to the default file would mean a
        // typo silently gates the build against a file the caller never named, so it is rejected
        // instead. An empty `extra.lockrot.baseline` is caught earlier, by the config schema's
        // minLength, and is left to fall through here.
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
}
