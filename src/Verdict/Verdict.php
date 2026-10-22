<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

/**
 * The words that name the maintenance flags, and the verdicts of a score of 0: `finished`,
 * `unknown` and `ok`.
 *
 * @internal
 */
final class Verdict
{
    public const ABANDONED = 'abandoned';
    public const SILENT = 'silent';
    public const PINNED = 'pinned';
    public const LEFT_BEHIND = 'left-behind';
    public const OLD_PROMISE = 'old-promise';
    public const STALE = 'stale';
    public const CAPPED = 'capped';
    public const UNKNOWN = 'unknown';
    public const FINISHED = 'finished';
    public const OK = 'ok';
}
