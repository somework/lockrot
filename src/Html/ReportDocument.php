<?php

declare(strict_types=1);

namespace Lockrot\Html;

use Lockrot\Analyzer\Report;
use Lockrot\Explain\Explanation;
use Lockrot\Json\Schemas;
use Lockrot\Output\FormatContext;
use Lockrot\Output\JsonFormatter;
use Lockrot\Signal\Signal;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use Lockrot\Version;

/**
 * Everything `--format=html` puts inside the page, as one array.
 *
 * The page itself renders; this decides what it has to render from. Keeping the two apart is what
 * makes the format testable at all: the JavaScript in resources/report/ is exercised by hand in a
 * browser, while the decisions — which packages get their release branches, what the baseline says
 * about each finding, which repository URL is safe to turn into a link — are made here and asserted
 * in {@see \Lockrot\Tests\Unit\Html\ReportDocumentTest}.
 *
 * The `report` key is exactly what `--format=json` writes under its envelope, so a consumer who
 * pulls the payload out of the page gets a document that validates against the published report
 * schema. `details` is the `--explain` document for the packages worth explaining, minus the parts
 * the report already carries; the page draws the branch timeline out of it.
 */
final class ReportDocument
{
    private Report $report;
    private FormatContext $context;
    private PageData $page;

    public function __construct(Report $report, FormatContext $context, ?PageData $page = null)
    {
        $this->report = $report;
        $this->context = $context;
        $this->page = $page ?? PageData::none();
    }

    /**
     * @param bool $showAll explain every package, not only the flagged ones. Costs roughly 4 KB a
     *                      package, so the default keeps a 100-package report around 250 KB rather
     *                      than a megabyte
     *
     * @return array<string, mixed>
     */
    public function toArray(bool $showAll = false): array
    {
        return [
            'context' => $this->context(),
            // Byte for byte what `--format=json` writes, envelope included, so `jq .report` out of
            // the page gives a document the published schema describes.
            'report' => ['$schema' => Schemas::url(Schemas::REPORT, JsonFormatter::SCHEMA), 'lockrot' => ['version' => Version::STRING, 'schema' => JsonFormatter::SCHEMA]] + $this->report->toArray(),
            'details' => $this->details($showAll),
            'baseline' => $this->baselineStates(),
        ];
    }

    /**
     * What the run was told to do, which is not in the report itself.
     *
     * @return array<string, mixed>
     */
    private function context(): array
    {
        $thresholds = $this->page->thresholds();

        return [
            'target_php' => $this->page->targetPhp(),
            'lock_path' => $this->context->lockPath(),
            'fail_on' => $this->context->failOn(),
            'thresholds' => $thresholds === null ? null : [
                'release-warn-years' => $thresholds->releaseWarnYears(),
                'release-high-years' => $thresholds->releaseHighYears(),
                'push-warn-years' => $thresholds->pushWarnYears(),
                'push-high-years' => $thresholds->pushHighYears(),
            ],
        ];
    }

    /**
     * The `--explain` document per package, keyed by name, minus `finding` and `notes` — the report
     * already carries both, and repeating them would double the page for nothing.
     *
     * @return array<string, array<string, mixed>>
     */
    private function details(bool $showAll): array
    {
        $analysis = $this->page->analysis();
        $thresholds = $this->page->thresholds();
        $targetPhp = $this->page->targetPhp();
        if ($analysis === null || $thresholds === null || $targetPhp === null) {
            return [];
        }

        $details = [];
        foreach ($this->report->findings() as $finding) {
            if (!$showAll && !self::worthExplaining($finding)) {
                continue;
            }
            $facts = $analysis->facts($finding->package());
            if ($facts === null) {
                continue;
            }
            $explained = (new Explanation($finding, $facts, $thresholds, $targetPhp, $this->report))->toArray();
            $metadata = $explained['metadata'] ?? null;
            $details[$finding->package()] = [
                'metadata' => \is_array($metadata) ? $metadata : null,
                'lock' => $explained['lock'] ?? null,
                'activity' => $explained['activity'] ?? null,
                'repository_link' => self::linkable(self::repositoryOf($metadata, $explained['lock'] ?? null)),
            ];
        }

        return $details;
    }

    /**
     * A flagged package, or an unflagged one carrying an advisory. The second case is the one the
     * terminal cannot show: a package on a branch that still gets security releases has no rot
     * verdict at all, so it is `ok`, and its advisories would otherwise be absent from a report
     * that did not pass `--all`.
     */
    private static function worthExplaining(Finding $finding): bool
    {
        if (Verdict::flagged($finding->verdict())) {
            return true;
        }
        foreach ($finding->signals() as $signal) {
            if ($signal->id() === Signal::S9) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the baseline knew about each finding: `new`, `known` or `worsened`, with the verdict it
     * recorded. `BaselineComparison` answers per package; the report's own JSON carries only the
     * totals, which is why this is assembled here rather than read back out of `report`.
     *
     * @return array<string, array{status: string, previous_verdict: ?string}>
     */
    private function baselineStates(): array
    {
        $baseline = $this->page->baseline();
        if ($baseline === null) {
            return [];
        }

        $states = [];
        foreach ($this->report->findings() as $finding) {
            $status = $baseline->statusOf($finding->package());
            if ($status === null) {
                continue;
            }
            $states[$finding->package()] = [
                'status' => $status,
                'previous_verdict' => $baseline->previousVerdictOf($finding->package()),
            ];
        }

        return $states;
    }

    /**
     * @param mixed $metadata
     * @param mixed $lock
     */
    private static function repositoryOf($metadata, $lock): ?string
    {
        foreach ([$metadata, $lock] as $source) {
            if (\is_array($source) && \is_string($source['repository'] ?? null) && $source['repository'] !== '') {
                return $source['repository'];
            }
        }

        return null;
    }

    /**
     * A repository URL only becomes an `href` when it is one. The value comes from the package's own
     * `source.url` or `support.source`, which is whatever its author wrote there, and the page it
     * lands in is opened in a browser — so a `javascript:` or `data:` URL is dropped rather than
     * rendered, and an `scp`-style `git@host:vendor/name.git` is rewritten to the https form it
     * means. The page checks the scheme again before it writes the attribute.
     */
    public static function linkable(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $url = preg_replace('{^git\+}', '', trim($url)) ?? '';
        $url = preg_replace('{\.git$}', '', $url) ?? '';
        if (preg_match('{^[A-Za-z0-9._~-]+@([A-Za-z0-9.-]+):(?!//)(.+)$}', $url, $m) === 1) {
            $url = 'https://'.$m[1].'/'.$m[2];
        }

        return preg_match('{^https?://[^\s<>"\']+$}i', $url) === 1 ? $url : null;
    }
}
