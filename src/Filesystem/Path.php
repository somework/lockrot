<?php

declare(strict_types=1);

namespace Lockrot\Filesystem;

/** @internal */
final class Path
{
    /**
     * A Windows form is absolute on every system: a drive letter (`C:\project`) or a UNC prefix
     * (`\\server\share`).
     */
    public static function isAbsolute(string $path): bool
    {
        return strpos($path, '/') === 0
            || strpos($path, '\\') === 0
            || preg_match('{^[A-Za-z]:[\\\\/]}', $path) === 1;
    }

    public static function resolve(string $base, string $path): string
    {
        return self::isAbsolute($path) ? $path : rtrim($base, '/\\').'/'.$path;
    }

    /**
     * Only the spelling is compared ({@see normalize()}): two spellings of one directory through a
     * symlink read as unrelated, so a caller gets the file name alone, not a wrong path.
     */
    public static function relativeTo(string $path, string $directory): ?string
    {
        $root = rtrim(self::normalize($directory), '/').'/';
        $path = self::normalize($path);

        return strpos($path, $root) === 0 ? (string) substr($path, \strlen($root)) : null;
    }

    /** The last component, split on either separator, so a Windows path has the same name on every system. */
    public static function name(string $path): string
    {
        return (string) preg_replace('{^.*[\\\\/]}s', '', $path);
    }

    /**
     * Win32 drops trailing dots and spaces (`composer.lock.` is `composer.lock`), and a colon names an
     * NTFS stream (`composer.lock::$DATA` is the file itself). Only the last component counts, so a
     * drive letter (`C:\`) never matches. The answer is the same on every system.
     */
    public static function isWindowsAlias(string $path): bool
    {
        $name = self::name($path);

        return strpos($name, ':') !== false || preg_match('{[. ]$}', $name) === 1;
    }

    /**
     * A key under which two spellings of one file compare equal: the directory resolved through the
     * filesystem, dot segments folded, either separator read as `/`, and lower case. Distinct names can
     * compare equal, on a case-sensitive filesystem or with a Unix backslash. That only errs on the
     * side of refusing. A missing directory is folded by spelling first, as Windows does
     * (`missing\..\lockrot-baseline.json` is the baseline there). The file itself is not resolved:
     * the write replaces a symlink at the target.
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
     * Folds `.` and `..` by spelling alone, the way Windows does before it looks at the disk. Nothing
     * climbs above a root (`/`, a drive such as `C:/`, a UNC `//`). A relative path keeps the leading
     * `..` it cannot fold.
     *
     * The disk is not consulted: on Unix a `..` after a symlinked directory is folded where the kernel
     * follows the link. {@see canonical()} resolves what exists first.
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
     * Same device and inode, so a hard link, a symlink and a case-folded spelling count as one file
     * (`composer.locK` with a Kelvin sign is the lock on macOS). Where the filesystem reports inode 0,
     * as on some Windows filesystems, the resolved paths are compared case-insensitively. A missing
     * path is the same file as nothing.
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
