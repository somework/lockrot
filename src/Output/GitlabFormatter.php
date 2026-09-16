<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Lock\LockLineIndex;
use Lockrot\Verdict\Finding;

/**
 * GitLab Code Quality JSON, one object per flagged finding (every finding with `--all`), for a job
 * that publishes `artifacts.reports.codequality` so findings show up as inline diff annotations on
 * a merge request.
 *
 * Shape per https://docs.gitlab.com/ci/testing/code_quality/#implement-a-custom-tool ("Implement a
 * custom tool"): each issue needs `description`, `check_name`, `fingerprint`, `severity` (one of
 * `info`, `minor`, `major`, `critical`, `blocker`) and a location with either `lines.begin` or
 * `positions.begin.line`; `type` and `categories` are accepted but not required by GitLab's own
 * parser — kept here for compatibility with tools that still expect the CodeClimate shape GitLab's
 * format is descended from.
 *
 * Severity reuses FormatContext::levelOf() so an issue's colour tracks the same fail-on threshold
 * the exit code does: `error` -> `major`, `warning` -> `minor`, `note` (a baselined-known finding,
 * or an unflagged row only visible under `--all`) -> `info`.
 *
 * Report::notes() has no field to carry a document-level note in this shape, so notes are dropped
 * here; use `--format=json` for them. `--strict-network` still drives the exit code independently
 * of the chosen format.
 *
 * The fingerprint is `sha256("lockrot|<package>|<verdict>")`: stable across machines and runs, and
 * deliberately excludes the line number and version, so a version bump that keeps the same verdict
 * — or a reformatted composer.lock that moves the entry to a different line — keeps the same GitLab
 * issue identity instead of appearing as a new one. The priority is deliberately not part of it:
 * a package that moves from a `require` to a `require-dev` would otherwise open a second issue for
 * a finding GitLab already tracks.
 */
final class GitlabFormatter implements FormatterInterface
{
    private const CATEGORIES = ['Bug Risk'];

    /** The line GitLab is told about when LockLineIndex could not find one; GitLab requires a line. */
    private const FALLBACK_LINE = 1;

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
        $issues = [];
        foreach ($showAll ? $report->findings() : $report->flagged() as $finding) {
            $issues[] = $this->issue($finding, $index->lineOf($finding->package()), $baseline);
        }

        return self::encode($issues);
    }

    /** @return array<string, mixed> */
    private function issue(Finding $finding, ?int $line, ?BaselineComparison $baseline): array
    {
        return [
            'type' => 'issue',
            'check_name' => 'lockrot/'.$finding->verdict(),
            'description' => $this->description($finding),
            'categories' => self::CATEGORIES,
            'severity' => $this->severity($finding, $baseline),
            'fingerprint' => hash('sha256', 'lockrot|'.$finding->package().'|'.$finding->verdict()),
            'location' => [
                'path' => 'composer.lock',
                'lines' => ['begin' => $line ?? self::FALLBACK_LINE],
            ],
        ];
    }

    private function severity(Finding $finding, ?BaselineComparison $baseline): string
    {
        switch ($this->context->levelOf($finding, $baseline)) {
            case FormatContext::LEVEL_ERROR:
                return 'major';
            case FormatContext::LEVEL_WARNING:
                return 'minor';
            default:
                return 'info';
        }
    }

    private function description(Finding $finding): string
    {
        $evidence = $finding->evidenceLine();

        // Code Quality has no title field of its own, so the verdict and priority ride in the
        // description — the same `<verdict> (<priority>)` phrase the GitHub annotation title uses.
        $message = $finding->package().' '.$finding->version()
            .' — '.$finding->verdict().' ('.$finding->priority().')';
        if ($evidence !== '') {
            $message .= ': '.$evidence;
        }

        return $message.Via::suffix($finding, ' > ');
    }

    /** @param list<array<string, mixed>> $issues */
    private static function encode(array $issues): string
    {
        $json = json_encode($issues, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        // Every value above is a scalar, list or string-keyed array built from Report data, so this
        // is unreachable in practice; guarded explicitly so a future encoding failure fails loudly
        // instead of silently emitting the string "false" (same guard as JsonFormatter).
        if ($json === false) {
            throw new \RuntimeException('Cannot encode report as GitLab Code Quality JSON: '.json_last_error_msg());
        }

        return $json."\n";
    }
}
