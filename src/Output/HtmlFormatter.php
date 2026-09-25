<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Html\PageData;
use Lockrot\Html\ReportDocument;
use Lockrot\Verdict\Verdict;

/**
 * The report as one page: `--format=html`.
 *
 * Self-contained on purpose. Playwright's HTML reporter ships a folder and a server because it has
 * traces and videos to serve; lockrot has nothing that cannot live inside the document, so the whole
 * run goes into one file that opens from `file://`, downloads from CI as a single artifact and
 * attaches to a ticket. No server, no network.
 *
 * The page itself is built in its own repository, somework/lockrot-report, and vendored here as
 * resources/report/report.html: its script and stylesheet already inline, both pinned by the
 * Content-Security-Policy the page carries. tools/report/update-renderer brings a release in after
 * checking its build provenance, and {@see \Lockrot\Tests\Unit\Output\RendererManifestTest} checks
 * the file against the manifest that came with it. This class fills three placeholders and adds
 * nothing to the page that could run.
 *
 * What the page renders from is {@see ReportDocument}; this class only assembles.
 *
 * @internal
 */
final class HtmlFormatter implements FormatterInterface
{
    private const TEMPLATE = __DIR__.'/../../resources/report/report.html';

    private PageData $page;

    /**
     * Alone among the formatters, this one takes no FormatContext: what the page needs from the
     * run — the target PHP, the thresholds, the lock's name, the fail-on — is in the report
     * itself, and a second copy beside it could only disagree with it.
     */
    public function __construct(?PageData $page = null)
    {
        $this->page = $page ?? PageData::none();
    }

    public function format(Report $report, bool $showAll = false): string
    {
        $document = new ReportDocument($report, $this->page);

        return strtr(self::template(), [
            '{{TITLE}}' => self::text(self::title($report)),
            '{{DESCRIPTION}}' => self::text(self::description($report)),
            '{{DATA}}' => self::payload($document->toArray($showAll)),
        ]);
    }

    /**
     * The sentence under the title wherever the page is linked — a search result, a Slack unfurl, a
     * post. It says what was found, because that is what the reader is deciding whether to open,
     * and it names the three verdicts that carried the most packages rather than all nine.
     */
    private static function description(Report $report): string
    {
        $flagged = \count($report->flagged());
        $checked = $report->packagesChecked();
        if ($flagged === 0) {
            return 'lockrot checked '.$checked.' packages in composer.lock and flagged none. Every verdict carries the release dates it was decided on.';
        }

        $counts = [];
        foreach ($report->byVerdict() as $verdict => $count) {
            if ($count > 0 && Verdict::flagged($verdict)) {
                $counts[$verdict] = $count;
            }
        }
        arsort($counts);
        $named = [];
        foreach (\array_slice($counts, 0, 3, true) as $verdict => $count) {
            $named[] = $count.' '.$verdict;
        }

        return 'lockrot flagged '.$flagged.' of '.$checked.' packages in composer.lock'
            .($named === [] ? '' : ': '.implode(', ', $named))
            .'. Each finding carries its evidence, the release branches behind it and any security advisory.';
    }

    /** What the tab says, which is the first thing anyone sees of a downloaded artifact. */
    private static function title(Report $report): string
    {
        $flagged = \count($report->flagged());
        if ($flagged === 0) {
            return 'lockrot: nothing flagged in '.$report->packagesChecked().' packages';
        }

        return 'lockrot: '.$flagged.' of '.$report->packagesChecked().' packages flagged';
    }

    /**
     * The payload, safe to sit inside `<script type="application/json">`.
     *
     * An HTML parser ends that element at the first `</script`, wherever it appears, and it stops
     * parsing at `<!--` too. Both can reach here through a package's own description, name or
     * advisory title, so both are written as escapes that JSON reads back as the same string.
     * `JSON_HEX_*` would do it as well but at the cost of every quote in the document.
     *
     * @param array<string, mixed> $document
     */
    private static function payload(array $document): string
    {
        $json = json_encode($document, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Cannot encode the report for the page: '.json_last_error_msg());
        }

        return str_replace(['</', '<!--'], ['<\\/', '<\\u0021--'], $json);
    }

    /** Escapes a string for HTML text, the way the page's own renderer does for everything else. */
    private static function text(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function template(): string
    {
        $contents = file_get_contents(self::TEMPLATE);
        // The page ships inside the package and the PHAR, so a failure here means a broken
        // install rather than anything a run can recover from.
        if ($contents === false) {
            throw new \RuntimeException('Cannot read the report page '.basename(self::TEMPLATE));
        }

        return $contents;
    }
}
