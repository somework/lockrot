<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support\Contract;

/**
 * Replaces several recordings only when every one of them is ready: tools/report/record --all-baselined then leaves
 * no tree in which some scenarios hold a new recording and the others an old one.
 */
final class StagedRecording
{
    /**
     * Records each root, checks its floors, writes every recording beside its root and only then moves them in.
     *
     * @param array<string, string>                                   $roots    each recording's directory by its id
     * @param callable(string): array<string, string>                 $record   the files of one id by relative path
     * @param callable(string, array<string, string>): list<string>   $missed   the floors that the files of one id miss
     * @param list<string>                                            $replaced the paths below a root that a recording replaces whole
     *
     * @throws \UnexpectedValueException when an id misses a floor, before anything is written
     */
    public static function replace(array $roots, callable $record, callable $missed, array $replaced): void
    {
        $recorded = [];
        foreach (array_keys($roots) as $id) {
            $files = $record($id);
            $misses = $missed($id, $files);
            if ($misses !== []) {
                throw new \UnexpectedValueException($id.' misses the floors: '.implode(', ', $misses));
            }
            $recorded[$id] = $files;
        }
        $staging = [];
        try {
            foreach ($recorded as $id => $files) {
                $staging[$id] = rtrim($roots[$id], '/').'.staging-'.bin2hex(random_bytes(8));
                foreach ($files as $path => $contents) {
                    self::write($staging[$id].'/'.$path, $contents);
                }
            }
            foreach ($staging as $id => $dir) {
                $root = rtrim($roots[$id], '/');
                $entries = array_diff(scandir($dir) ?: [], ['.', '..']);
                foreach (array_unique(array_merge($replaced, $entries)) as $entry) {
                    self::removeTree($root.'/'.$entry);
                }
                foreach ($entries as $entry) {
                    if (!rename($dir.'/'.$entry, $root.'/'.$entry)) {
                        throw new \RuntimeException('cannot move '.$dir.'/'.$entry);
                    }
                }
            }
        } finally {
            foreach ($staging as $dir) {
                self::removeTree($dir);
            }
        }
    }

    public static function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($path);
    }

    private static function write(string $path, string $contents): void
    {
        if (!is_dir(\dirname($path)) && !mkdir(\dirname($path), 0777, true) && !is_dir(\dirname($path))) {
            throw new \RuntimeException('cannot create '.\dirname($path));
        }
        if (file_put_contents($path, $contents) === false) {
            throw new \RuntimeException('cannot write '.$path);
        }
    }
}
