<?php

declare(strict_types=1);

namespace Lockrot\Filesystem;

/**
 * The little lockrot needs to know about a path it is handed: whether it is absolute, where a
 * relative one lands, and a form two spellings of the same file can be compared in.
 *
 * @internal
 */
final class Path
{
    /**
     * Unix absolute paths start with a separator; Windows ones with a drive letter (`C:\project`)
     * or a UNC prefix (`\\server\share`), both of which the leading-separator test already covers.
     */
    public static function isAbsolute(string $path): bool
    {
        return strpos($path, '/') === 0
            || strpos($path, '\\') === 0
            || preg_match('{^[A-Za-z]:[\\\\/]}', $path) === 1;
    }

    /** $path as given when it is absolute, otherwise joined onto $base. */
    public static function resolve(string $base, string $path): string
    {
        return self::isAbsolute($path) ? $path : rtrim($base, '/\\').'/'.$path;
    }

    /**
     * A key under which two spellings of one file compare equal: the directory resolved through the
     * filesystem (dot segments, symlinked directories such as macOS's temp directory) and lower
     * case — so `Composer.lock` on a case-insensitive filesystem, which is the lock, is caught. On a
     * case-sensitive one that makes two distinct names compare equal, which only ever errs on the
     * side of refusing.
     *
     * A directory that does not exist cannot be resolved, so the path is then compared as it is
     * spelled. The two forms never meet over one file: its directory either exists or it does not.
     * The file itself is not resolved: a symlink at the target is replaced by the write, not
     * followed, so the link is the file that is written.
     */
    public static function canonical(string $absolute): string
    {
        $directory = realpath(\dirname($absolute));

        return strtolower($directory === false ? $absolute : $directory.'/'.basename($absolute));
    }
}
