<?php

declare(strict_types=1);

namespace Lockrot\Data\Advisory;

use Composer\Advisory\PartialSecurityAdvisory;
use Composer\Advisory\SecurityAdvisory;
use Composer\Config;
use Composer\Policy\PolicyConfig;

/**
 * The advisories that the project told Composer to ignore, applied as `composer audit` applies
 * them (docs/verdicts.md#security-advisories). With {@see PolicyConfig}, the lists come from it,
 * flattened as audit flattens them: Composer drops a per-package version constraint there, so an
 * entry ignores the whole package.
 *
 * @internal
 */
final class AdvisoryIgnore
{
    /** @var array<string, true> package names, advisory ids, CVEs and source ids */
    private array $ids;
    /** @var array<string, true> */
    private array $severities;
    private ?string $whyUnreadable;

    /**
     * @param list<string> $ids
     * @param list<string> $severities
     */
    public function __construct(array $ids, array $severities = [], ?string $whyUnreadable = null)
    {
        $this->ids = array_fill_keys($ids, true);
        $this->severities = array_fill_keys($severities, true);
        $this->whyUnreadable = $whyUnreadable;
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function fromConfig(Config $config): self
    {
        // PolicyConfig exists only on Composer 2.10 and later. The guard must stay for older versions.
        if (class_exists(PolicyConfig::class)) {
            // PolicyConfig throws on a policy it does not know. The PHAR reads projects written for
            // another Composer version, so a rejected policy gives no ignore list and a run note,
            // never no report.
            try {
                $policy = PolicyConfig::fromConfig($config);
            } catch (\Throwable $e) {
                return new self([], [], (string) strtok($e->getMessage(), "\r\n"));
            }

            return new self(
                array_keys($policy->advisories->getIgnoreListForOperation('audit')),
                array_keys($policy->advisories->getIgnoreSeverityForOperation('audit'))
            );
        }

        $audit = $config->get('audit');
        if (!\is_array($audit)) {
            return self::none();
        }

        return self::fromRaw(\is_array($audit['ignore'] ?? null) ? $audit['ignore'] : [], \is_array($audit['ignore-severity'] ?? null) ? $audit['ignore-severity'] : []);
    }

    /**
     * Each list is a plain list of strings or a map of string to reason, as on Composer 2.4 to 2.9.
     *
     * @param array<mixed> $ignore
     * @param array<mixed> $ignoreSeverity
     */
    public static function fromRaw(array $ignore, array $ignoreSeverity): self
    {
        return new self(self::keysOrValues($ignore), self::keysOrValues($ignoreSeverity));
    }

    public function ignores(string $package, PartialSecurityAdvisory $advisory): bool
    {
        if (isset($this->ids[$package]) || isset($this->ids[$advisory->advisoryId])) {
            return true;
        }
        if (!$advisory instanceof SecurityAdvisory) {
            return false;
        }
        if ($advisory->cve !== null && isset($this->ids[$advisory->cve])) {
            return true;
        }
        if ($advisory->severity !== null && isset($this->severities[$advisory->severity])) {
            return true;
        }
        foreach ($advisory->sources as $source) {
            if (isset($this->ids[$source['remoteId']])) {
                return true;
            }
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return $this->ids === [] && $this->severities === [];
    }

    /** Why the ignore list could not be read, possibly empty. Null when it was read. */
    public function whyUnreadable(): ?string
    {
        return $this->whyUnreadable;
    }

    /**
     * @param array<mixed> $list
     *
     * @return list<string>
     */
    private static function keysOrValues(array $list): array
    {
        $out = [];
        foreach ($list as $key => $value) {
            if (\is_int($key) && \is_string($value)) {
                $out[] = $value;
            } elseif (\is_string($key)) {
                $out[] = $key;
            }
        }

        return $out;
    }
}
