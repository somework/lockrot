<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Lock\LockLineIndex;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Priority;
use Lockrot\Verdict\Verdict;

/**
 * SARIF 2.1.0 (OASIS), the format GitHub code scanning ingests through
 * `github/codeql-action/upload-sarif`, so a `composer lockrot` run can show up in a repository's
 * Security tab and on the pull request.
 *
 * One run, one rule per verdict actually present in the report, one result per finding, each
 * located on the `"name"` line of the package's entry in the analysed lock. `results` keeps the report's
 * own priority-then-severity-then-name order so two runs over the same lock produce byte-identical
 * output.
 *
 * A result carries the priority twice, for the two ways a consumer reads it: as `rank`, the numeric
 * field SARIF defines for exactly this, and as `properties.priority` next to `properties.direct`
 * and `properties.dev`, the two facts it is derived from. `ruleId` stays on the verdict; `level`
 * follows the run's fail-on threshold ({@see FormatContext::levelOf()}), whichever kind it names.
 *
 * The location's `uri` is the lock's path relative to the project directory
 * ({@see FormatContext::lockName()}) — `composer.lock`, or `alt.lock` under `COMPOSER=alt.json` — and
 * `%SRCROOT%` is that directory, so the two resolve to the file the run read.
 *
 * @internal
 */
final class SarifFormatter implements FormatterInterface
{
    private const SCHEMA_URI = 'https://json.schemastore.org/sarif-2.1.0.json';
    private const INFORMATION_URI = 'https://github.com/somework/lockrot';
    private const HELP_URI = 'https://lockrot.dev/verdicts/';
    private const URI_BASE_ID = '%SRCROOT%';

    /**
     * SARIF 2.1.0 §3.27.20 types `result.rank` as a number from 0.0 to 100.0. The five priority
     * levels are evenly spaced over it, so `none` is 0.0 and `critical` 100.0 and a consumer that
     * sorts by rank reads the report in the order lockrot prints it.
     */
    private const RANK_STEP = 25.0;

    /**
     * The README's verdict table, as {short, full} per verdict. Kept in the same wording so a
     * code-scanning alert says exactly what the README says.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const DESCRIPTIONS = [
        Verdict::ABANDONED => [
            'Package is marked abandoned by its repository, or its repository is archived',
            "The package's Composer repository marks it abandoned (Packagist by default), or its repository is archived on GitHub or GitLab.",
        ],
        Verdict::SILENT => [
            'No stable release and no repository push for a long time',
            'No stable release for at least release-high-years (default 5 years) and no repository push for at least push-high-years (default 5 years); an archived repository is reported as abandoned instead.',
        ],
        Verdict::PINNED => [
            'Installed version is a branch snapshot, or the package has no stable release',
            'The installed version is a branch snapshot (dev-* or #hash), or the package has no stable release at all.',
        ],
        Verdict::LEFT_BEHIND => [
            'The installed release branch stopped while a newer branch kept releasing',
            "No stable release on the installed version's branch for at least release-high-years (default 5 years), while a higher branch has released since: the package is alive, the branch installed here gets no fixes.",
        ],
        Verdict::OLD_PROMISE => [
            "Released before the target PHP's GA date with an open-ended php constraint",
            "The installed version was released before the target PHP's GA date, and its require.php constraint is open-ended (>=N, *) for that target.",
        ],
        Verdict::STALE => [
            'Old release or old push, below the silent thresholds',
            'Old release or old push, but not old enough — or not on both fronts — for silent.',
        ],
        Verdict::UNKNOWN => [
            'No data could be obtained for this package',
            'No data could be obtained: the package was not found in any configured Composer repository, or every lookup failed.',
        ],
        Verdict::FINISHED => [
            'Matched the finished-package allowlist',
            'Matched the built-in or project allowlist — the package is complete by design, not neglected.',
        ],
        Verdict::OK => [
            'None of the other verdicts applied',
            'None of the other verdicts applied to this package.',
        ],
    ];

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
        $rules = [];
        $ruleIndexes = [];
        $results = [];
        foreach ($showAll ? $report->findings() : $report->flagged() as $finding) {
            $verdict = $finding->verdict();
            if (!isset($ruleIndexes[$verdict])) {
                $ruleIndexes[$verdict] = \count($rules);
                $rules[] = $this->rule($verdict);
            }
            $results[] = $this->result($finding, $ruleIndexes[$verdict], $index->lineOf($finding->package()), $baseline);
        }

        $run = [
            'tool' => ['driver' => [
                'name' => 'lockrot',
                'version' => $this->context->toolVersion(),
                'informationUri' => self::INFORMATION_URI,
                'rules' => $rules,
            ]],
            'columnKind' => 'utf16CodeUnits',
        ];
        $lockDirectory = $this->context->lockDirectory();
        if ($lockDirectory !== null) {
            $run['originalUriBaseIds'] = [self::URI_BASE_ID => ['uri' => self::directoryUri($lockDirectory)]];
        }
        $run['results'] = $results;
        $run['invocations'] = [$this->invocation($report)];

        return self::encode(['$schema' => self::SCHEMA_URI, 'version' => '2.1.0', 'runs' => [$run]]);
    }

    /** @return array<string, mixed> */
    private function rule(string $verdict): array
    {
        [$short, $full] = self::DESCRIPTIONS[$verdict] ?? ['Dependency rot', 'A lockrot verdict.'];

        return [
            'id' => 'lockrot/'.$verdict,
            'name' => $verdict,
            'shortDescription' => ['text' => $verdict.': '.$short],
            'fullDescription' => ['text' => $full],
            // The rule's own level, before the run's fail-on threshold is applied to a finding:
            // every flagged verdict warns, the rest are notes.
            'defaultConfiguration' => ['level' => Verdict::flagged($verdict) ? FormatContext::LEVEL_WARNING : FormatContext::LEVEL_NOTE],
            'helpUri' => self::HELP_URI,
        ];
    }

