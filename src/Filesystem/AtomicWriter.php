<?php

declare(strict_types=1);

namespace Lockrot\Filesystem;

use Lockrot\Exception\ConfigException;

/**
 * Every file lockrot writes in a project — the baseline and the `--output` reports — goes through
 * here: the contents go to a sibling `.tmp` file first, which is then renamed over the target, so a
 * run interrupted mid-write never leaves a truncated file behind. A truncated baseline would read as
 * "these findings were never accepted" on the next CI run; a truncated SARIF file as a broken upload.
 *
 * Composer's own JsonFile::write() is not used: it calls file_put_contents() without checking the
 * result, so an unwritable target returns silently. Directories are never created; a missing one is
 * a failed write like any other.
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
        // Unique per run, and in the target's own directory so the rename below stays within one
        // filesystem and therefore atomic: two concurrent runs in the same workspace must not be
        // able to rename each other's half-written file.
        $temporary = \sprintf('%s.%d-%s.tmp', $path, getmypid(), uniqid('', true));
        // Cleared first so reason() below reports this write's own failure and never an unrelated
        // warning some earlier part of the run left behind.
        error_clear_last();
        $written = @file_put_contents($temporary, $contents);
        if ($written !== \strlen($contents)) {
            // Read before the cleanup: unlink() on a temp file that was never created records a
            // failure of its own, which would otherwise replace the reason the caller needs.
            $reason = self::reason();
            @unlink($temporary);

            throw new ConfigException('Cannot write '.$displayPath.': '.$reason);
        }
        if (!@rename($temporary, $path)) {
            $reason = self::reason();
            @unlink($temporary);

            throw new ConfigException('Cannot write '.$displayPath.': '.$reason);
        }
    }

    /** The last filesystem failure PHP recorded, or a generic reason when it recorded none. */
    private static function reason(): string
    {
        $error = error_get_last();
        $message = $error === null ? null : $error['message'];

        return \is_string($message) && $message !== '' ? $message : 'the file could not be created';
    }
}
