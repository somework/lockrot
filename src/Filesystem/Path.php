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
     * Whether the last component is a name Windows reads as another: Win32 drops trailing dots and
     * spaces (`composer.lock.` is `composer.lock`), and a colon names an NTFS stream of the file
     * before it (`composer.lock::$DATA` is the file itself). Only the last component is looked at,
     * split on either separator, so a drive letter (`C:\`) never counts, and the answer is the same
     * on every system.
     */
    public static function isWindowsAlias(string $path): bool
    {
        $name = (string) preg_replace('{^.*[\\\\/]}s', '', $path);

        return strpos($name, ':') !== false || preg_match('{[. ]$}', $name) === 1;
    }

    /**
     * A key under which two spellings of one file compare equal: the directory resolved through the
     * filesystem (dot segments, symlinked directories such as macOS's temp directory), dot segments
     * folded, either separator read as `/`, and lower case — so `Composer.lock` on a case-insensitive
     * filesystem, which is the lock, is caught. On a case-sensitive one that makes two distinct names
     * compare equal, and on Unix a backslash is a character of the name rather than a separator;
     * both only ever err on the side of refusing.
     *
     * A directory that does not exist cannot be resolved as spelled. Windows does not need it to:
     * it folds `..` by spelling alone, so `missing\..\lockrot-baseline.json` is the baseline there.
     * The path is then folded the same way ({@see normalize()}) and resolved again, and compared as
     * it is spelled only when even that directory does not exist.
     *
     * The file itself is not resolved: a symlink at the target is replaced by the write, not
     * followed, so the link is the file that is written. {@see sameFile()} is the question about
     * what is on disk.
     */
    public static function canonical(string $absolute): string
    {
        $directory = realpath(\dirname($absolute));
        if ($directory === false) {
            $absolute = self::normalize($absolute);
            $directory = realpath(\dirname($absolute));
        }

        return strtolower(self::normalize($directory === false ? $absolute : rtrim($directory, '/\\').'/'.basename($absolute)));
    }

    /**
     * $path with `.` and `..` segments folded by spelling alone, the way Windows folds them before it
     * looks at the disk: either separator is one, `/` is written back, repeated separators are one,
     * and nothing climbs above a root (`/`, a drive such as `C:/`, a UNC `//`). A relative path keeps
     * the leading `..` it cannot fold.
     *
     * The disk is not consulted, so on Unix a `..` after a symlinked directory is folded lexically
     * here where the kernel would follow the link; {@see canonical()} resolves what exists first.
     */
    public static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        // Always matches, if only the empty string: a relative path has no root.
        $root = preg_match('{^(?:[A-Za-z]:)?/{0,2}}', $path, $match) === 1 ? $match[0] : '';
        $segments = [];
        foreach (explode('/', substr($path, \strlen($root))) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment !== '..') {
                $segments[] = $segment;
            } elseif ($segments !== [] && end($segments) !== '..') {
                array_pop($segments);
            } elseif ($root === '') {
                $segments[] = $segment;
            }
        }

        return $root.implode('/', $segments);
    }

    /**
     * Whether two paths name one file on disk, links followed: the same device and inode — a hard
     * link, a symlink, and on a case-insensitive filesystem any spelling it folds to the same entry
     * (`composer.locK` with a Kelvin sign is the lock on macOS). Where the filesystem reports no inode
     * (0, as on some Windows filesystems) the resolved paths are compared instead, case-insensitively
     * as Windows compares them. A path that does not exist is the same file as nothing.
     */
    public static function sameFile(string $first, string $second): bool
    {
        $a = @stat($first);
        $b = @stat($second);
        if ($a === false || $b === false) {
            return false;
        }
        if ($a['ino'] === 0 || $b['ino'] === 0) {
            return strtolower((string) realpath($first)) === strtolower((string) realpath($second));
        }

        return $a['dev'] === $b['dev'] && $a['ino'] === $b['ino'];
    }
}
