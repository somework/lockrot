<?php

declare(strict_types=1);

namespace Lockrot\Data\Abandoned;

use Composer\Config;
use Composer\Package\BasePackage;
use Composer\Policy\AbandonedPolicyConfig;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Semver\VersionParser;
use Composer\Util\Platform;

/**
 * Composer's abandoned ignore list, read and matched as `composer audit` reads and matches it
 * (docs/verdicts.md#abandoned). A name it matches raises no S1.
 *
 * @internal
 */
final class AbandonedIgnore
{
    private string $by;
    /** @var list<AbandonedIgnoreRule> */
    private array $rules;
    private ?string $whyUnreadable;
    private ?AbandonedAuditOff $notAuditedBy = null;

    /** @param list<AbandonedIgnoreRule> $rules */
    private function __construct(string $by, array $rules, ?string $whyUnreadable)
    {
        $this->by = $by;
        $this->rules = $rules;
        $this->whyUnreadable = $whyUnreadable;
    }

    public static function none(): self
    {
        return new self(AbandonedIgnoreMatch::BY_POLICY, [], null);
    }

    /** @param list<AbandonedIgnoreRule> $rules in config order */
    public static function of(string $by, array $rules): self
    {
        return new self($by, $rules, null);
    }

    /**
     * Composer's config can be wrong for this Composer: the PHAR reads projects written for
     * another one. A list Composer rejects is no list, with the first line of why, never no report.
     */
    public static function fromConfig(Config $config): self
    {
        $ignore = self::listOf($config);
        try {
            $ignore->notAuditedBy = self::notAudited($config);
        } catch (\Throwable $e) {
            // The list read the same config and holds why Composer rejected it.
            $ignore->notAuditedBy = null;
        }

        return $ignore;
    }

    private static function listOf(Config $config): self
    {
        try {
            return self::fromPolicy($config);
        } catch (\Throwable $e) {
            return new self(AbandonedIgnoreMatch::BY_POLICY, [], (string) strtok($e->getMessage(), "\r\n"));
        }
    }

    /**
     * The setting that turns the abandoned audit off, in Composer's precedence: a policy that is off
     * (`policy: false` or `COMPOSER_POLICY=0`), then the environment variable, then the policy when
     * it names `abandoned`, then the legacy `audit.abandoned`.
     */
    private static function notAudited(Config $config): ?AbandonedAuditOff
    {
        $policy = $config->get('policy');
        if ($policy === false) {
            return ($config->raw()['config']['policy'] ?? null) === false ? new AbandonedAuditOff('policy', false) : new AbandonedAuditOff('COMPOSER_POLICY', false);
        }
        $env = Platform::getEnv('COMPOSER_AUDIT_ABANDONED');
        if (\is_string($env) && $env !== '') {
            return $env === 'ignore' ? new AbandonedAuditOff('COMPOSER_AUDIT_ABANDONED', 'ignore') : null;
        }
        if (\is_array($policy) && \array_key_exists('abandoned', $policy)) {
            $abandoned = $policy['abandoned'];
            if ($abandoned === false) {
                return new AbandonedAuditOff('policy.abandoned', false);
            }

            return \is_array($abandoned) && ($abandoned['audit'] ?? null) === 'ignore' ? new AbandonedAuditOff('policy.abandoned.audit', 'ignore') : null;
        }
        $audit = $config->get('audit');

        return \is_array($audit) && ($audit['abandoned'] ?? null) === 'ignore' ? new AbandonedAuditOff('audit.abandoned', 'ignore') : null;
    }

    /**
     * Normalised as `PolicyConfig::fromConfig()` normalises it: Composer's default `policy` is
     * `true`, which the policy API rejects as an argument.
     */
    private static function fromPolicy(Config $config): self
    {
        $raw = $config->get('policy');
        if ($raw === false) {
            return self::none();
        }
        $policy = \is_array($raw) ? $raw : [];
        $audit = $config->get('audit');

        return self::of(
            isset($policy['abandoned']) ? AbandonedIgnoreMatch::BY_POLICY : AbandonedIgnoreMatch::BY_AUDIT,
            self::policyRules($policy, \is_array($audit) ? $audit : [])
        );
    }

    /**
     * The patterns `composer audit` applies, in config order.
     *
     * @param array<mixed> $policy
     * @param array<mixed> $audit
     *
     * @return list<AbandonedIgnoreRule>
     */
    private static function policyRules(array $policy, array $audit): array
    {
        $list = AbandonedPolicyConfig::fromRawConfig(self::stringKeys($policy), self::stringKeys($audit), new VersionParser());
        $rulesByPattern = $list->getIgnoreForOperation('audit');
        $rules = [];
        foreach ($list->getFlatIgnoreForOperation('audit') as $pattern => $reason) {
            $constraints = [];
            foreach ($rulesByPattern[$pattern] ?? [] as $rule) {
                if (!$rule->constraint instanceof MatchAllConstraint) {
                    $constraints[] = $rule->constraint->getPrettyString();
                }
            }
            $rules[] = new AbandonedIgnoreRule((string) $pattern, $reason, $constraints);
        }

        return $rules;
    }

    /**
     * @param array<mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function stringKeys(array $config): array
    {
        return array_filter($config, 'is_string', \ARRAY_FILTER_USE_KEY);
    }

    /** Every pattern that matches the name, in config order. Null when none does. */
    public function match(string $name): ?AbandonedIgnoreMatch
    {
        $matched = array_values(array_filter($this->rules, static fn (AbandonedIgnoreRule $rule): bool => preg_match(BasePackage::packageNameToRegexp($rule->pattern()), $name) === 1));

        return $matched === [] ? null : new AbandonedIgnoreMatch($this->by, $matched);
    }

    /**
     * The Composer setting that stops `composer audit` reporting abandoned packages, null when none does.
     */
    public function notAuditedBy(): ?AbandonedAuditOff
    {
        return $this->notAuditedBy;
    }

    /** Why Composer rejected the list, possibly empty. Null when it was read. */
    public function whyUnreadable(): ?string
    {
        return $this->whyUnreadable;
    }
}
