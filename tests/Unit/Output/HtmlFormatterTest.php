<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Output\FormatContext;
use Lockrot\Output\Formatters;
use Lockrot\Output\HtmlFormatter;
use Lockrot\Signal\Signal;
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
        return (new HtmlFormatter(FormatContext::unknown()))->format($report, $showAll);
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
        self::assertStringNotContainsString('<!--', $page);
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

    public function testTheTitleIsEscapedLikeEverythingElse(): void
    {
        $page = (new HtmlFormatter(FormatContext::unknown()))->format($this->report([], 0));

        self::assertStringNotContainsString('<title></title>', $page);
        self::assertStringContainsString('lockrot: nothing flagged in 0 packages', $page);
    }

    public function testThePayloadCarriesTheReportAndNothingItCannotShow(): void
    {
        $payload = self::payloadOf($this->page($this->report([$this->finding('vendor/pkg')])));

        self::assertSame(['context', 'report', 'details', 'baseline'], array_keys($payload));
        self::assertSame('https://lockrot.dev/schema/report-1.json', J::stringAt($payload, ['report', '$schema']));
        self::assertSame([], J::arrayAt($payload, ['details']), 'no facts were passed, so there is nothing to explain');
        self::assertSame([], J::arrayAt($payload, ['baseline']));
    }

    public function testTheFormatIsReachableByName(): void
    {
        self::assertInstanceOf(HtmlFormatter::class, Formatters::for('html'));
    }
}
