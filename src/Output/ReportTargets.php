<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Exception\ConfigException;
use Lockrot\Filesystem\AtomicWriter;
use Lockrot\Filesystem\Path;
use Lockrot\Html\PageData;

/**
 * The files one run writes its report to: every `--output=<format>:<path>`, checked before the
 * analysis starts and written after it, from the same report stdout gets.
 *
 * The checks are the whole promise of `--output` — lockrot writes the files it is told to and
 * nothing else — so a spec that would write anything else is refused up front, with exit 2, before
 * a single repository is asked anything:
 *
 * - a file name Windows reads as another — one ending in a dot or a space, or holding a colon
 *   (`composer.lock.` and `composer.lock::$DATA` are the lock) — on every system, so the rule is one;
 * - a file named `composer.json` or `composer.lock`, in any directory and any letter case, with dot
 *   segments folded by spelling as Windows folds them (`missing\..\composer.lock` is the lock there);
 * - a file the caller protects — the baseline, the manifest Composer reads and its lock — spelled any
 *   way that folds to it, as above;
 * - an existing file that is on disk one of those, or the composer.json or composer.lock beside it:
 *   a symlink, a hard link, a Windows 8.3 short name, or a spelling the filesystem folds by Unicode
 *   rules (`composer.locK` with a Kelvin sign is the lock on macOS), compared by device and inode;
 * - the same file named twice, by spelling or, for files that exist, on disk;
 * - a file whose directory does not exist, or is not a directory: lockrot creates none;
 * - a path that already exists and is not a regular file (a directory, a device such as
 *   /dev/stdout, a pipe): the write is a rename over the path, and stdout is what --format is for.
 *
 * Two names that do not exist yet can still be one file once the first is written (on APFS, a name
 * spelled precomposed and decomposed), so {@see write()} asks again before each file and stops with
 * exit 2 rather than write one report over another.
 *
 * Writability is not checked in advance: is_writable() is unreliable under ACLs and root, so an
 * unwritable target fails at the write instead, with PHP's reason, as exit 2 all the same.
 *
 * @internal
 */
final class ReportTargets
{
    private const COMPOSER_FILES = ['composer.json', 'composer.lock'];
    /** The reason given for every composer.json and composer.lock, whichever rule caught it. */
    public const COMPOSER_REASON = 'lockrot never writes composer.json or composer.lock';

    /** @var list<ReportTarget> */
    private array $targets;

    /** @param list<ReportTarget> $targets */
    private function __construct(array $targets)
    {
        $this->targets = $targets;
    }

    /**
     * @param list<string>                      $specs     every `--output` value, in the order given
     * @param string                            $cwd       the directory relative paths are relative to
     * @param list<array{0: string, 1: string}> $protected absolute paths no report may be written to,
     *                                                     each with the reason given when one is named
     *
     * @throws ConfigException for the first spec that breaks a rule, after every spec has parsed
     */
    public static function resolve(array $specs, string $cwd, array $protected): self
    {
        $targets = [];
        foreach ($specs as $spec) {
            $targets[] = ReportTarget::parse($spec, $cwd);
        }
        $seen = [];
        foreach ($targets as $index => $target) {
            $canonical = Path::canonical($target->path());
            self::refuseProtected($target, $canonical, $protected);
            if (isset($seen[$canonical])) {
                throw new ConfigException($target->option().': the same file as '.$seen[$canonical]);
            }
            self::refuseSameFileAs($target, \array_slice($targets, 0, $index));
            $seen[$canonical] = $target->option();
            self::refuseUnwritablePlace($target);
        }

        return new self($targets);
    }

    /** @param list<array{0: string, 1: string}> $protected */
    private static function refuseProtected(ReportTarget $target, string $canonical, array $protected): void
    {
        if (Path::isWindowsAlias($target->path())) {
            throw new ConfigException($target->option().': a file name that ends in a dot or a space, or holds a colon, is another name on Windows');
        }
        // The name as Windows folds it, and the file itself when it exists: a symlink, or a Windows
        // 8.3 short name, to a composer file.
        $real = realpath($target->path());
        foreach ([Path::normalize($target->path()), (string) $real] as $name) {
            if (\in_array(strtolower(basename($name)), self::COMPOSER_FILES, true)) {
                throw new ConfigException($target->option().': '.self::COMPOSER_REASON);
            }
        }
        foreach ($protected as [$path, $reason]) {
            if (Path::canonical($path) === $canonical || Path::sameFile($target->path(), $path)) {
                throw new ConfigException($target->option().': '.$reason);
            }
        }
        foreach (self::COMPOSER_FILES as $name) {
            if (Path::sameFile($target->path(), \dirname($target->path()).'/'.$name)) {
                throw new ConfigException($target->option().': '.self::COMPOSER_REASON);
            }
        }
    }

    /**
     * @param list<ReportTarget> $earlier
     *
     * @throws ConfigException when $target is, on disk, one of the $earlier files
     */
    private static function refuseSameFileAs(ReportTarget $target, array $earlier): void
    {
        foreach ($earlier as $other) {
            if (Path::sameFile($target->path(), $other->path())) {
                throw new ConfigException($target->option().': the same file as '.$other->option());
            }
        }
    }

    private static function refuseUnwritablePlace(ReportTarget $target): void
    {
        $directory = \dirname($target->path());
        if (!is_dir($directory)) {
            $shown = \dirname($target->displayPath());
            throw new ConfigException(file_exists($directory)
                ? $target->option().': '.$shown.' is not a directory'
                : $target->option().': directory '.$shown.' does not exist; lockrot does not create directories');
        }
        if (file_exists($target->path()) && !is_file($target->path())) {
            throw new ConfigException($target->option().': '.$target->displayPath().' exists and is not a regular file');
        }
    }

    /** Whether a file asks for $format — `html` needs facts the other formats do without. */
    public function wants(string $format): bool
    {
        foreach ($this->targets as $target) {
            if ($target->format() === $format) {
                return true;
            }
        }

        return false;
    }

    /**
     * Renders and writes every file, in the order given, calling $onWritten after each one.
     *
     * Each file is what `--format=<its format>` prints for the same report and context. The format
     * that carries console markup ({@see Formatters::carriesConsoleMarkup()}) is rendered the way a
     * redirected stdout renders it ({@see ConsoleMarkup::render()}): no colours, no tags, the brackets back
     * as the evidence wrote them.
     *
     * @param callable(ReportTarget): void $onWritten
     *
     * @throws ConfigException at the first file that cannot be written, or that is on disk a file
     *                         this run has already written; the files before it stay
     */
    public function write(Report $report, FormatContext $context, ?PageData $page, bool $showAll, callable $onWritten): void
    {
        foreach ($this->targets as $index => $target) {
            self::refuseSameFileAs($target, \array_slice($this->targets, 0, $index));
            $contents = Formatters::for($target->format(), $context, $page)->format($report, $showAll);
            if (Formatters::carriesConsoleMarkup($target->format())) {
                $contents = ConsoleMarkup::render($contents, false);
            }
            AtomicWriter::write($target->path(), $contents, $target->displayPath());
            $onWritten($target);
        }
    }
}
