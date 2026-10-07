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
    /** Composer 2.9.2 to 2.9: `AuditConfig::fromConfig()`, which applies the `apply` scopes. */
    public const AUDIT_CONFIG = 'audit_config';
    /** Composer 2.4 to 2.9.1: the raw `config.audit` lists. */
    public const RAW = 'raw';

    /** @return self::POLICY|self::AUDIT_CONFIG|self::RAW */
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
