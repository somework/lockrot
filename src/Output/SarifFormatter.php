<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Lock\LockLineIndex;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;

/**
 * SARIF 2.1.0 (OASIS), the format GitHub code scanning ingests through
 * `github/codeql-action/upload-sarif`, so a `composer lockrot` run can show up in a repository's
 * Security tab and on the pull request.
 *
 * One run, one rule per verdict actually present in the report, one result per finding, each
 * located on the `"name"` line of the package's composer.lock entry. The document is validated
 * against the official schema in SarifFormatterTest; `results` keeps the report's own
 * severity-then-name order so two runs over the same lock produce byte-identical output.
 */
final class SarifFormatter implements FormatterInterface
{
    private const SCHEMA_URI = 'https://json.schemastore.org/sarif-2.1.0.json';
    private const INFORMATION_URI = 'https://github.com/somework/lockrot';
    private const HELP_URI = 'https://github.com/somework/lockrot#what-the-verdicts-mean';
    private const ARTIFACT_URI = 'composer.lock';
    private const URI_BASE_ID = '%SRCROOT%';

    /**
     * The README's "What the verdicts mean" table, as {short, full} per verdict. Kept in the same
     * wording so a code-scanning alert says exactly what the README says.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const DESCRIPTIONS = [
        Verdict::ABANDONED => [
            'Package is flagged abandoned, or its repository is archived',
            "The package's Composer repository flags it abandoned (Packagist by default), or its GitHub repository is archived.",
        ],
        Verdict::SILENT => [
            'No stable release and no repository push for a long time',
            'No stable release for at least release-high-years (default 5 years) and no repository push for at least push-high-years (default 5 years); an archived repository is reported as abandoned instead.',
        ],
        Verdict::PINNED => [
            'Installed version is a branch snapshot, or the package has no stable release',
            'The installed version is a branch snapshot (dev-* or #hash), or the package has no stable release at all.',
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

        $rules = [];
        $ruleIndexes = [];
        $results = [];
        foreach ($showAll ? $report->findings() : $report->flagged() as $finding) {
            $verdict = $finding->verdict();
            if (!isset($ruleIndexes[$verdict])) {
                $ruleIndexes[$verdict] = \count($rules);
                $rules[] = $this->rule($verdict);
            }
            $results[] = $this->result($finding, $ruleIndexes[$verdict], $index->lineOf($finding->package()));
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
        if ($lockPath !== null) {
            $run['originalUriBaseIds'] = [self::URI_BASE_ID => ['uri' => self::directoryUri($lockPath)]];
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
    private function result(Finding $finding, int $ruleIndex, ?int $line): array
    {
        $artifactLocation = ['uri' => self::ARTIFACT_URI];
        if ($this->context->lockPath() !== null) {
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

        return [
            'ruleId' => 'lockrot/'.$finding->verdict(),
            'ruleIndex' => $ruleIndex,
            'level' => $this->context->levelOf($finding->verdict()),
            'message' => ['text' => $this->message($finding)],
            'locations' => [['physicalLocation' => $physicalLocation]],
            // The package name alone identifies a finding across runs: one result per package, and
            // the line it sits on moves whenever anything above it in the lock changes.
            'partialFingerprints' => ['lockrot/package' => $finding->package()],
            'properties' => [
                'package' => $finding->package(),
                'version' => $finding->version(),
                'verdict' => $finding->verdict(),
                'signals' => $signals,
                'chain' => $finding->chain(),
                'data_date' => $dataDate === null ? null : $dataDate->format(\DATE_ATOM),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function invocation(Report $report): array
    {
        // lockrot ran to completion whatever the findings say: a failed lookup is carried by the
        // notes below and by the `unknown` verdict, never by claiming the tool itself failed.
        $invocation = ['executionSuccessful' => true];

        $notifications = [];
        foreach ($report->notes() as $note) {
            $notifications[] = ['level' => FormatContext::LEVEL_NOTE, 'message' => ['text' => $note]];
        }
        if ($notifications !== []) {
            $invocation['toolExecutionNotifications'] = $notifications;
        }

        return $invocation;
    }

    private function message(Finding $finding): string
    {
        $evidence = $finding->evidence();
        if ($finding->allowlistReason() !== null) {
            $evidence = ($evidence === '' ? '' : $evidence.'; ').'allowlisted: '.$finding->allowlistReason();
        }

        $message = $finding->package().' '.$finding->version();

        return $evidence === '' ? $message : $message.': '.$evidence;
    }

    /**
     * The `originalUriBaseIds` entry for %SRCROOT%: an absolute file URI for the directory the lock
     * was read from, with the trailing slash SARIF 2.1.0 §3.4.4 requires of a directory URI.
     *
     * Each path segment is percent-encoded on its own so the separators survive: a checkout
     * directory may legally hold a space, `#`, `?` or non-ASCII, none of which a URI can carry raw.
     */
    private static function directoryUri(string $lockPath): string
    {
        $directory = rtrim(str_replace('\\', '/', \dirname($lockPath)), '/');

        $segments = [];
        foreach (explode('/', ltrim($directory, '/')) as $segment) {
            // rawurlencode() also escapes ":", which RFC 3986 allows unescaped inside a path segment
            // and which a Windows drive letter needs — file:///C:/project/, not file:///C%3A/project/.
            $segments[] = str_replace('%3A', ':', rawurlencode($segment));
        }

        return 'file:///'.implode('/', $segments).'/';
    }

    /** @param array<string, mixed> $document */
    private static function encode(array $document): string
    {
        $json = json_encode($document, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        // Everything above is scalars, lists and string-keyed arrays built from Report data, so this
        // is unreachable in practice; guarded explicitly so a future encoding failure fails loudly
        // instead of silently emitting the string "false" (same guard as JsonFormatter).
        if ($json === false) {
            throw new \RuntimeException('Cannot encode report as SARIF: '.json_last_error_msg());
        }

        return $json."\n";
    }
}
