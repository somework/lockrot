<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Exception\ConfigException;
use Lockrot\Html\PageData;
use Lockrot\Html\ReportDocument;
use Lockrot\Json\JsonReader;
use Lockrot\Json\JsonWriter;
use Lockrot\Verdict\ScoreModel;

/**
 * The report as one self-contained page: docs/ci.md#-formathtml.
 *
 * The page is built in https://github.com/somework/lockrot-report and vendored as
 * resources/report/report.html. tools/report/update-renderer checks its build provenance before it
 * updates the file, and {@see \Lockrot\Tests\Unit\Output\RendererManifestTest} checks the file
 * against its manifest. The page's Content-Security-Policy pins its inline script and stylesheet,
 * so this class fills only three placeholders and must add nothing that can run.
 *
 * @internal
 */
final class HtmlFormatter implements FormatterInterface
{
    private const TEMPLATE = __DIR__.'/../../resources/report/report.html';

    private const MANIFEST = __DIR__.'/../../resources/report/manifest.json';

    private PageData $page;
    /** @var list<int> */
    private array $reads;

    /**
     * Takes no FormatContext: the report holds what the page needs, and a second copy can disagree
     * with it.
     *
     * @param list<int>|null $reads the report schema numbers the vendored renderer reads, its
     *                              manifest's `schema.report` unless given
     */
    public function __construct(?PageData $page = null, ?array $reads = null)
    {
        $this->page = $page ?? PageData::none();
        $this->reads = $reads ?? $this->page->reads() ?? self::manifestReads();
    }

    /** @throws ConfigException when the vendored renderer does not read the schema lockrot writes */
    public function format(Report $report, bool $showAll = false): string
    {
        if (!\in_array(JsonFormatter::SCHEMA, $this->reads, true)) {
            throw new ConfigException(\sprintf('html needs a lockrot-report release that reads report-%d (this build vendors one that reads [%s]); use --format=json meanwhile', JsonFormatter::SCHEMA, implode(', ', $this->reads)));
        }
        $document = new ReportDocument($report, $this->page);

        return strtr(self::template(), [
            '{{TITLE}}' => self::text(self::title($report)),
            '{{DESCRIPTION}}' => self::text(self::description($report)),
            '{{DATA}}' => self::payload($document->toArray($showAll)),
        ]);
    }

    private static function description(Report $report): string
    {
        if ($report->packagesChecked() === 0) {
            return 'lockrot found no package in composer.lock.';
        }
        $flagged = \count($report->graded());
        $checked = self::packages($report->packagesChecked());
        if ($flagged === 0) {
            return 'lockrot checked '.$checked.' in composer.lock and flagged none.';
        }
        $counts = $report->byGrade();
        $named = [];
        foreach (ScoreModel::GRADES as $grade) {
            if ($counts[$grade] > 0) {
                $named[] = $counts[$grade].' '.$grade;
            }
        }

        return 'lockrot flagged '.$flagged.' of '.$checked.' in composer.lock: '.implode(', ', $named)
            .'. Each finding lists every flag that fired, the sum behind its grade, and its next step.';
    }

    private static function title(Report $report): string
    {
        if ($report->packagesChecked() === 0) {
            return 'lockrot: composer.lock holds no package';
        }
        $flagged = \count($report->graded());
        if ($flagged === 0) {
            return 'lockrot: nothing flagged in '.self::packages($report->packagesChecked());
        }

        return 'lockrot: '.$flagged.' of '.self::packages($report->packagesChecked()).' flagged';
    }

    private static function packages(int $count): string
    {
        return $count.' '.($count === 1 ? 'package' : 'packages');
    }

    /**
     * Safe inside `<script type="application/json">`: an HTML parser ends that element at the first
     * `</script` and stops parsing at `<!--`, and package metadata can hold both. JSON reads the
     * escapes back as the same string. Not `JSON_HEX_*`: it also escapes every quote.
     *
     * @param array<string, mixed> $document
     */
    private static function payload(array $document): string
    {
        $json = JsonWriter::encode($document, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if ($json === null) {
            throw new \RuntimeException('Cannot encode the report for the page: '.json_last_error_msg());
        }

        return str_replace(['</', '<!--'], ['<\\/', '<\\u0021--'], $json);
    }

    private static function text(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @return list<int> */
    private static function manifestReads(): array
    {
        $schema = JsonReader::readObject(self::MANIFEST)['schema'] ?? null;
        $reads = \is_array($schema) ? ($schema['report'] ?? null) : null;

        return \is_array($reads) ? array_values(array_filter($reads, 'is_int')) : [];
    }

    private static function template(): string
    {
        $contents = file_get_contents(self::TEMPLATE);
        // A failure here is a broken install, not something a run can recover from.
        if ($contents === false) {
            throw new \RuntimeException('Cannot read the report page '.basename(self::TEMPLATE));
        }

        return $contents;
    }
}
