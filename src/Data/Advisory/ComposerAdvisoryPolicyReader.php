<?php

declare(strict_types=1);

namespace Lockrot\Data\Advisory;

use Composer\Advisory\AuditConfig;
use Composer\Config;
use Composer\Policy\AdvisoriesPolicyConfig;
use Composer\Semver\VersionParser;

/**
 * The only class that calls the version-specific Composer API of the audit ignore lists. phpstan
 * analyses against the newest Composer, so the Composer 2.9 calls carry ignore rules in
 * phpstan.neon.dist.
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
            return self::AUDIT_IGNORE;
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
     * @param mixed $map key => reason, as AuditConfig keeps it
     *
     * @return array<string, ?string>
     */
    private static function reasons($map): array
    {
        $reasons = [];
        foreach (\is_array($map) ? $map : [] as $key => $reason) {
            $reasons[(string) $key] = \is_string($reason) ? $reason : null;
        }

        return $reasons;
    }
}
