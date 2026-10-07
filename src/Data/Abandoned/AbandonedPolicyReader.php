<?php

declare(strict_types=1);

namespace Lockrot\Data\Abandoned;

use Composer\Config;

/**
 * Reads Composer's abandoned ignore list through the API of the Composer that runs lockrot. Each
 * call exists on one range of Composer versions only, which {@see api()} names.
 *
 * @internal
 */
interface AbandonedPolicyReader
{
    /** Composer 2.10 and later: `AbandonedPolicyConfig`. */
    public const POLICY = 'policy';
    /** Composer 2.9: `AuditConfig::fromConfig()`. */
    public const AUDIT_CONFIG = 'audit_config';
    /** Composer 2.2 to 2.8: no list. */
    public const NONE = 'none';

    /** @return self::POLICY|self::AUDIT_CONFIG|self::NONE */
    public function api(): string;

    /**
     * The patterns `composer audit` applies, in config order, from the normalised raw config.
     *
     * @param array<mixed> $policy `config.policy`, an array
     * @param array<mixed> $audit  `config.audit`, an array
     *
     * @return list<array{pattern: string, reason: ?string, constraints: list<string>}>
     *
     * @throws \Throwable when Composer rejects the list
     */
    public function policyRules(array $policy, array $audit): array;

    /**
     * The list as Composer 2.9's `AuditConfig` keeps it: names as a list's values or a map's keys.
     *
     * @return array<mixed>
     *
     * @throws \Throwable when Composer rejects the list
     */
    public function auditConfigList(Config $config): array;
}
