<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

/**
 * Every Markdown page that the site builds from a docs tree, nested pages included. `overrides/`
 * holds theme templates, not pages.
 */
final class DocsPages
{
    /** @return array<string, string> the path relative to $root, with `/` => the absolute path, sorted by the relative path */
    public static function in(string $root): array
    {
        $root = rtrim($root, '/').'/';
        $pages = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            $relative = str_replace('\\', '/', substr($file->getPathname(), \strlen($root)));
            if ($file->getExtension() !== 'md' || strpos($relative, 'overrides/') === 0) {
                continue;
            }
            $pages[$relative] = $file->getPathname();
        }
        ksort($pages, \SORT_STRING);

        return $pages;
    }
}
