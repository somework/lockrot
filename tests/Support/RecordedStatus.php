<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

/**
 * Which recorded answers a recording keeps on disk. A transport failure, a 429, a 5xx, GitHub's 403 rate limit and
 * GitHub's 401 for a refused token describe the moment of the recording, not the package. A kept one reads as a fact
 * in every replay, so a re-run must fetch it again.
 */
final class RecordedStatus
{
    public static function keeps(int $status, bool $github): bool
    {
        return $status !== 0 && $status !== 429 && $status < 500 && !($github && \in_array($status, [401, 403], true));
    }

    /** GitLab answers only for a project that exists or does not: any other status describes the moment, so the recording stays incomplete. */
    public static function keepsGitlab(int $status): bool
    {
        return \in_array($status, [200, 404], true);
    }

    /** Whether $path holds an envelope whose status the recorder keeps. */
    public static function isRecorded(string $path, bool $github): bool
    {
        $body = is_file($path) ? @file_get_contents($path) : false;
        $envelope = $body === false ? null : json_decode($body, true);

        return \is_array($envelope) && \is_int($envelope['status'] ?? null) && self::keeps($envelope['status'], $github);
    }
}
