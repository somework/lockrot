<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Json\JsonWriter;
use Lockrot\Lock\LockLineIndex;
use Lockrot\Verdict\Finding;

/**
 * GitLab Code Quality JSON: docs/ci.md#-formatgitlab. The report format is at
 * https://docs.gitlab.com/ci/testing/code_quality/#code-quality-report-format
 *
 * The `fingerprint` must not hold the line, the version, the lock's path or the priority, so the
 * GitLab issue keeps its identity when they change (docs/compatibility.md#finding-identity).
 *
 * @internal
 */
final class GitlabFormatter implements FormatterInterface
{
    /** GitLab does not require `type` or `categories`. Tools that expect CodeClimate read them. */
    private const CATEGORIES = ['Bug Risk'];

    /** GitLab requires a line, and LockLineIndex can find none. */
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
                'path' => $this->context->lockName(),
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

        // Code Quality has no title field, so the verdict and the priority go in the description.
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
        $json = JsonWriter::encode($issues, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        // Report data always encodes. This guard keeps a failure from becoming the string "false".
        if ($json === null) {
            throw new \RuntimeException('Cannot encode report as GitLab Code Quality JSON: '.json_last_error_msg());
        }

        return $json."\n";
    }
}
