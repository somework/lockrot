<?php

declare(strict_types=1);

namespace Lockrot\Filesystem;

use Lockrot\Exception\ConfigException;

/**
 * Every file lockrot writes in a project — the baseline and the `--output` reports — goes through
 * here: the contents go to a sibling `.tmp` file first, which is then renamed over the target, so a
 * run interrupted mid-write never leaves a truncated file behind. A truncated baseline would read as
 * "these findings were never accepted" on the next CI run; a truncated SARIF file as a broken upload.
 * An interrupted run can leave that `*.tmp` file beside the target instead; it is inert.
 *
 * The temporary file is created exclusively (`fopen` mode `x`, O_CREAT|O_EXCL): a file or symlink
 * already at its name fails the write instead of being followed. A file that is replaced keeps its
 * permission bits — a report kept at 0600 stays 0600 — and a new one gets the umask's default like
 * any other; owner, group and ACLs are not carried over.
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
        $handle = @fopen($temporary, 'xb');
        if ($handle === false) {
            // Nothing of ours to clean up: whatever sits at the name, if anything, is not this run's.
            throw new ConfigException('Cannot write '.$displayPath.': '.self::reason());
        }
        $written = @fwrite($handle, $contents);
        $closed = @fclose($handle);
        if ($written !== \strlen($contents) || !$closed || !self::keepPermissions($path, $temporary) || !@rename($temporary, $path)) {
            // Read before the cleanup: unlink() can record a failure of its own, which would
            // otherwise replace the reason the caller needs.
            $reason = self::reason();
            @unlink($temporary);

            throw new ConfigException('Cannot write '.$displayPath.': '.$reason);
        }
    }

    /** Gives $temporary the permission bits of the file it replaces, when there is one. */
    private static function keepPermissions(string $path, string $temporary): bool
    {
        $mode = @fileperms($path);

        return $mode === false || @chmod($temporary, $mode & 0777);
    }

    /** The last filesystem failure PHP recorded, or a generic reason when it recorded none. */
    private static function reason(): string
    {
        $error = error_get_last();
        $message = $error === null ? null : $error['message'];

        return \is_string($message) && $message !== '' ? $message : 'the file could not be created';
    }
}
