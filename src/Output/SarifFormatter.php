<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Config\Gate;
use Lockrot\Data\Advisory\AdvisoryRepository;
use Lockrot\Json\JsonWriter;
use Lockrot\Lock\LockLineIndex;
use Lockrot\Verdict\ScoreModel;

/**
 * SARIF 2.1.0 for GitHub code scanning: docs/ci.md#-formatsarif. The specification is at
 * https://docs.oasis-open.org/sarif/sarif/v2.1.0/os/sarif-v2.1.0-os.html
 *
 * `results` keeps the report's order, so the same lock and the same data give byte-identical output.
 *
 * @internal
 */
final class SarifFormatter implements FormatterInterface
{
    private const SCHEMA_URI = 'https://json.schemastore.org/sarif-2.1.0.json';
    private const INFORMATION_URI = 'https://github.com/somework/lockrot';
    private const URI_BASE_ID = '%SRCROOT%';

    private const ADVISORIES_UNCHECKED = 'advisories-unchecked';

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
        $run = $this->context->runOf($report);
        $document = $report->withRun($run)->toArray();
        $rules = [];
        $results = [];
        foreach ($this->context->recordFindings($report, $showAll) as $finding) {
            $records = new SarifRecords($finding, $gate->standingOf($finding->package()), $report->baseline());
            foreach ($records->results($showAll, $this->context->isUncheckedRecord($finding, $showAll)) as $result) {
                $result['locations'] = [['physicalLocation' => $this->physicalLocation($index->lineOf($finding->package()))]];
                $results[] = $result;
            }
            $rules = array_merge($rules, $records->rules());
        }
        $gap = $this->context->advisoriesNotCheckedLevel($report);
        if ($gap !== null) {
            $rules['lockrot/'.self::ADVISORIES_UNCHECKED] = SarifRecords::rule('lockrot/'.self::ADVISORIES_UNCHECKED, null);
            $results[] = [
                'ruleId' => 'lockrot/'.self::ADVISORIES_UNCHECKED,
                'level' => $gap,
                'message' => ['text' => 'advisories not checked: the gate could not read the advisories of every package'],
                'locations' => [['physicalLocation' => ['artifactLocation' => $this->artifactLocation()]]],
                'partialFingerprints' => ['lockrot/run' => self::ADVISORIES_UNCHECKED],
            ];
        }
        $ruleIndexes = array_flip(array_keys($rules));
        foreach ($results as $i => $result) {
            $results[$i] = ['ruleId' => $result['ruleId'], 'ruleIndex' => $ruleIndexes[$result['ruleId']]] + $result;
        }
        $runBlock = \is_array($document['run']) ? $document['run'] : [];

        $sarifRun = [
            'tool' => ['driver' => [
                'name' => 'lockrot',
                'version' => $this->context->toolVersion(),
                'informationUri' => self::INFORMATION_URI,
                'rules' => array_values($rules),
            ]],
            'columnKind' => 'utf16CodeUnits',
        ];
        $lockDirectory = $this->context->lockDirectory();
        if ($lockDirectory !== null) {
            $sarifRun['originalUriBaseIds'] = [self::URI_BASE_ID => ['uri' => self::directoryUri($lockDirectory)]];
        }
        $sarifRun['results'] = $results;
        $sarifRun['invocations'] = [$this->invocation($report, $gate, $document['gate'], $runBlock)];
        $sarifRun['properties'] = [
            'lockrot_schema' => 2,
            'text_grammar' => $runBlock['text_grammar'] ?? null,
            'score_text_grammar' => ScoreModel::SCORE_TEXT_GRAMMAR,
            'score_model_id' => ScoreModel::ID,
            'fix_model' => $runBlock['fix_model'] ?? null,
            'advisory_lookup' => $report->advisoryLookup(),
        ];

        return self::encode(['$schema' => self::SCHEMA_URI, 'version' => '2.1.0', 'runs' => [$sarifRun]]);
    }

    /** @return array{artifactLocation: array{uri: string, uriBaseId?: string}, region?: array{startLine: int}} */
    private function physicalLocation(?int $line): array
    {
        $location = ['artifactLocation' => $this->artifactLocation()];
        if ($line !== null) {
            $location['region'] = ['startLine' => $line];
        }

        return $location;
    }

    /**
     * The invocation: the exit code that the gate decides, `gate.text`, the gate and
     * the run keys of report-2, the run notes, and one warning per failed advisory repository.
     *
     * @param mixed                $gateBlock report-2's root `gate`
     * @param array<mixed, mixed>  $runBlock  report-2's `run`
     *
     * @return array<string, mixed>
     */
    private function invocation(Report $report, Gate $gate, $gateBlock, array $runBlock): array
    {
        $invocation = [
            'executionSuccessful' => true,
            'exitCode' => $gate->fails() ? 1 : 0,
            'exitCodeDescription' => RunGateText::render($gate, $this->context->runOf($report)),
            'properties' => [
                'gate' => $gateBlock,
                'fail_on' => $runBlock['fail_on'] ?? null,
                'fail_on_source' => $runBlock['fail_on_source'] ?? null,
                'gates' => $runBlock['gates'] ?? [],
                'mode' => $runBlock['mode'] ?? null,
                'strict_network' => $this->context->runOf($report)->strictNetwork(),
            ],
        ];
        $notifications = [];
        foreach ($report->advisoryLookup()->repositories() as $repository) {
            if ($repository->outcome() === AdvisoryRepository::FAILED) {
                $notifications[] = ['level' => FormatContext::LEVEL_WARNING, 'message' => ['text' => RunFooter::repository($repository)]];
            }
        }
        $notes = $report->notes();
        $baseline = $report->baseline();
        $stale = $baseline === null ? null : $baseline->staleNote();
        if ($stale !== null) {
            $notes[] = $stale;
        }
        foreach ($notes as $note) {
            $notifications[] = ['level' => FormatContext::LEVEL_NOTE, 'message' => ['text' => $note]];
        }
        if ($notifications !== []) {
            $invocation['toolExecutionNotifications'] = $notifications;
        }

        return $invocation;
    }

    /** @return array{uri: string, uriBaseId?: string} */
    private function artifactLocation(): array
    {
        $location = ['uri' => self::relativeUri($this->context->lockName())];
        if ($this->context->lockDirectory() !== null) {
            $location['uriBaseId'] = self::URI_BASE_ID;
        }

        return $location;
    }

    /**
     * An absolute file URI with the trailing slash that SARIF 2.1.0 §3.4.4 requires of a directory.
     * Each segment is percent-encoded on its own so the separators survive: a directory can hold a
     * space, `#`, `?` or non-ASCII.
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
     * The lock's name as a relative URI reference, each segment percent-encoded on its own. A `:`
     * in the first segment ends a URI scheme (RFC 3986 §4.2), so it is escaped here, unlike in
     * {@see directoryUri()}.
     */
    private static function relativeUri(string $name): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $name)));
    }

    /** @param array<string, mixed> $document */
    private static function encode(array $document): string
    {
        // PRESERVE_ZERO_FRACTION writes `rank` as 100.0, not 100, the float that SARIF types it as.
        $json = JsonWriter::encode($document, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION);
        // Report data always encodes. Without this guard, a failure prints an empty document.
        if ($json === null) {
            throw new \RuntimeException('Cannot encode report as SARIF: '.json_last_error_msg());
        }

        return $json."\n";
    }
}
