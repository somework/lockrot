<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Config\Gate;
use Lockrot\Json\JsonWriter;
use Lockrot\Lock\LockLineIndex;
use Lockrot\Verdict\Finding;

/**
 * GitLab Code Quality JSON: docs/ci.md#-formatgitlab. The report format is at
 * https://docs.gitlab.com/ci/testing/code_quality/#code-quality-report-format
 *
 * The `fingerprint` hashes the package and the flag only, never the line, the version, the lock's
 * path or the grade, so an issue keeps its identity when they change (docs/compatibility.md#finding-identity).
 *
 * @internal
 *
 * @phpstan-type Issue array{type: string, check_name: string, description: string, categories: list<string>, severity: string, fingerprint: string, location: array{path: string, lines: array{begin: int}}}
 */
final class GitlabFormatter implements FormatterInterface
{
    /** GitLab does not require `type` or `categories`. Tools that expect CodeClimate read them. */
    private const CATEGORIES = ['Bug Risk'];

    private const SEVERITY = [FormatContext::LEVEL_ERROR => 'major', FormatContext::LEVEL_WARNING => 'minor', FormatContext::LEVEL_NOTE => 'info'];

    /** GitLab requires a line: this one stands in when LockLineIndex finds none. */
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

        $gate = $this->context->gateOf($report);
        $issues = [];
        foreach ($this->context->recordFindings($report, $showAll) as $finding) {
            foreach ($this->issues($finding, $index->lineOf($finding->package()), $report, $gate, $showAll) as $issue) {
                $issues[] = $issue;
            }
        }
        $gap = $this->context->advisoriesNotCheckedLevel($report);
        if ($gap !== null) {
            $issues[] = [
                'type' => 'issue',
                'check_name' => 'lockrot/advisories-unchecked',
                'description' => 'advisories not checked: the gate could not read the advisories of every package',
                'categories' => self::CATEGORIES,
                'severity' => $gap === FormatContext::LEVEL_ERROR ? 'major' : 'minor',
                'fingerprint' => hash('sha256', 'lockrot|advisories-unchecked'),
                'location' => ['path' => $this->context->lockName(), 'lines' => ['begin' => self::FALLBACK_LINE]],
            ];
        }

        return self::encode($issues);
    }

    /**
     * The issues of one finding: one per counted flag, with the fingerprint of the package and the
     * flag (docs/compatibility.md#finding-identity). Under `--all`, a score-0 finding gets the issue
     * of its word, and one with a missing check also gets a `lockrot/unchecked` issue.
     *
     * @return list<Issue>
     */
    private function issues(Finding $finding, ?int $line, Report $report, Gate $gate, bool $showAll): array
    {
        $marks = new SarifRecords($finding, $gate->standingOf($finding->package()), $report->baseline());
        $location = ['path' => $this->context->lockName(), 'lines' => ['begin' => $line ?? self::FALLBACK_LINE]];
        $via = $finding->isDirect() || $finding->directDependents() === [] ? '' : ' (via '.FindingBlock::dependents($finding).')';
        if (!$finding->isGraded()) {
            $head = $finding->package().' '.$finding->version().' — finding '.$finding->verdict().' ('.FindingBlock::scoreLine($finding).')';
            $issues = [];
            if ($showAll) {
                $issues[] = self::issue($finding->verdict(), $head.'.'.$via, self::CATEGORIES, 'info', $finding->package(), $location);
            }
            if ($this->context->isUncheckedRecord($finding, $showAll)) {
                $major = $marks->errorBy(MarkedFact::SIGNAL, null) !== [];
                $issues[] = self::issue('unchecked', $head.' — '.FindingBlock::checksMissing($finding).'.'.$via, self::CATEGORIES, $major ? 'major' : 'info', $finding->package(), $location);
            }

            return $issues;
        }
        $head = $finding->package().' '.$finding->version().' — finding '.$finding->verdict().' ('.FindingBlock::scoreLine($finding).') — ';
        $move = FindingBlock::move($finding);
        $tail = ($move === null ? '' : ' Do: '.$move.'.').$via;
        $issues = [];
        $unchecked = $marks->errorBy(MarkedFact::SIGNAL, null);
        foreach (FindingBlock::evidence($finding) as $flag => $summary) {
            $vulnerable = $flag === 'vulnerable';
            $errorBy = SarifRecords::union($marks->errorBy($vulnerable ? MarkedFact::ADVISORY : MarkedFact::FLAG, $vulnerable ? null : $flag), $issues === [] ? $unchecked : []);
            $level = $vulnerable && $errorBy === [] && $marks->advisoriesKnown() ? FormatContext::LEVEL_NOTE : $marks->level($errorBy, $vulnerable ? null : $marks->flagState($flag));
            $issues[] = self::issue($flag, $head.$flag.': '.$summary.'.'.$tail, $vulnerable ? ['Security'] : self::CATEGORIES, self::SEVERITY[$level], $finding->package(), $location);
        }

        return $issues;
    }

    /**
     * @param list<string>                                    $categories
     * @param array{path: string, lines: array{begin: int}} $location
     *
     * @return Issue
     */
    private static function issue(string $check, string $description, array $categories, string $severity, string $package, array $location): array
    {
        return [
            'type' => 'issue',
            'check_name' => 'lockrot/'.$check,
            'description' => $description,
            'categories' => $categories,
            'severity' => $severity,
            'fingerprint' => hash('sha256', 'lockrot|'.$package.'|'.$check),
            'location' => $location,
        ];
    }

    /** @param list<Issue> $issues */
    private static function encode(array $issues): string
    {
        $json = JsonWriter::encode($issues, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        // Report data always encodes. Without this guard, a failure prints an empty document.
        if ($json === null) {
            throw new \RuntimeException('Cannot encode report as GitLab Code Quality JSON: '.json_last_error_msg());
        }

        return $json."\n";
    }
}
