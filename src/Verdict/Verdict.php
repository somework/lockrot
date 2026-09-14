<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

final class Verdict
{
    public const ABANDONED = 'abandoned';
    public const SILENT = 'silent';
    public const PINNED = 'pinned';
    public const OLD_PROMISE = 'old-promise';
    public const STALE = 'stale';
    public const UNKNOWN = 'unknown';
    public const FINISHED = 'finished';
    public const OK = 'ok';

    private const SEVERITY = [
        self::ABANDONED => 60, self::SILENT => 50, self::PINNED => 40, self::OLD_PROMISE => 30,
        self::STALE => 20, self::UNKNOWN => 10, self::FINISHED => 0, self::OK => 0,
    ];

    public static function severity(string $verdict): int
    {
        return self::SEVERITY[$verdict] ?? 0;
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::SEVERITY);
    }

    public static function isValid(string $verdict): bool
    {
        return isset(self::SEVERITY[$verdict]);
    }

    public static function flagged(string $verdict): bool
    {
        return self::severity($verdict) >= self::SEVERITY[self::STALE];
    }
}
