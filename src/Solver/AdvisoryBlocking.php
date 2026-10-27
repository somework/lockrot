<?php

declare(strict_types=1);

namespace Lockrot\Solver;

use Composer\Advisory\AuditConfig;
use Composer\Advisory\Auditor;
use Composer\Advisory\PartialSecurityAdvisory;
use Composer\Config;
use Composer\Package\PackageInterface;
use Composer\Policy\ListPolicyConfig;
use Composer\Policy\PolicyConfig;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Util\Platform;
use Lockrot\Exception\ConfigException;

/**
 * What Composer 2.9 and later block in an update: the settings of the `block` operation, read
 * through the API of the Composer that runs lockrot, and applied with Composer's own Auditor, as
 * `SecurityAdvisoryPoolFilter` applies them. phpstan analyses against the newest Composer, so the
 * Composer 2.9 settings are read as the object's properties.
 *
 * @internal
 */
final class AdvisoryBlocking
{
    /** Every switch that turns the blocking of advisories off, in the order of `run.move_check.blocking_disabled_by`. */
    public const SWITCHES = ['COMPOSER_NO_BLOCKING', 'COMPOSER_NO_SECURITY_BLOCKING', self::POLICY_VARIABLE, self::ADVISORIES_VARIABLE, self::POLICY_KEY, self::ADVISORIES_KEY, self::BLOCK_KEY, self::AUDIT_KEY];
    private const POLICY_VARIABLE = 'COMPOSER_POLICY';
    private const ADVISORIES_VARIABLE = 'COMPOSER_POLICY_ADVISORIES_BLOCK';
    private const POLICY_KEY = 'config.policy';
    private const ADVISORIES_KEY = 'config.policy.advisories';
    private const BLOCK_KEY = 'config.policy.advisories.block';
    private const AUDIT_KEY = 'config.audit.block-insecure';
    private bool $blocks;
    /** @var array<int|string, ?string> */
    private array $list;
    /** @var array<string, ?string> */
    private array $severities;
    private bool $abandoned;
    /** @var array<int|string, ?string> */
    private array $abandonedIgnore;
    /** @var list<value-of<self::SWITCHES>> */
    private array $disabledBy;

    /**
     * Each list keeps the keys that this Composer's Auditor reads: a list on Composer 2.9.0 and 2.9.1.
     *
     * @param array<int|string, ?string> $list            the ignore list for blocking
     * @param array<string, ?string>     $severities      the severities that blocking ignores
     * @param array<int|string, ?string> $abandonedIgnore the abandoned packages that blocking ignores
     * @param list<value-of<self::SWITCHES>> $disabledBy the {@see SWITCHES} that turned the blocking off
     */
    private function __construct(bool $blocks, array $list, array $severities, bool $abandoned, array $abandonedIgnore, array $disabledBy = [])
    {
        $this->blocks = $blocks;
        $this->list = $list;
        $this->severities = $severities;
        $this->abandoned = $abandoned;
        $this->abandonedIgnore = $abandonedIgnore;
        $this->disabledBy = $disabledBy;
    }

    /** @param list<value-of<self::SWITCHES>> $disabledBy */
    private static function none(array $disabledBy = []): self
    {
        return new self(false, [], [], false, [], $disabledBy);
    }

    /** Every advisory blocks, and no abandoned package: a test's Composer 2.9 default. */
    public static function everyAdvisory(): self
    {
        return new self(true, [], [], false, []);
    }

    /**
     * A config that Composer rejects blocks every advisory: the update that it runs fails anyway.
     *
     * @throws ConfigException when Composer refuses the value of a variable that turns the blocking off
     */
    public static function fromConfig(Config $config): self
    {
        if (!ComposerFeatures::blocksAdvisories()) {
            return self::none();
        }
        $version = ComposerFeatures::version();
        $disabledBy = array_values(array_filter(ComposerFeatures::blockingVariables($version), static fn (string $name): bool => self::isTrue($name, $version) === true));
        if (class_exists(PolicyConfig::class)) {
            $policyVariable = self::isTrue(self::POLICY_VARIABLE, $version);
            $advisoriesVariable = self::isTrue(self::ADVISORIES_VARIABLE, $version);
            try {
                $policy = PolicyConfig::fromConfig($config);
            } catch (\Throwable $e) {
                return $disabledBy === [] ? self::everyAdvisory() : self::none($disabledBy);
            }
            if (!$policy->enabled || !$policy->advisories->shouldBlock(ListPolicyConfig::BLOCK_SCOPE_UPDATE)) {
                return self::none(array_merge($disabledBy, self::policySwitch($config, $policyVariable, $advisoriesVariable)));
            }
            if ($disabledBy !== []) {
                return self::none($disabledBy);
            }

            return new self(true, $policy->advisories->getIgnoreListForOperation('block'), $policy->advisories->getIgnoreSeverityForOperation('block'), $policy->abandoned->shouldBlock(ListPolicyConfig::BLOCK_SCOPE_UPDATE), $policy->abandoned->getFlatIgnoreForOperation('block'));
        }
        // Composer 2.10 has no AuditConfig::fromConfig().
        $factory = [AuditConfig::class, 'fromConfig'];
        try {
            $audit = \is_callable($factory) ? $factory($config) : null;
        } catch (\Throwable $e) {
            return $disabledBy === [] ? self::everyAdvisory() : self::none($disabledBy);
        }
        $settings = \is_object($audit) ? get_object_vars($audit) : [];
        if (($settings['blockInsecure'] ?? true) === false) {
            return self::none(array_merge($disabledBy, [self::AUDIT_KEY]));
        }
        if ($disabledBy !== []) {
            return self::none($disabledBy);
        }
        // Composer 2.9.2 and later split each list by operation, 2.9.0 and 2.9.1 block with the whole audit list and no severity.
        if (\array_key_exists('ignoreListForBlocking', $settings)) {
            return new self(true, self::listOf($settings['ignoreListForBlocking']), self::severitiesOf($settings['ignoreSeverityForBlocking'] ?? []), ($settings['blockAbandoned'] ?? false) === true, self::listOf($settings['ignoreAbandonedForBlocking'] ?? []));
        }

        return new self(true, self::listOf($settings['ignoreList'] ?? []), [], ($settings['blockAbandoned'] ?? false) === true, self::listOf($settings['ignoreAbandonedPackages'] ?? []));
    }

