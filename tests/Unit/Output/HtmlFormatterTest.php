<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Analyzer\Analysis;
use Lockrot\Analyzer\Report;
use Lockrot\Html\PageData;
use Lockrot\Output\Formatters;
use Lockrot\Output\HtmlFormatter;
use Lockrot\Signal\Signal;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\JsonPath as J;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\TestCase;

final class HtmlFormatterTest extends TestCase
{
    /** @param list<Finding> $findings */
    private function report(array $findings, int $checked = 1): Report
    {
        return new Report($findings, [], new \DateTimeImmutable(F::NOW), $checked, 0, false);
    }

    /** @param list<Signal> $signals */
    private function finding(string $package, string $verdict = Verdict::STALE, array $signals = []): Finding
    {
        return new Finding($package, '1.0.0', $verdict, $signals, ['root/app', $package], null, new \DateTimeImmutable(F::NOW));
    }

    private function page(Report $report, bool $showAll = false): string
    {
        return (new HtmlFormatter())->format($report, $showAll);
    }

    /** @return array<mixed, mixed> */
    private static function payloadOf(string $page): array
    {
        $matched = preg_match('{<script id="lockrot-data" type="application/json">(.*?)</script>}s', $page, $m);
        self::assertSame(1, $matched, 'the page carries exactly one payload');
        $decoded = json_decode($m[1], true);
        self::assertIsArray($decoded, 'the payload is valid JSON: '.json_last_error_msg());

        return $decoded;
    }

    public function testThePageIsOneFileWithItsStylesAndScriptInline(): void
    {
        $page = $this->page($this->report([$this->finding('vendor/pkg')]));

        self::assertStringStartsWith('<!doctype html>', $page);
        self::assertStringContainsString('<style>', $page);
        self::assertStringContainsString('.row {', $page, 'the stylesheet is spliced in, not linked');
        self::assertStringContainsString('function baselineState', $page, 'and so is the script');
        // Order, not just presence: report.js reads LockrotLib while it is still evaluating, so a
        // page that carries both halves the wrong way round throws before it renders anything.
        $library = strpos($page, 'var LockrotLib');
        $application = strpos($page, 'function baselineState');
        self::assertIsInt($library, 'the DOM-free half is in the page');
        self::assertIsInt($application);
        self::assertLessThan($application, $library, 'and it comes first');
        self::assertStringNotContainsString('{{CSS}}', $page);
        self::assertStringNotContainsString('{{DATA}}', $page);
    }

    /**
     * A report about dependency risk does not phone home, and a CI artifact is often opened from
     * `file://` or under a strict CSP. Only the documentation links the page renders may point
     * outward, and those are written by the reader's click, not fetched.
     */
    public function testThePageFetchesNothing(): void
    {
        $page = $this->page($this->report([$this->finding('vendor/pkg')]));

        self::assertDoesNotMatchRegularExpression('{<link[^>]+rel="stylesheet"}i', $page);
        self::assertDoesNotMatchRegularExpression('{<script[^>]+src=}i', $page);
        self::assertDoesNotMatchRegularExpression('{<img[^>]+src=}i', $page);
        self::assertStringNotContainsString('fonts.googleapis.com', $page);
        self::assertStringNotContainsString('@import', $page);
        self::assertStringNotContainsString('fetch(', $page);
        self::assertStringNotContainsString('XMLHttpRequest', $page);
    }

    /**
     * Every string in the payload came off the network. An HTML parser ends the script element at
     * the first `</script`, so a package that names itself one must not be able to close it.
     */
    public function testAPackageCannotCloseTheScriptItIsEmbeddedIn(): void
    {
        $nasty = 'evil/</script><script>alert(1)</script>';
        $page = $this->page($this->report([$this->finding($nasty)]));

        self::assertStringNotContainsString('</script><script>alert(1)', $page);
        $matched = preg_match('{<script id="lockrot-data" type="application/json">(.*?)</script>}s', $page, $m);
        self::assertSame(1, $matched);
        self::assertStringNotContainsString('<!--', $m[1], 'a comment opener inside the payload would end parsing too');
        $payload = self::payloadOf($page);
        self::assertSame($nasty, J::stringAt($payload, ['report', 'findings', 0, 'package']), 'and it still reads back unchanged');
    }

