<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Exception\ConfigException;
use Lockrot\Filesystem\AtomicWriter;
use Lockrot\Filesystem\Path;
use Lockrot\Html\PageData;

/**
 * The files one run writes its report to: docs/configuration.md#writing-reports-to-files.
 *
 * {@see resolve()} refuses a spec that writes anything but a report, before the analysis
 * starts. Two names that do not exist yet can become one file once the first is written, so
 * {@see write()} checks again before each file. resolve() does not check writability, because
 * is_writable() is unreliable under ACLs and root. An unwritable target fails at the write.
 *
 * @internal
 */
final class ReportTargets
{
    private const COMPOSER_FILES = ['composer.json', 'composer.lock'];
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
     * @param list<array{0: string, 1: string}> $protected absolute paths that no report can be written to,
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
     * Writes every file in the order given and calls $onWritten after each one. Each file is what
     * `--format=<its format>` prints, except that console markup renders the way a redirected
     * stdout renders it ({@see ConsoleMarkup::render()}).
     *
     * @param callable(ReportTarget): void $onWritten
     *
     * @throws ConfigException at the first file that cannot be written, or that is on disk a file
     *                         this run has already written, and the files before it stay
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
