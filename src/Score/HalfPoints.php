<?php

declare(strict_types=1);

namespace Lockrot\Score;

/**
 * The engine counts in integer half points. report-2 writes a whole number as an integer and a half
 * as a float, and the score line writes a half as `4.5`.
 *
 * @internal
 */
final class HalfPoints
{
    /** @return int|float PHP's `/` gives an integer for a whole quotient and an exact float for a half */
    public static function json(int $halves)
    {
        return $halves / 2;
    }

    public static function text(int $halves): string
    {
        return intdiv($halves, 2).($halves % 2 === 0 ? '' : '.5');
    }
}
