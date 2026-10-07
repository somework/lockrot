<?php

declare(strict_types=1);

namespace Lockrot\Html;

use Lockrot\Analyzer\Report;
use Lockrot\Data\Repository\RepositoryUrl;
use Lockrot\Explain\Explanation;
use Lockrot\Json\Schemas;
use Lockrot\Output\JsonFormatter;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\Signal;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use Lockrot\Version;

/**
 * This array is the contract with the renderer in https://github.com/somework/lockrot-report, which
 * lives in another repository. The decisions are made here and asserted in
 * {@see \Lockrot\Tests\Unit\Html\ReportDocumentTest}. The `report` key validates against the
 * published report schema. `details` is the `--explain` document of the packages worth explaining.
 *
 * @internal
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
     * @param bool $showAll explain every package, not only the flagged ones: the page grows with the lock
     *
     * @return array<string, mixed>
     */
    public function toArray(bool $showAll = false): array
    {
        return [
            // The `--format=json` document, envelope included, so `jq .report` out of the page gives a
            // document the published schema describes. The page reads the run's settings and baseline
            // standing from it: a second copy could disagree.
            'report' => ['$schema' => Schemas::url(Schemas::REPORT, JsonFormatter::SCHEMA), 'lockrot' => ['version' => Version::STRING, 'schema' => JsonFormatter::SCHEMA]] + $this->report->toArray(),
            'details' => $this->details($showAll),
        ];
    }


    /**
     * The `--explain` document per package, minus `finding`, `notes` and `note_details`: the report
     * already carries them.
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
            $explained = (new Explanation($finding, $facts, $thresholds, $targetPhp, $this->report, $this->page->projectPhp()))->toArray();
            $metadata = $explained['metadata'] ?? null;
            $details[$finding->package()] = [
                'metadata' => \is_array($metadata) ? $metadata : null,
                'lock' => $explained['lock'] ?? null,
                'activity' => $explained['activity'] ?? null,
                'repository_link' => self::linkable(self::repositoryOf($facts)),
            ];
        }

        return $details;
    }

    /**
     * A flagged package, or an unflagged one with an advisory: a package on a branch that still gets
     * security releases is `ok`, and this puts its advisories on the page without `--all`.
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
     * What the package says, not what the explanation shows: a checkout on the machine is shown as
     * null, and must not hand the link to the lock's URL behind it.
     */
    private static function repositoryOf(PackageFacts $facts): ?string
    {
        foreach ([$facts->metadata() === null ? null : $facts->metadata()->repositoryUrl(), $facts->package()->repositoryUrl()] as $url) {
            if ($url !== null && $url !== '') {
                return $url;
            }
        }

        return null;
    }

    /** A repository URL becomes an `href` only when it is a link and has no credentials ({@see RepositoryUrl}). */
    public static function linkable(?string $url): ?string
    {
        return RepositoryUrl::linkable($url);
    }
}
