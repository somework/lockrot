<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Config\Gate;
use Lockrot\Lock\LockLineIndex;
use Lockrot\Verdict\Finding;

/**
 * GitHub Actions workflow commands: docs/ci.md#-formatgithub. The syntax is documented at
 * https://docs.github.com/en/actions/writing-workflows/choosing-what-your-workflow-does/workflow-commands-for-github-actions
 * but not the escaping, which the runner's toolkit defines:
 * https://github.com/actions/toolkit/blob/main/packages/core/src/command.ts
 *
 * @internal
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
        $gate = $this->context->gateOf($report);
        $lines = [];
        foreach ($this->context->recordFindings($report, $showAll) as $finding) {
            $lines[] = $this->annotation($finding, $index->lineOf($finding->package()), $baseline, $gate);
        }
        $gap = $this->context->advisoriesNotCheckedLevel($report);
        if ($gap !== null) {
            $lines[] = '::'.$gap.' title=lockrot::'.self::escapeData('advisories not checked: the gate could not read the advisories of every package');
        }
        foreach ($this->notes($report) as $note) {
            $lines[] = '::notice title=lockrot::'.self::escapeData($note);
        }
        // A plain log line, not an annotation. A package name comes unvalidated from the lock, so
        // fold line breaks into spaces: a `::` after one runs as a workflow command. escapeData()
        // leaves a literal `%25` in a plain line.
        $exposure = $report->exposureSummaryLine();
        if ($exposure !== '') {
            $lines[] = str_replace(["\r\n", "\r", "\n"], ' ', $exposure);
        }
        $unflagged = $report->unflaggedAdvisoriesLine();
        if ($unflagged !== '') {
            $lines[] = $unflagged;
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

    private function annotation(Finding $finding, ?int $line, ?BaselineComparison $baseline, Gate $gate): string
    {
        $properties = ['file='.self::escapeProperty($this->context->lockName())];
        if ($line !== null) {
            $properties[] = 'line='.$line;
        }
        $properties[] = 'title='.self::escapeProperty('lockrot: '.$finding->verdict().' ('.$finding->priority().')');

        return \sprintf(
            '::%s %s::%s',
            $this->command($finding, $baseline, $gate),
            implode(',', $properties),
            self::escapeData($this->message($finding))
        );
    }

    private function command(Finding $finding, ?BaselineComparison $baseline, Gate $gate): string
    {
        $level = $this->context->levelOf($finding, $gate->standingOf($finding->package()), $baseline);

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
