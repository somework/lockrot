<?php

declare(strict_types=1);

namespace Lockrot\Data\Advisory;

use Composer\Advisory\PartialSecurityAdvisory;
use Composer\Advisory\SecurityAdvisory;
use Composer\Config;
use Composer\Policy\PolicyConfig;

/**
 * The advisories the project told Composer to ignore, applied the way `composer audit` applies
 * them: by package name, advisory id, CVE, source id (`GHSA-…`) or severity. A project that
 * accepted a vulnerability in `config.policy.advisories` (Composer 2.10+) or `config.audit.ignore`
 * (2.4+) does not need lockrot to raise it again.
 *
 * On a Composer with {@see PolicyConfig} the lists come from it — the same object the audit command
 * reads, flattened as audit flattens them: per-operation flags and reasons are resolved there, and
 * a per-package version constraint is dropped there too, so an entry ignores the package whole on
 * both sides. Older versions had one flat list under `config.audit.ignore`, read directly.
 *
 * @internal
 */
final class AdvisoryIgnore
{
    /** @var array<string, true> package names, advisory ids, CVEs and source ids */
    private array $ids;
    /** @var array<string, true> */
    private array $severities;
    /** Why the list is empty when the project meant it not to be; a report note. */
    private ?string $note;

    /**
     * @param list<string> $ids
     * @param list<string> $severities
     */
    public function __construct(array $ids, array $severities = [], ?string $note = null)
    {
        $this->ids = array_fill_keys($ids, true);
        $this->severities = array_fill_keys($severities, true);
        $this->note = $note;
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function fromConfig(Config $config): self
    {
        // Composer 2.10 introduced the policy object; the guard is load-bearing on every older version.
        if (class_exists(PolicyConfig::class)) {
            // It rejects what it does not know — a `licenses` section reserved for a later
            // Composer, an unknown `ignore-*` key, a constraint it cannot parse — by throwing. A
            // report is not the place to enforce Composer's config schema: the PHAR carries one
            // Composer version and reads projects written for another, so a rejected policy means
            // no ignore list, never no report — and a note, since accepted advisories reappear.
            try {
                $policy = PolicyConfig::fromConfig($config);
            } catch (\Throwable $e) {
                return new self([], [], \sprintf("Composer's advisory ignore list not read (%s); every advisory counts", (string) strtok($e->getMessage(), "\r\n")));
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
     * The 2.4–2.9 shape: each list is either a plain list of strings or a map of string to reason.
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

    /** The report note explaining an ignore list that could not be read, null when it was. */
    public function note(): ?string
    {
        return $this->note;
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
