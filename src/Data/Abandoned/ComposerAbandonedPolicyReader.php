<?php

declare(strict_types=1);

namespace Lockrot\Data\Abandoned;

use Composer\Advisory\AuditConfig;
use Composer\Config;
use Composer\Policy\AbandonedPolicyConfig;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Semver\VersionParser;

/**
 * The only class that calls the version-specific Composer API of the abandoned ignore list.
 * phpstan analyses against the newest Composer, so the Composer 2.9 calls carry ignore rules in
 * phpstan.neon.dist.
 *
 * @internal
 */
final class ComposerAbandonedPolicyReader implements AbandonedPolicyReader
{
    public function api(): string
    {
        if (class_exists(AbandonedPolicyConfig::class)) {
            return self::POLICY;
        }
        if (class_exists(AuditConfig::class) && method_exists(AuditConfig::class, 'fromConfig')) {
            return self::AUDIT_CONFIG;
        }

        return self::NONE;
    }

    public function policyRules(array $policy, array $audit): array
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
            $rules[] = ['pattern' => (string) $pattern, 'reason' => $reason, 'constraints' => $constraints];
        }

        return $rules;
    }

    /** Composer 2.9.2 renamed the property when it added `apply` scopes to the list. */
    public function auditConfigList(Config $config): array
    {
        $auditConfig = AuditConfig::fromConfig($config);
        $properties = \is_object($auditConfig) ? get_object_vars($auditConfig) : [];
        $list = $properties['ignoreAbandonedForAudit'] ?? $properties['ignoreAbandonedPackages'] ?? [];

        return \is_array($list) ? $list : [];
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
}
