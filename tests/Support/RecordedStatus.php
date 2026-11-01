<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

/**
 * Which answers bin/record-fixtures keeps on disk. A transport failure, a 429, a 5xx and GitHub's 403 rate limit
 * describe the moment of the recording, not the package: a recorded one would read as a fact in every replay, and a
 * re-run must fetch it again.
 */
final class RecordedStatus
{
    public static function keeps(int $status, bool $github): bool
    {
        return $status !== 0 && $status !== 429 && $status < 500 && !($github && $status === 403);
    }

    /** Whether $path holds an envelope whose status the recorder keeps. */
    public static function isRecorded(string $path, bool $github): bool
    {
        $body = is_file($path) ? @file_get_contents($path) : false;
        $envelope = $body === false ? null : json_decode($body, true);

        return \is_array($envelope) && \is_int($envelope['status'] ?? null) && self::keeps($envelope['status'], $github);
    }
}
