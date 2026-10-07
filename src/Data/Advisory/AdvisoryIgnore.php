<?php

declare(strict_types=1);

namespace Lockrot\Data\Advisory;

use Composer\Advisory\PartialSecurityAdvisory;
use Composer\Advisory\SecurityAdvisory;
use Composer\Config;

/**
 * The advisories that the project told Composer to ignore, matched as `composer audit` matches
 * them (docs/verdicts.md#security-advisories), and whether its policy turns advisories off.
 * Composer drops a per-package version constraint for audit, so a package rule ignores the whole
 * package.
 *
 * @internal
 */
final class AdvisoryIgnore
{
    /** @var array<string, ?string> advisory ids, CVEs, source ids and package names => reason */
    private array $list;
    /** @var array<string, ?string> */
    private array $severities;
    private bool $fromPolicy;
    private ?string $whyUnreadable;
    /** @var array{policy_key: string, value: bool|string}|null */
    private ?array $disabledBy;

    /**
     * @param array<string, ?string>                              $list
     * @param array<string, ?string>                              $severities
     * @param array{policy_key: string, value: bool|string}|null $disabledBy
     */
    private function __construct(array $list, array $severities, bool $fromPolicy, ?string $whyUnreadable = null, ?array $disabledBy = null)
    {
        $this->list = $list;
        $this->severities = $severities;
        $this->fromPolicy = $fromPolicy;
        $this->whyUnreadable = $whyUnreadable;
        $this->disabledBy = $disabledBy;
    }

    public static function none(): self
    {
        return new self([], [], false);
    }

    public static function unreadable(string $why): self
    {
        return new self([], [], false, $why);
    }

    /** @param bool|string $value */
    public static function disabled(string $policyKey, $value): self
    {
        return new self([], [], true, null, ['policy_key' => $policyKey, 'value' => $value]);
    }

    /**
     * Composer's config can be wrong for this Composer: the PHAR reads projects written for
     * another one. Lists Composer rejects ignore nothing, with the first line of why, never no
     * report.
     */
    public static function fromConfig(Config $config, ?AdvisoryPolicyReader $reader = null): self
    {
        $reader ??= new ComposerAdvisoryPolicyReader();
        try {
            $api = $reader->api();
            if ($api === AdvisoryPolicyReader::POLICY) {
                return self::fromPolicy($config, $reader);
            }
            if ($api === AdvisoryPolicyReader::AUDIT_CONFIG) {
                $lists = $reader->auditConfig($config);

                return new self($lists['list'], $lists['severities'], false);
            }
            $audit = $config->get('audit');

            return \is_array($audit) ? self::fromRaw(\is_array($audit['ignore'] ?? null) ? $audit['ignore'] : [], \is_array($audit['ignore-severity'] ?? null) ? $audit['ignore-severity'] : []) : self::none();
        } catch (\Throwable $e) {
            return self::unreadable((string) strtok($e->getMessage(), "\r\n"));
        }
    }

    /**
     * Normalised as `PolicyConfig::fromConfig()` normalises it: Composer's default `policy` is
     * `true`, which the policy API rejects as an argument. `COMPOSER_POLICY=0` reads as `false`.
     */
    private static function fromPolicy(Config $config, AdvisoryPolicyReader $reader): self
    {
        $raw = $config->get('policy');
        if ($raw === false) {
            return self::disabled(($config->raw()['config']['policy'] ?? true) === false ? 'policy' : 'COMPOSER_POLICY', false);
        }
        $policy = \is_array($raw) ? $raw : [];
        if (($policy['advisories'] ?? null) === false) {
            return self::disabled('policy.advisories', false);
        }
        $audit = $config->get('audit');
        $lists = $reader->policy($policy, \is_array($audit) ? $audit : []);
        if ($lists['audit'] === 'ignore') {
            return self::disabled('policy.advisories.audit', 'ignore');
        }

        return new self($lists['list'], $lists['severities'], isset($policy['advisories']));
    }

    /**
     * Each list is a plain list of strings or a map of string to reason, as on Composer 2.4 to 2.9.
     *
     * @param array<mixed> $ignore
     * @param array<mixed> $ignoreSeverity
     */
    public static function fromRaw(array $ignore, array $ignoreSeverity): self
    {
        return new self(self::keysOrValues($ignore), self::keysOrValues($ignoreSeverity), false);
    }

    /**
     * The rule that ignores the advisory, null when none does. Composer's Auditor checks the
     * package, the id, the severity, the CVE and the source ids in that order, and the last rule
     * that matches gives the reason: so does this.
     */
    public function match(string $package, PartialSecurityAdvisory $advisory): ?AdvisoryIgnoreMatch
    {
        $match = $this->listed(AdvisoryIgnoreMatch::PACKAGE, $package);
        $match = $this->listed(AdvisoryIgnoreMatch::ID, $advisory->advisoryId) ?? $match;
        if (!$advisory instanceof SecurityAdvisory) {
            return $match;
        }
        if ($advisory->severity !== null && \array_key_exists($advisory->severity, $this->severities)) {
            $match = new AdvisoryIgnoreMatch(AdvisoryIgnoreMatch::SEVERITY, $advisory->severity, $this->severities[$advisory->severity], $this->fromPolicy ? AdvisoryIgnoreMatch::BY_POLICY : AdvisoryIgnoreMatch::BY_AUDIT_SEVERITY);
        }
        if ($advisory->cve !== null) {
            $match = $this->listed(AdvisoryIgnoreMatch::CVE, $advisory->cve) ?? $match;
        }
        foreach ($advisory->sources as $source) {
            $bySource = $this->listed(AdvisoryIgnoreMatch::REMOTE_ID, $source['remoteId']);
            if ($bySource !== null) {
                return $bySource;
            }
        }

        return $match;
    }

    private function listed(string $kind, string $key): ?AdvisoryIgnoreMatch
    {
        return \array_key_exists($key, $this->list) ? new AdvisoryIgnoreMatch($kind, $key, $this->list[$key], $this->fromPolicy ? AdvisoryIgnoreMatch::BY_POLICY : AdvisoryIgnoreMatch::BY_AUDIT) : null;
    }

    /** Why the ignore lists could not be read, possibly empty. Null when they were read. */
    public function whyUnreadable(): ?string
    {
        return $this->whyUnreadable;
    }

    /**
     * The setting that turns advisories off, null when none does: `policy`, `policy.advisories`,
     * `policy.advisories.audit` or the `COMPOSER_POLICY` variable.
     *
     * @return array{policy_key: string, value: bool|string}|null
     */
    public function disabledBy(): ?array
    {
        return $this->disabledBy;
    }

    /**
     * @param array<mixed> $list
     *
     * @return array<string, ?string>
     */
    private static function keysOrValues(array $list): array
    {
        $out = [];
        foreach ($list as $key => $value) {
            if (\is_int($key) && \is_string($value)) {
                $out[$value] = null;
            } elseif (\is_string($key)) {
                $out[$key] = \is_string($value) ? $value : null;
            }
        }

        return $out;
    }
}
