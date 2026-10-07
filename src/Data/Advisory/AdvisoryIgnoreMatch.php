<?php

declare(strict_types=1);

namespace Lockrot\Data\Advisory;

/**
 * The rule of Composer's audit ignore lists that ignores one advisory. `kind` is what the rule
 * matched on, `key` the rule as written, `by` the Composer setting that holds it. Both `kind` and
 * `by` are open sets: Composer's settings grow outside lockrot.
 *
 * @internal
 */
final class AdvisoryIgnoreMatch
{
    public const ID = 'id';
    public const CVE = 'cve';
    public const REMOTE_ID = 'remote_id';
    public const PACKAGE = 'package';
    public const SEVERITY = 'severity';

    public const BY_POLICY = 'policy.advisories';
    public const BY_AUDIT = 'audit.ignore';
    public const BY_AUDIT_SEVERITY = 'audit.ignore-severity';

    private string $kind;
    private string $key;
    private ?string $reason;
    private string $by;

    public function __construct(string $kind, string $key, ?string $reason, string $by)
    {
        $this->kind = $kind;
        $this->key = $key;
        $this->reason = $reason;
        $this->by = $by;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function key(): string
    {
        return $this->key;
    }

    /** The project's words, data: never part of a machine string. */
    public function reason(): ?string
    {
        return $this->reason;
    }

    public function by(): string
    {
        return $this->by;
    }
}
