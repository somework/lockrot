<?php

declare(strict_types=1);

namespace Lockrot\Data\Advisory;

use Composer\Advisory\AuditConfig;
use Composer\Advisory\Auditor;
use Composer\Config;
use Composer\Policy\AdvisoriesPolicyConfig;
use Composer\Semver\VersionParser;

/**
 * Calls the version-specific Composer API of the audit ignore lists. phpstan analyses against the
 * newest Composer, so the Composer 2.9 calls carry ignore rules in phpstan.neon.dist.
 *
 * @internal
 */
final class ComposerAdvisoryPolicyReader implements AdvisoryPolicyReader
{
    public function api(): string
    {
        if (class_exists(AdvisoriesPolicyConfig::class)) {
            return self::POLICY;
        }
        if (!class_exists(AuditConfig::class)) {
            return (new \ReflectionMethod(Auditor::class, 'audit'))->getNumberOfParameters() > 5 ? self::AUDIT_IGNORE : self::NONE;
        }

        return property_exists(AuditConfig::class, 'ignoreListForAudit') ? self::AUDIT_CONFIG : self::AUDIT_SECTION;
    }

    /** Not `PolicyConfig::fromConfig()`: there a bad `malware` section or custom list empties the advisory list too. */
    public function policy(array $policy, array $audit): array
    {
        $advisories = AdvisoriesPolicyConfig::fromRawConfig(array_filter($policy, 'is_string', \ARRAY_FILTER_USE_KEY), array_filter($audit, 'is_string', \ARRAY_FILTER_USE_KEY), new VersionParser());

        return [
            'list' => $advisories->getIgnoreListForOperation('audit'),
            'severities' => $advisories->getIgnoreSeverityForOperation('audit'),
            'audit' => $advisories->audit,
        ];
    }

    public function auditConfig(Config $config): array
    {
        $auditConfig = AuditConfig::fromConfig($config);
        $properties = \is_object($auditConfig) ? get_object_vars($auditConfig) : [];

        return ['list' => self::reasons($properties['ignoreListForAudit'] ?? []), 'severities' => self::reasons($properties['ignoreSeverityForAudit'] ?? [])];
    }

    /**
     * @param mixed $map key => reason, or a list of keys on Composer 2.9.2
     *
     * @return array<string, ?string>
     */
    private static function reasons($map): array
    {
        $map = \is_array($map) ? $map : [];
        $isList = array_values($map) === $map;
        $reasons = [];
        foreach ($map as $key => $reason) {
            if (!$isList) {
                $reasons[(string) $key] = \is_string($reason) ? $reason : null;
            } elseif (\is_string($reason)) {
                $reasons[$reason] = null;
            }
        }

        return $reasons;
    }
}