    public function blocks(): bool
    {
        return $this->blocks;
    }

    /** @return list<value-of<self::SWITCHES>> the switches that turned the blocking off, in the order of {@see SWITCHES} */
    public function disabledBy(): array
    {
        return $this->disabledBy;
    }

    /**
     * The ranges that an update blocks, by package name.
     *
     * @param array<string, list<PartialSecurityAdvisory>> $records every record of each name
     *
     * @return array<string, list<ConstraintInterface>>
     */
    public function ranges(array $records): array
    {
        if (!$this->blocks || $records === []) {
            return [];
        }
        $ranges = [];
        // Composer before 2.9 has no public processAdvisories(): with no ignore rule, every record blocks.
        if ($this->list === [] && $this->severities === []) {
            foreach ($records as $name => $advisories) {
                foreach ($advisories as $advisory) {
                    $ranges[$name][] = $advisory->affectedVersions;
                }
            }

            return $ranges;
        }
        // @phpstan-ignore argument.type (the list keeps the shape that this Composer's Auditor reads)
        foreach ((new Auditor())->processAdvisories($records, $this->list, $this->severities)['advisories'] as $name => $advisories) {
            foreach ($advisories as $advisory) {
                $ranges[$name][] = $advisory->affectedVersions;
            }
        }

        return $ranges;
    }

    /**
     * Whether partial records leave out what the ignore list matches: Composer 2.9.2 and later then
     * ask the complete records.
     *
     * @param array<string, list<PartialSecurityAdvisory>> $records
     */
    public function needsComplete(array $records): bool
    {
        // @phpstan-ignore function.alreadyNarrowedType (Composer 2.9.0 and 2.9.1 have no such method), argument.type (the list keeps the shape that this Composer's Auditor reads)
        return method_exists(Auditor::class, 'needsCompleteAdvisoryLoad') && (new Auditor())->needsCompleteAdvisoryLoad($records, $this->list);
    }

    public function blocksAbandoned(PackageInterface $package): bool
    {
        // @phpstan-ignore argument.type (the list keeps the shape that this Composer's Auditor reads)
        return $this->abandoned && (new Auditor())->filterAbandonedPackages([$package], $this->abandonedIgnore) !== [];
    }

    /**
     * A variable as this Composer reads it: Composer 2.10 and later with Platform::getBoolEnv(),
     * which refuses a value outside its set, and Composer 2.9 as `(bool) Platform::getEnv()`.
     * Null when the variable is not set.
     *
     * @param non-empty-string $name
     *
     * @throws ConfigException with Composer's words, which name the variable
     */
    private static function isTrue(string $name, string $version): ?bool
    {
        if (version_compare($version, '2.10.0-dev', '<')) {
            $value = Platform::getEnv($name);

            return $value === false ? null : (bool) $value;
        }
        try {
            return Platform::getBoolEnv($name);
        } catch (\RuntimeException $e) {
            throw new ConfigException($e->getMessage(), 0, $e);
        }
    }

    /**
     * The switch that turned the blocking of advisories off, as Composer 2.10 reads them: `policy`
     * after COMPOSER_POLICY, then `policy.advisories` or the legacy `audit` that it replaces, then
     * COMPOSER_POLICY_ADVISORIES_BLOCK, which a `policy` that is off leaves unread.
     *
     * @return list<value-of<self::SWITCHES>> one switch, none when Composer reads no switch as off
     */
    private static function policySwitch(Config $config, ?bool $policyVariable, ?bool $advisoriesVariable): array
    {
        if ($config->get('policy') === false) {
            return [$policyVariable === false ? self::POLICY_VARIABLE : self::POLICY_KEY];
        }
        if ($advisoriesVariable !== null) {
            return $advisoriesVariable ? [] : [self::ADVISORIES_VARIABLE];
        }
        $policy = \is_array($config->get('policy')) ? $config->get('policy') : [];
        $audit = \is_array($config->get('audit')) ? $config->get('audit') : [];
        if (!isset($policy['advisories']) && $audit !== []) {
            return ($audit['block-insecure'] ?? true) ? [] : [self::AUDIT_KEY];
        }
        $advisories = $policy['advisories'] ?? [];
        if ($advisories === false) {
            return [self::ADVISORIES_KEY];
        }

        return (bool) (\is_array($advisories) ? $advisories['block'] ?? true : true) ? [] : [self::BLOCK_KEY];
    }

    /**
     * @param mixed $value
     *
     * @return array<string, ?string>
     */
    private static function severitiesOf($value): array
    {
        $severities = [];
        foreach (self::listOf($value) as $severity => $reason) {
            $severities[(string) $severity] = $reason;
        }

        return $severities;
    }

    /**
     * @param mixed $value
     *
     * @return array<int|string, ?string> the keys as written, a value that is no string as null
     */
    private static function listOf($value): array
    {
        return array_map(static fn ($reason): ?string => \is_string($reason) ? $reason : null, \is_array($value) ? $value : []);
    }
}
