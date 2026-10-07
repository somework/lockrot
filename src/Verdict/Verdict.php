<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

use Lockrot\Legacy\Verdict013;

/**
 * The report-1 verdicts that {@see Finding::verdict()} returns.
 *
 * @internal
 */
final class Verdict
{
    public const ABANDONED = Verdict013::ABANDONED;
    public const SILENT = Verdict013::SILENT;
    public const PINNED = Verdict013::PINNED;
    public const LEFT_BEHIND = Verdict013::LEFT_BEHIND;
    public const OLD_PROMISE = Verdict013::OLD_PROMISE;
    public const STALE = Verdict013::STALE;
    public const UNKNOWN = Verdict013::UNKNOWN;
    public const FINISHED = Verdict013::FINISHED;
    public const OK = Verdict013::OK;

    /** @deprecated misreads a grade: call {@see Verdict013::severity()} on a report-1 verdict */
    public static function severity(string $verdict): int
    {
        return Verdict013::severity($verdict);
    }

    /**
     * @deprecated misreads a grade: call {@see Verdict013::all()}
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return Verdict013::all();
    }

    /** @deprecated misreads a grade: call {@see Verdict013::isValid()} on a report-1 verdict */
    public static function isValid(string $verdict): bool
    {
        return Verdict013::isValid($verdict);
    }

    /** @deprecated misreads a grade: call {@see Verdict013::flagged()} on a report-1 verdict, or {@see Finding::isGraded()} */
    public static function flagged(string $verdict): bool
    {
        return Verdict013::flagged($verdict);
    }
}
