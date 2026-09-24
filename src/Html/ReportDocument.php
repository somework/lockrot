<?php

declare(strict_types=1);

namespace Lockrot\Html;

use Lockrot\Analyzer\Report;
use Lockrot\Data\Repository\RepositoryUrl;
use Lockrot\Explain\Explanation;
use Lockrot\Json\Schemas;
use Lockrot\Output\JsonFormatter;
use Lockrot\Signal\Signal;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use Lockrot\Version;

/**
 * Everything `--format=html` puts inside the page, as one array.
 *
 * The page itself renders; this decides what it has to render from. The two live in different
 * repositories: the renderer (somework/lockrot-report) is tested in browsers against real payloads,
 * while the decisions — which packages get their release branches, what the baseline says about
 * each finding, which repository URL is safe to turn into a link — are made here and asserted in
 * {@see \Lockrot\Tests\Unit\Html\ReportDocumentTest}. This array is the contract between them.
 *
 * The `report` key is exactly what `--format=json` writes under its envelope, so a consumer who
 * pulls the payload out of the page gets a document that validates against the published report
 * schema. `details` is the `--explain` document for the packages worth explaining, minus the parts
 * the report already carries; the page draws the branch timeline out of it.
 */
final class ReportDocument
{
    private Report $report;
    private PageData $page;

    public function __construct(Report $report, ?PageData $page = null)
    {
        $this->report = $report;
        $this->page = $page ?? PageData::none();
    }

    /**
     * @param bool $showAll explain every package, not only the flagged ones. Costs roughly 4 KB a
     *                      package, so the default keeps a 100-package report around 300 KB rather
     *                      than a megabyte
     *
     * @return array<string, mixed>
     */
    public function toArray(bool $showAll = false): array
    {
        return [
            // The document `--format=json` writes, envelope included — compact here where the
            // formatter pretty-prints, and identical once parsed — so `jq .report` out of
            // the page gives a document the published schema describes. What the run was told to
            // do and where each finding stands against the baseline are in there too, so the page
            // reads them from the report rather than keeping a second copy that could disagree.
            'report' => ['$schema' => Schemas::url(Schemas::REPORT, JsonFormatter::SCHEMA), 'lockrot' => ['version' => Version::STRING, 'schema' => JsonFormatter::SCHEMA]] + $this->report->toArray(),
            'details' => $this->details($showAll),
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
     * A repository URL only becomes an `href` when it is one, and never carries the credentials a
     * private source routinely has in it ({@see RepositoryUrl}). The page checks the scheme again
     * before it writes the attribute.
     */
    public static function linkable(?string $url): ?string
    {
        return RepositoryUrl::linkable($url);
    }
}