    public function testTheTitleSaysWhatTheRunFound(): void
    {
        $flagged = $this->page($this->report([$this->finding('vendor/pkg')], 12));
        self::assertStringContainsString('<title>lockrot: 1 of 12 packages flagged</title>', $flagged);

        $clean = $this->page($this->report([$this->finding('vendor/pkg', Verdict::OK)], 12));
        self::assertStringContainsString('<title>lockrot: nothing flagged in 12 packages</title>', $clean);
    }

    public function testThePageCarriesTheLibyearsBlockAndAPlaceToShowIt(): void
    {
        $measured = new Finding('smalot/pdfparser', 'v1.1.0', Verdict::LEFT_BEHIND, [], ['smalot/pdfparser'], null, new \DateTimeImmutable(F::NOW), null, false, ['smalot/pdfparser'], 4.7123);
        $page = $this->page($this->report([$measured, $this->finding('vendor/pinned', Verdict::PINNED)], 2));
        $payload = self::payloadOf($page);

        self::assertStringContainsString('id="libyearsTotal"', $page, 'the ledger has a place for the total');
        self::assertStringContainsString('id="libyearsLine"', $page, 'and for the line under it');
        self::assertStringContainsString('function libyearsSummary', $page, 'the library that writes the words under the figure is inline');
        self::assertStringContainsString('id="libyearsDef"', $page, 'and the glossary says what the number is');
        self::assertSame(4.71, J::arrayAt($payload, ['report', 'libyears'])['total']);
        self::assertSame(4.71, J::arrayAt($payload, ['report', 'findings', 0])['libyears']);
        self::assertNull(J::arrayAt($payload, ['report', 'findings', 1])['libyears']);
    }

    public function testTheTitleIsEscapedLikeEverythingElse(): void
    {
        $page = (new HtmlFormatter())->format($this->report([], 0));

        self::assertStringNotContainsString('<title></title>', $page);
        self::assertStringContainsString('lockrot: nothing flagged in 0 packages', $page);
    }

    public function testThePayloadCarriesTheReportAndNothingItCannotShow(): void
    {
        $payload = self::payloadOf($this->page($this->report([$this->finding('vendor/pkg')])));

        // Two keys, not four: what the run was told to do and where each finding stands against
        // the baseline live in the report itself now, so the page reads them from there.
        self::assertSame(['report', 'details'], array_keys($payload));
        self::assertSame('https://lockrot.dev/schema/report-1.json', J::stringAt($payload, ['report', '$schema']));
        self::assertSame([], J::arrayAt($payload, ['details']), 'no facts were passed, so there is nothing to explain');
    }

    /**
     * Without `--all` the page explains the flagged packages and leaves the rest as rows, which is
     * what keeps a 100-package report near 250 KB rather than a megabyte.
     */
    public function testTheDefaultIsTheFlaggedPackagesOnly(): void
    {
        $report = $this->report([$this->finding('vendor/rotten'), $this->finding('vendor/fine', Verdict::OK)], 2);
        $facts = [
            'vendor/rotten' => F::facts(F::package(['name' => 'vendor/rotten']), F::metadata([['1.0.0', '2020-01-01T00:00:00+00:00']])),
            'vendor/fine' => F::facts(F::package(['name' => 'vendor/fine']), F::metadata([['1.0.0', '2026-01-01T00:00:00+00:00']])),
        ];
        $formatter = new HtmlFormatter(new PageData(new Analysis($report, $facts), new Thresholds(), '8.4'));

        self::assertSame(['vendor/rotten'], array_keys(J::arrayAt(self::payloadOf($formatter->format($report)), ['details'])));
        self::assertSame(['vendor/rotten', 'vendor/fine'], array_keys(J::arrayAt(self::payloadOf($formatter->format($report, true)), ['details'])));
    }

