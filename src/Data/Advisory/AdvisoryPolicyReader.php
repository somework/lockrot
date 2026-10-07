<?php

declare(strict_types=1);

namespace Lockrot\Data\Advisory;

use Composer\Config;

/**
 * Reads Composer's audit ignore lists through the API of the Composer that runs lockrot. Each
 * call exists on one range of Composer versions only, which {@see api()} names.
 *
 * @internal
 */
interface AdvisoryPolicyReader
{
    /** Composer 2.10 and later: `AdvisoriesPolicyConfig`. */
    public const POLICY = 'policy';
    /** Composer 2.9.2 and later 2.9 releases: `AuditConfig::fromConfig()`, which applies the `apply` scopes. */
    public const AUDIT_CONFIG = 'audit_config';
    /** Composer 2.9.0 and 2.9.1: the raw `audit.ignore` and `audit.ignore-severity`, no package names. */
    public const AUDIT_SECTION = 'audit_section';
    /** Composer 2.6 to 2.8: the raw `audit.ignore`, no package names. */
    public const AUDIT_IGNORE = 'audit_ignore';
    /** Composer 2.4 and 2.5: no ignore list. */
    public const NONE = 'none';

    /** @return self::POLICY|self::AUDIT_CONFIG|self::AUDIT_SECTION|self::AUDIT_IGNORE|self::NONE */
    public function api(): string;

    /**
     * The lists for audit, from the normalised raw config. `list` holds advisory ids, CVEs, source
     * ids and package names, each with its reason. `audit` is the advisory list's audit mode.
     *
     * @param array<mixed> $policy `config.policy`, an array
     * @param array<mixed> $audit  `config.audit`, an array
     *
     * @return array{list: array<string, ?string>, severities: array<string, ?string>, audit: string}
     *
     * @throws \Throwable when Composer rejects the lists
     */
    public function policy(array $policy, array $audit): array;

    /**
     * @return array{list: array<string, ?string>, severities: array<string, ?string>}
     *
     * @throws \Throwable when Composer rejects the lists
     */
    public function auditConfig(Config $config): array;
}
