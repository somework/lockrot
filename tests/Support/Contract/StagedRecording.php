<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support\Contract;

/**
 * Replaces several recordings only when every one of them is ready, so that no tree holds new recordings beside old
 * ones. The staging and backup directories sit beside each root and start with a dot, so that a `*` glob skips them.
 */
final class StagedRecording
{
    /**
     * Records each root and checks its floors, writes every recording beside its root, then moves the recordings in.
     * When a move fails, the roots that already moved get their old files back.
     *
     * @param array<string, string>                                 $roots    each recording's directory by its id
     * @param callable(string): array<string, string>               $record   the files of one id by relative path
     * @param callable(string, array<string, string>): list<string> $missed   the floors that the files of one id miss
     * @param list<string>                                          $replaced the paths below a root that a recording replaces whole
     *
     * @throws \UnexpectedValueException when an id misses a floor, before anything is written
     * @throws \RuntimeException         when a file cannot be written or moved, after the roots are put back
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
        $aside = [];
        $moved = [];
        try {
            $staging = [];
            foreach ($recorded as $id => $files) {
                $staging[$id] = $aside[] = self::beside($roots[$id], 'staging');
                foreach ($files as $path => $contents) {
                    self::write($staging[$id].'/'.$path, $contents);
                }
            }
            foreach ($staging as $id => $dir) {
                $root = rtrim($roots[$id], '/');
                $backup = $aside[] = self::beside($root, 'old');
                self::makeDirectory($root);
                self::makeDirectory($backup);
                $entries = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
                foreach (array_unique(array_merge($replaced, $entries)) as $entry) {
                    if (file_exists($root.'/'.$entry) || is_link($root.'/'.$entry)) {
                        $moved[] = self::move($root.'/'.$entry, $backup.'/'.$entry);
                    }
                }
                foreach ($entries as $entry) {
                    $moved[] = self::move($dir.'/'.$entry, $root.'/'.$entry);
                }
            }
        } catch (\RuntimeException $failed) {
            $stuck = array_filter(array_reverse($moved), static fn (array $move): bool => !@rename($move[1], $move[0]));
            if ($stuck !== []) {
                // Keep every backup: it holds the only copy of an old file that did not move back.
                $aside = array_values(array_filter($aside, static fn (string $dir): bool => strpos($dir, '.old-') === false));

                throw new \RuntimeException($failed->getMessage().', and these did not move back: '.implode(', ', array_column($stuck, 1)), 0, $failed);
            }

            throw $failed;
        } finally {
            foreach ($aside as $dir) {
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

    /** A new path beside $root whose name starts with a dot. */
    private static function beside(string $root, string $kind): string
    {
        $root = rtrim($root, '/');

        return \dirname($root).'/.'.basename($root).'.'.$kind.'-'.bin2hex(random_bytes(8));
    }

    /** @return array{string, string} the move, from and to */
    private static function move(string $from, string $to): array
    {
        if (!@rename($from, $to)) {
            throw new \RuntimeException('cannot move '.$from.' to '.$to);
        }

        return [$from, $to];
    }

    private static function makeDirectory(string $path): void
    {
        if (!is_dir($path) && !@mkdir($path, 0777, true) && !is_dir($path)) {
            throw new \RuntimeException('cannot create '.$path);
        }
    }

    private static function write(string $path, string $contents): void
    {
        self::makeDirectory(\dirname($path));
        if (@file_put_contents($path, $contents) === false) {
            throw new \RuntimeException('cannot write '.$path);
        }
    }
}
