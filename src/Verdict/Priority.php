<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

/**
 * The levels and their rules: docs/verdicts.md#priority.
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

    /** Its keys are the flagged verdicts ({@see Verdict::flagged()}). A verdict missing here has no priority. */
    private const BASE = [
        Verdict::ABANDONED => self::CRITICAL,
        Verdict::SILENT => self::CRITICAL,
        Verdict::PINNED => self::HIGH,
        Verdict::LEFT_BEHIND => self::HIGH,
        Verdict::OLD_PROMISE => self::HIGH,
        Verdict::STALE => self::MEDIUM,
    ];

    /** `low` is the floor: a flagged finding stays in the report. */
    private const LOWER = [
        self::CRITICAL => self::HIGH,
        self::HIGH => self::MEDIUM,
        self::MEDIUM => self::LOW,
        self::LOW => self::LOW,
    ];

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

    public static function of(string $verdict, bool $direct, bool $dev, bool $unfixableAdvisory = false): string
    {
        return self::basis($verdict, $direct, $dev, $unfixableAdvisory)->priority();
    }

    /** The steps and their order: docs/verdicts.md#priority. $reached is false for an empty chain. */
    public static function basis(string $verdict, bool $direct, bool $dev, bool $unfixableAdvisory, bool $reached = true): PriorityBasis
    {
        if (!isset(self::BASE[$verdict])) {
            return PriorityBasis::startingAt(self::NONE);
        }
        $basis = PriorityBasis::startingAt(self::BASE[$verdict]);
        if (!$direct) {
            $basis = $basis->withStep($reached ? PriorityBasis::STEP_TRANSITIVE : PriorityBasis::STEP_UNREACHED, self::LOWER[$basis->priority()]);
        }
        if ($dev) {
            $basis = $basis->withStep(PriorityBasis::STEP_DEV, self::LOWER[$basis->priority()]);
        }
        if ($unfixableAdvisory) {
            $basis = $basis->withStep(PriorityBasis::STEP_NO_FIX_EXPECTED, self::RAISE[$basis->priority()]);
        }

        return $basis;
    }
}
