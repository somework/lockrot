<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Baseline\BaselineComparison;
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
 *
 * The title carries `lockrot: <verdict> (<priority>)` — the same phrase every other format uses
 * where it names a verdict next to a finding. The command (`error`/`warning`/`notice`) is unchanged
 * by the priority: it comes from FormatContext::levelOf(), so the colour of an annotation keeps
 * matching the exit code.
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

        $baseline = $report->baseline();
        $lines = [];
        foreach ($showAll ? $report->findings() : $report->flagged() as $finding) {
            $lines[] = $this->annotation($finding, $index->lineOf($finding->package()), $baseline);
        }
        foreach ($this->notes($report) as $note) {
            $lines[] = '::notice title=lockrot::'.self::escapeData($note);
        }
        // A plain log line, not an annotation: it describes the run, and no line of composer.lock is
        // the place for it. It carries package names, and a name is read from the lock unvalidated,
        // so a line break inside one would start a new line — and a `::` after it would be run as a
        // workflow command. The breaks are folded into spaces; escapeData() is for command payloads
        // and would leave a literal `%25` in a plain line.
        $exposure = $report->exposureSummaryLine();
        if ($exposure !== '') {
            $lines[] = str_replace(["\r\n", "\r", "\n"], ' ', $exposure);
        }
        $lines[] = $report->summaryLine();

        return implode("\n", $lines)."\n";
    }

    /** @return list<string> */
    private function notes(Report $report): array
    {
        $notes = $report->notes();
        $baseline = $report->baseline();
        $stale = $baseline === null ? null : $baseline->staleNote();
        if ($stale !== null) {
            $notes[] = $stale;
        }

        return $notes;
    }

    private function annotation(Finding $finding, ?int $line, ?BaselineComparison $baseline): string
    {
        $properties = ['file=composer.lock'];
        if ($line !== null) {
            $properties[] = 'line='.$line;
        }
        // Both axes in the one field GitHub shows above the annotation body: the verdict says what
        // was observed, the priority how much it applies to this project. The command below stays on
        // the verdict, so the annotation's colour still tracks the exit code.
        $properties[] = 'title='.self::escapeProperty('lockrot: '.$finding->verdict().' ('.$finding->priority().')');

        return \sprintf(
            '::%s %s::%s',
            $this->command($finding, $baseline),
            implode(',', $properties),
            self::escapeData($this->message($finding))
        );
    }

    private function command(Finding $finding, ?BaselineComparison $baseline): string
    {
        $level = $this->context->levelOf($finding, $baseline);

        return $level === FormatContext::LEVEL_NOTE ? 'notice' : $level;
    }

    private function message(Finding $finding): string
    {
        $evidence = $finding->evidenceLine();

        $message = $finding->package().' '.$finding->version();
        if ($evidence !== '') {
            $message .= ': '.$evidence;
        }

        return $message.Via::suffix($finding, ' > ');
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