    /**
     * Escaping every slash would inflate a page full of URLs for nothing: the payload sits in a
     * script element, where a slash is only dangerous next to `</`, and that pair is escaped on its
     * own.
     */
    public function testTheUrlsInThePayloadAreNotEscapedSlashBySlash(): void
    {
        $page = $this->page($this->report([$this->finding('vendor/pkg')]));

        self::assertStringContainsString('"https://lockrot.dev/schema/report-1.json"', $page);
        self::assertStringNotContainsString('https:\/\/lockrot.dev', $page);
    }

    /**
     * Whether a published report may be indexed is the publisher's call, made in their robots.txt
     * and their headers. What the page owes a link is a sentence and a card.
     */
    public function testThePageCarriesWhatALinkNeedsAndNoIndexingPolicy(): void
    {
        $report = $this->report([
            $this->finding('vendor/gone', Verdict::ABANDONED),
            $this->finding('vendor/quiet', Verdict::SILENT),
            $this->finding('vendor/fine', Verdict::OK),
        ], 40);
        $page = $this->page($report);

        self::assertStringNotContainsString('name="robots"', $page);
        self::assertStringContainsString('lockrot flagged 2 of 40 packages in composer.lock: 1 abandoned, 1 silent.', $page);
        self::assertStringContainsString('<meta property="og:image" content="https://lockrot.dev/assets/og.png">', $page);
        self::assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $page);
    }

    /**
     * The sentence under the title is what a search result and a Slack unfurl show, and it has room
     * for three verdicts. Which three is the whole point: the biggest counts, biggest first. A run
     * with five flagged verdicts names left-behind, old-promise and stale, and says nothing about
     * the two singletons below them.
     */
    public function testTheDescriptionNamesTheThreeBiggestVerdictsInOrder(): void
    {
        $findings = [$this->finding('vendor/gone', Verdict::ABANDONED), $this->finding('vendor/quiet', Verdict::SILENT)];
        foreach (range(1, 3) as $i) {
            $findings[] = $this->finding('vendor/left'.$i, Verdict::LEFT_BEHIND);
        }
        foreach (range(1, 5) as $i) {
            $findings[] = $this->finding('vendor/old'.$i, Verdict::OLD_PROMISE);
        }
        foreach (range(1, 4) as $i) {
            $findings[] = $this->finding('vendor/stale'.$i, Verdict::STALE);
        }

        $page = $this->page($this->report($findings, 60));

        self::assertStringContainsString(
            'lockrot flagged 14 of 60 packages in composer.lock: 5 old-promise, 4 stale, 3 left-behind.',
            $page
        );
        self::assertStringNotContainsString('1 abandoned', $page, 'the fourth and fifth verdicts do not fit');
        self::assertStringNotContainsString('1 silent', $page);
    }

    /**
     * The markup carries placeholders for the three values in the header bar, and the script
     * replaces all three. A placeholder that names a PHP version is a claim about the run, made by
     * a file that has not read the run yet: it is what a reader sees before the script goes, what
     * a reader sees if it never goes, and what anyone reading the file itself sees. The other two
     * placeholders are em dashes; this one was `8.4`.
     */
    public function testThePageNamesNoTargetPhpUntilItKnowsOne(): void
    {
        $page = $this->page($this->report([$this->finding('vendor/pkg')]));

        $matched = preg_match('{<b class="mono" id="mTarget">(.*?)</b>}', $page, $m);
        self::assertSame(1, $matched, 'the header carries the target PHP slot');
        self::assertSame("\u{2014}", $m[1], 'and it holds nothing until the script fills it');
    }

    public function testACleanRunSaysSoInItsDescription(): void
    {
        $page = $this->page($this->report([$this->finding('vendor/fine', Verdict::OK)], 40));

        self::assertStringContainsString('lockrot checked 40 packages in composer.lock and flagged none.', $page);
    }

    public function testTheFormatIsReachableByName(): void
    {
        self::assertInstanceOf(HtmlFormatter::class, Formatters::for('html'));
    }
}
