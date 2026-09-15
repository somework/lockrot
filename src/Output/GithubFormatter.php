<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Lock\LockLineIndex;
use Lockrot\Verdict\Finding;

/**
 * GitHub Actions workflow commands, one annotation per finding, so a `composer lockrot` step
 * annotates composer.lock on the pull request instead of only writing to the job log.
 *
 * Command syntax — `::error file={name},line={line},title={title}::{message}`, and the same shape
 * for `::warning` and `::notice` — is documented at
 * https://docs.github.com/en/actions/writing-workflows/choosing-what-your-workflow-does/workflow-commands-for-github-actions
 * ("Setting an error message" / "Setting a warning message" / "Setting a notice message").
 *
 * That page does not spell out the escaping, which the runner's own toolkit defines instead
 * (actions/toolkit, packages/core/src/command.ts): message values go through `escapeData()`
 * (`%` → `%25`, CR → `%0D`, LF → `%0A`) and property values through `escapeProperty()`, which adds
 * `:` → `%3A` and `,` → `%2C` because both are command-syntax separators.
 *
 * `file=` is always the literal `composer.lock`: annotations are resolved against the checkout
 * root, so an absolute path from the runner's filesystem would not match a file in the diff.
 */
final class GithubFormatter implements FormatterInterface
{
    private FormatContext $context;

    public function __construct(FormatContext $context)
    {
        $this->context = $context;
    }

    public function format(Report $report, bool $showAll = false): string
    {
        $lockPath = $this->context->lockPath();
        $index = $lockPath === null ? LockLineIndex::empty() : LockLineIndex::fromFile($lockPath);

        $lines = [];
        foreach ($showAll ? $report->findings() : $report->flagged() as $finding) {
            $lines[] = $this->annotation($finding, $index->lineOf($finding->package()));
        }
        foreach ($report->notes() as $note) {
            $lines[] = '::notice title=lockrot::'.self::escapeData($note);
        }
        $lines[] = $this->summary($report);

        return implode("\n", $lines)."\n";
    }

    private function annotation(Finding $finding, ?int $line): string
    {
        $properties = ['file=composer.lock'];
        if ($line !== null) {
            $properties[] = 'line='.$line;
        }
        $properties[] = 'title='.self::escapeProperty('lockrot: '.$finding->verdict());

        return \sprintf(
            '::%s %s::%s',
            $this->command($finding->verdict()),
            implode(',', $properties),
            self::escapeData($this->message($finding))
        );
    }

    private function command(string $verdict): string
    {
        $level = $this->context->levelOf($verdict);

        return $level === FormatContext::LEVEL_NOTE ? 'notice' : $level;
    }

    private function message(Finding $finding): string
    {
        $evidence = $finding->evidence();
        if ($finding->allowlistReason() !== null) {
            $evidence = ($evidence === '' ? '' : $evidence.'; ').'allowlisted: '.$finding->allowlistReason();
        }

        $message = $finding->package().' '.$finding->version();
        if ($evidence !== '') {
            $message .= ': '.$evidence;
        }

        $chain = $finding->chain();
        array_pop($chain);
        if ($chain !== []) {
            $message .= ' (via '.implode(' > ', $chain).')';
        }

        return $message;
    }

    /**
     * Deliberately identical to TableFormatter::summary(); GithubFormatterTest pins the two
     * together so neither can drift without a failing test.
     */
    private function summary(Report $report): string
    {
        $parts = [\sprintf('%d packages checked', $report->packagesChecked())];
        foreach ($report->byVerdict() as $verdict => $count) {
            $parts[] = $verdict.' '.$count;
        }

        return implode(' · ', $parts);
    }

    private static function escapeData(string $value): string
    {
        return str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $value);
    }

    private static function escapeProperty(string $value): string
    {
        return str_replace(['%', "\r", "\n", ':', ','], ['%25', '%0D', '%0A', '%3A', '%2C'], $value);
    }
}
