<?php

declare(strict_types=1);

namespace Lockrot\Filesystem;

use Lockrot\Exception\ConfigException;

/**
 * How a file is written: SECURITY.md#what-lockrot-does-and-does-not-do. A truncated baseline
 * reads as "these findings were never accepted", so the write must stay atomic.
 *
 * Do not use Composer's JsonFile::write(): it ignores the result of file_put_contents(), so an
 * unwritable target fails silently.
 *
 * @internal
 */
final class AtomicWriter
{
    /**
     * @param string $path        where the file goes, absolute
     * @param string $displayPath the path as the user gave it, for the error message
     *
     * @throws ConfigException when the target cannot be written
     */
    public static function write(string $path, string $contents, string $displayPath): void
    {
        // Unique per run, and in the target's own directory so the rename stays on one filesystem and
        // atomic: two concurrent runs must not rename each other's half-written file.
        $temporary = \sprintf('%s.%d-%s.tmp', $path, getmypid(), uniqid('', true));
        $failure = null;
        // Not error_get_last(): Composer's ErrorHandler returns true for a suppressed warning, so
        // PHP never records it there.
        set_error_handler(static function (int $level, string $message) use (&$failure): bool {
            $failure = $message;

            return true;
        });
        try {
            $handle = @fopen($temporary, 'xb');
            if ($handle === false) {
                // Nothing of ours to clean up: whatever sits at the name, if anything, is not this run's.
                throw new ConfigException('Cannot write '.$displayPath.': '.self::reason($failure));
            }
            $written = @fwrite($handle, $contents);
            // Data on disk before the rename: else a power loss can leave the target empty (PHP 8.1+).
            if (\function_exists('fsync')) {
                @fsync($handle);
            }
            $closed = @fclose($handle);
            if ($written !== \strlen($contents) || !$closed || !self::keepPermissions($path, $temporary) || !@rename($temporary, $path)) {
                // Read before the cleanup: unlink() can record a failure of its own, which
                // else replaces the reason the caller needs.
                $reason = self::reason($failure);
                @unlink($temporary);

                throw new ConfigException('Cannot write '.$displayPath.': '.$reason);
            }
        } finally {
            restore_error_handler();
        }
    }

    private static function keepPermissions(string $path, string $temporary): bool
    {
        $mode = @fileperms($path);

        return $mode === false || @chmod($temporary, $mode & 0777);
    }

    /** @param ?string $failure the last warning of the write, null for a short write without one */
    private static function reason(?string $failure): string
    {
        return $failure ?? 'the write failed';
    }
}
