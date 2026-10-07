<?php

declare(strict_types=1);

namespace Lockrot\Data\Abandoned;

use Composer\Config;
use Composer\Package\BasePackage;

/**
 * Composer's abandoned ignore list, read and matched as `composer audit` reads and matches it
 * (docs/verdicts.md#abandoned). A name it matches raises no S1.
 *
 * @internal
 */
final class AbandonedIgnore
{
    private string $by;
    /** @var list<array{pattern: string, reason: ?string, constraints: list<string>}> */
    private array $rules;
    private ?string $whyUnreadable;

    /** @param list<array{pattern: string, reason: ?string, constraints: list<string>}> $rules */
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

    /** @param list<array{pattern: string, reason: ?string, constraints: list<string>}> $rules in config order */
    public static function of(string $by, array $rules): self
    {
        return new self($by, $rules, null);
    }

    /**
     * Composer's config can be wrong for this Composer: the PHAR reads projects written for
     * another one. A list Composer rejects is no list, with the first line of why, never no report.
     */
    public static function fromConfig(Config $config, ?AbandonedPolicyReader $reader = null): self
    {
        $reader ??= new ComposerAbandonedPolicyReader();
        try {
            switch ($reader->api()) {
                case AbandonedPolicyReader::POLICY:
                    return self::fromPolicy($config, $reader);
                case AbandonedPolicyReader::AUDIT_CONFIG:
                    return self::of(AbandonedIgnoreMatch::BY_AUDIT, self::fromAuditConfig($reader->auditConfigList($config)));
                default:
                    return self::none();
            }
        } catch (\Throwable $e) {
            return new self(AbandonedIgnoreMatch::BY_POLICY, [], (string) strtok($e->getMessage(), "\r\n"));
        }
    }

    /**
     * Normalised as `PolicyConfig::fromConfig()` normalises it: Composer's default `policy` is
     * `true`, which the policy API rejects as an argument.
     */
    private static function fromPolicy(Config $config, AbandonedPolicyReader $reader): self
    {
        $raw = $config->get('policy');
        if ($raw === false) {
            return self::none();
        }
        $policy = \is_array($raw) ? $raw : [];
        $audit = $config->get('audit');

        return self::of(
            isset($policy['abandoned']) ? AbandonedIgnoreMatch::BY_POLICY : AbandonedIgnoreMatch::BY_AUDIT,
            $reader->policyRules($policy, \is_array($audit) ? $audit : [])
        );
    }

    /**
     * @param array<mixed> $list
     *
     * @return list<array{pattern: string, reason: ?string, constraints: list<string>}>
     */
    private static function fromAuditConfig(array $list): array
    {
        $rules = [];
        $isList = array_values($list) === $list;
        foreach ($list as $key => $value) {
            if (!$isList) {
                $rules[] = ['pattern' => (string) $key, 'reason' => \is_string($value) ? $value : null, 'constraints' => []];
            } elseif (\is_string($value)) {
                $rules[] = ['pattern' => $value, 'reason' => null, 'constraints' => []];
            }
        }

        return $rules;
    }

    /** Every pattern that matches the name, in config order; null when none does. */
    public function match(string $name): ?AbandonedIgnoreMatch
    {
        $matched = array_values(array_filter($this->rules, static fn (array $rule): bool => preg_match(BasePackage::packageNameToRegexp($rule['pattern']), $name) === 1));

        return $matched === [] ? null : new AbandonedIgnoreMatch($this->by, $matched);
    }

    /** Why Composer rejected the list, possibly empty. Null when it was read. */
    public function whyUnreadable(): ?string
    {
        return $this->whyUnreadable;
    }
}
