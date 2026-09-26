<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

/**
 * The second axis next to the verdict: how much a finding is worth a maintainer's attention.
 *
 * The verdict says *what* was observed about a package; the priority says *how loudly it applies to
 * this project*. A package flagged the same way matters less when nothing requires it directly, and
 * less again when it is only ever installed for development. The priority orders the report, is
 * carried in every output format, and decides the exit code only when `fail-on` names a priority
 * ({@see FailOn}); the baseline stays on the verdict.
 *
 * @internal
 */
final class Priority
{
    public const CRITICAL = 'critical';
    public const HIGH = 'high';
    public const MEDIUM = 'medium';
    public const LOW = 'low';
    public const NONE = 'none';

    private const RANK = [
        self::CRITICAL => 4, self::HIGH => 3, self::MEDIUM => 2, self::LOW => 1, self::NONE => 0,
    ];

    /**
     * The level a flagged verdict starts from, before the direct/transitive and prod/dev steps.
     * Its keys are exactly the flagged verdicts, so a verdict missing here is an unflagged one
     * ({@see Verdict::flagged()}) and has no priority at all.
     */
    private const BASE = [
        Verdict::ABANDONED => self::CRITICAL,
        Verdict::SILENT => self::CRITICAL,
        Verdict::PINNED => self::HIGH,
        Verdict::LEFT_BEHIND => self::HIGH,
        Verdict::OLD_PROMISE => self::HIGH,
        Verdict::STALE => self::MEDIUM,
    ];

    /** One step down the ladder. `low` is the floor: a flagged finding never drops out of the report. */
    private const LOWER = [
        self::CRITICAL => self::HIGH,
        self::HIGH => self::MEDIUM,
        self::MEDIUM => self::LOW,
        self::LOW => self::LOW,
    ];

    /** One step up, for a vulnerability nobody will fix ({@see Finding::hasUnfixableAdvisory()}). `critical` is the ceiling. */
    private const RAISE = [
        self::CRITICAL => self::CRITICAL,
        self::HIGH => self::CRITICAL,
        self::MEDIUM => self::HIGH,
        self::LOW => self::MEDIUM,
    ];

    public static function rank(string $priority): int
    {
        return self::RANK[$priority] ?? 0;
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::RANK);
    }

    /**
     * An unflagged verdict has no priority. A flagged one starts at its base level and drops one step
     * for being transitive and one more for being a development dependency, never below `low`.
     *
     * A package with an empty chain — nothing in the project reaches it — counts as transitive.
     *
     * After those steps, a security advisory on a package whose verdict says no fix is coming
     * ($unfixableAdvisory) raises the result one step: `composer audit` already reports the
     * vulnerability; that no release will close it is what the verdict adds. An unflagged verdict
     * is never raised: the advisory alone is audit's finding, not lockrot's.
     */
    public static function of(string $verdict, bool $direct, bool $dev, bool $unfixableAdvisory = false): string
    {
        if (!isset(self::BASE[$verdict])) {
            return self::NONE;
        }
        $priority = self::BASE[$verdict];
        if (!$direct) {
            $priority = self::LOWER[$priority];
        }
        if ($dev) {
            $priority = self::LOWER[$priority];
        }
        if ($unfixableAdvisory) {
            $priority = self::RAISE[$priority];
        }

        return $priority;
    }
}
