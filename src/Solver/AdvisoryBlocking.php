<?php

declare(strict_types=1);

namespace Lockrot\Solver;

use Composer\Advisory\Auditor;
use Composer\Config;
use Composer\DependencyResolver\SecurityAdvisoryPoolFilter;
use Composer\IO\NullIO;
use Composer\Policy\ListPolicyConfig;
use Composer\Policy\PolicyConfig;
use Composer\Util\Platform;
use Lockrot\Exception\ConfigException;

/**
 * Whether an update blocks advisories, read through the API of the Composer that runs lockrot, and
 * Composer's own `SecurityAdvisoryPoolFilter` that blocks them. The filter is Composer's internal
 * API: when this Composer cannot build it, each solve with blocking on fails with that error.
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
    private ?SecurityAdvisoryPoolFilter $filter = null;
    private ?\Throwable $filterError = null;
    /** @var list<value-of<self::SWITCHES>> */
    private array $disabledBy;

    /** @param list<value-of<self::SWITCHES>> $disabledBy the {@see SWITCHES} that turned the blocking off */
    private function __construct(bool $blocks, array $disabledBy = [])
    {
        $this->blocks = $blocks;
        $this->disabledBy = $disabledBy;
    }

    /** @param list<value-of<self::SWITCHES>> $disabledBy */
    private static function none(array $disabledBy = []): self
    {
        return new self(false, $disabledBy);
    }

    /** Composer's blocking with $policy, or the error of a filter that this Composer cannot build. */
    private static function blocking(PolicyConfig $policy): self
    {
        $blocking = new self(true);
        try {
            $blocking->filter = new SecurityAdvisoryPoolFilter(new Auditor(), $policy, new NullIO());
        } catch (\Throwable $e) {
            $blocking->filterError = $e;
        }

        return $blocking;
    }

    /** Composer's default policy: every advisory blocks, and no abandoned package. */
    public static function everyAdvisory(): self
    {
        return self::blocking(PolicyConfig::fromConfig(new Config(false)));
    }

    /** Blocking on, with a pool filter that this Composer could not build: for a test of that failure. */
    public static function unbuildable(\Throwable $error): self
    {
        $blocking = new self(true);
        $blocking->filterError = $error;

        return $blocking;
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

        return self::blocking($policy);
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

    /** Composer's pool filter, null when the blocking is off or when this Composer could not build it ({@see filterError()}). */
    public function poolFilter(): ?SecurityAdvisoryPoolFilter
    {
        return $this->filter;
    }

    public function filterError(): ?\Throwable
    {
        return $this->filterError;
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