    /** @return array<string, mixed> */
    private function result(Finding $finding, int $ruleIndex, ?int $line, ?BaselineComparison $baseline): array
    {
        $artifactLocation = ['uri' => self::relativeUri($this->context->lockName())];
        if ($this->context->lockDirectory() !== null) {
            $artifactLocation['uriBaseId'] = self::URI_BASE_ID;
        }
        $physicalLocation = ['artifactLocation' => $artifactLocation];
        if ($line !== null) {
            $physicalLocation['region'] = ['startLine' => $line];
        }

        $signals = [];
        foreach ($finding->signals() as $signal) {
            $signals[] = $signal->id();
        }
        $dataDate = $finding->dataDate();

        $properties = [
            'package' => $finding->package(),
            'version' => $finding->version(),
            'verdict' => $finding->verdict(),
            'priority' => $finding->priority(),
            'direct' => $finding->isDirect(),
            'dev' => $finding->isDev(),
            'signals' => $signals,
            'chain' => $finding->chain(),
            'direct_dependents' => $finding->directDependents(),
            'data_date' => $dataDate === null ? null : $dataDate->format(\DATE_ATOM),
        ];
        // Only when the run actually had a baseline to compare against: a null here would read as
        // "compared and unclassified" rather than "not compared at all".
        $status = $baseline === null ? null : $baseline->statusOf($finding->package());
        if ($status !== null) {
            $properties['baseline'] = $status;
        }

        return [
            'ruleId' => 'lockrot/'.$finding->verdict(),
            'ruleIndex' => $ruleIndex,
            'level' => $this->context->levelOf($finding, $baseline),
            'rank' => Priority::rank($finding->priority()) * self::RANK_STEP,
            'message' => ['text' => $this->message($finding)],
            'locations' => [['physicalLocation' => $physicalLocation]],
            // The package name alone identifies a finding across runs: one result per package, and
            // the line it sits on moves whenever anything above it in the lock changes.
            'partialFingerprints' => ['lockrot/package' => $finding->package()],
            'properties' => $properties,
        ];
    }

    /** @return array<string, mixed> */
    private function invocation(Report $report): array
    {
        // lockrot ran to completion whatever the findings say: a failed lookup is carried by the
        // notes below and by the `unknown` verdict, never by claiming the tool itself failed.
        $invocation = ['executionSuccessful' => true];

        $notes = $report->notes();
        $baseline = $report->baseline();
        $stale = $baseline === null ? null : $baseline->staleNote();
        if ($stale !== null) {
            $notes[] = $stale;
        }

        $notifications = [];
        foreach ($notes as $note) {
            $notifications[] = ['level' => FormatContext::LEVEL_NOTE, 'message' => ['text' => $note]];
        }
        if ($notifications !== []) {
            $invocation['toolExecutionNotifications'] = $notifications;
        }

        return $invocation;
    }

    private function message(Finding $finding): string
    {
        $evidence = $finding->evidenceLine();

        $message = $finding->package().' '.$finding->version();

        return $evidence === '' ? $message : $message.': '.$evidence;
    }

    /**
     * The `originalUriBaseIds` entry for %SRCROOT%: an absolute file URI for the directory the lock's
     * name is relative to, with the trailing slash SARIF 2.1.0 §3.4.4 requires of a directory URI.
     *
     * Each path segment is percent-encoded on its own so the separators survive: a checkout
     * directory may legally hold a space, `#`, `?` or non-ASCII, none of which a URI can carry raw.
     */
    private static function directoryUri(string $directory): string
    {
        $segments = [];
        foreach (explode('/', trim(str_replace('\\', '/', $directory), '/')) as $segment) {
            // rawurlencode() also escapes ":", which RFC 3986 allows unescaped inside a path segment
            // and which a Windows drive letter needs — file:///C:/project/, not file:///C%3A/project/.
            $segments[] = str_replace('%3A', ':', rawurlencode($segment));
        }

        return 'file:///'.implode('/', $segments).'/';
    }

    /**
     * The lock's `/`-separated relative name as a relative URI reference, each segment
     * percent-encoded on its own. Unlike in {@see directoryUri()}, `:` is escaped too: in the first
     * segment of a relative reference it would read as the end of a URI scheme (RFC 3986 §4.2).
     */
    private static function relativeUri(string $name): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $name)));
    }

    /** @param array<string, mixed> $document */
    private static function encode(array $document): string
    {
        // PRESERVE_ZERO_FRACTION keeps `rank` a JSON number with a fraction — 100.0, not 100 — so a
        // consumer that distinguishes the two reads every rank as the float SARIF types it as.
        $json = json_encode($document, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION);
        // Everything above is scalars, lists and string-keyed arrays built from Report data, so this
        // is unreachable in practice; guarded explicitly so a future encoding failure fails loudly
        // instead of silently emitting the string "false" (same guard as JsonFormatter).
        if ($json === false) {
            throw new \RuntimeException('Cannot encode report as SARIF: '.json_last_error_msg());
        }

        return $json."\n";
    }
}
