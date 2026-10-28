<?php

declare(strict_types=1);

namespace Lockrot\Solver;

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
     * @throws ConfigException when Composer rejects the policy or audit configuration, or a variable
     *                         that it reads: each update that lockrot prints would fail on it
     */
    public static function fromConfig(Config $config): self
    {
        $policyVariable = self::isTrue(self::POLICY_VARIABLE);
        // PolicyConfig::fromConfig() returns before it reads this variable when `policy` is off.
        $advisoriesVariable = $config->get('policy') === false ? null : self::isTrue(self::ADVISORIES_VARIABLE);
        try {
            $policy = PolicyConfig::fromConfig($config);
        } catch (\Throwable $e) {
            throw new ConfigException('Composer rejects the policy configuration: '.$e->getMessage(), 0, $e);
        }
        $disabledBy = self::noBlocking();
        if (!$policy->enabled || !$policy->advisories->shouldBlock(ListPolicyConfig::BLOCK_SCOPE_UPDATE)) {
            return self::none(array_merge($disabledBy, self::policySwitch($config, $policyVariable, $advisoriesVariable)));
        }
        if ($disabledBy !== []) {
            return self::none($disabledBy);
        }

        return new self(true, $policy->advisories->getIgnoreListForOperation('block'), $policy->advisories->getIgnoreSeverityForOperation('block'), $policy->abandoned->shouldBlock(ListPolicyConfig::BLOCK_SCOPE_UPDATE), $policy->abandoned->getFlatIgnoreForOperation('block'));
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
     * The variables of BaseCommand::createPolicyConfig() that turn the blocking off. Each sets its
     * command option with `(bool) Platform::getEnv()`, so `false` and `off` count. Composer 2.10
     * also reads each with Platform::getBoolEnv() until one is on.
     *
     * @return list<value-of<self::SWITCHES>>
     *
     * @throws ConfigException when Composer refuses the value of a variable that it reads
     */
    private static function noBlocking(): array
    {
        $disabledBy = [];
        $checked = true;
        foreach (['COMPOSER_NO_BLOCKING', 'COMPOSER_NO_SECURITY_BLOCKING'] as $name) {
            $checked = $checked && self::isTrue($name) !== true;
            if (!\in_array(Platform::getEnv($name), [false, '', '0'], true)) {
                $disabledBy[] = $name;
            }
        }

        return $disabledBy;
    }

    /**
     * A variable as Composer 2.10 reads it with Platform::getBoolEnv(), which refuses a value
     * outside its set. Null when the variable is not set or empty.
     *
     * @param non-empty-string $name
     *
     * @throws ConfigException with Composer's words, which name the variable
     */
    private static function isTrue(string $name): ?bool
    {
        try {
            return Platform::getBoolEnv($name);
        } catch (\RuntimeException $e) {
            throw new ConfigException($e->getMessage());
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
        if (($policy['advisories'] ?? []) === false) {
            return [self::ADVISORIES_KEY];
        }

        // The caller asks only when the blocking is off, and a `policy.advisories` list blocks unless its `block` is false.
        return [self::BLOCK_KEY];
    }
}
