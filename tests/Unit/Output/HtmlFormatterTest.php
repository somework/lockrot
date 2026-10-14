<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Analyzer\Analysis;
use Lockrot\Analyzer\LibyearsMeasurement;
use Lockrot\Analyzer\Report;
use Lockrot\Exception\ConfigException;
use Lockrot\Html\PageData;
use Lockrot\Output\Formatters;
use Lockrot\Output\HtmlFormatter;
use Lockrot\Output\JsonFormatter;
use Lockrot\Signal\Signal;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\JsonPath as J;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HtmlFormatterTest extends TestCase
{
    /** @param list<Finding> $findings */
    private function report(array $findings, int $checked = 1): Report
    {
        return new Report($findings, [], new \DateTimeImmutable(F::NOW), $checked, 0);
    }

    /** @param list<Signal> $signals */
    private function finding(string $package, string $verdict = Verdict::STALE, array $signals = []): Finding
    {
        return (new FindingBuilder())->withPackage($package)->withVerdict($verdict)->withSignals($signals)->withChain(['root/app', $package])->withDataDate(new \DateTimeImmutable(F::NOW))->build();
    }

    private function page(Report $report, bool $showAll = false): string
    {
        return (new HtmlFormatter(null, [JsonFormatter::SCHEMA]))->format($report, $showAll);
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

    public function testThePageRefusesAReportItsVendoredRendererCannotRead(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('html needs a lockrot-report release that reads report-2 (this build vendors one that reads [1]); use --format=json meanwhile');

        (new HtmlFormatter())->format(new Report([], [], new \DateTimeImmutable('2026-09-14T00:00:00+00:00'), 0, 0));
    }

    public function testThePageIsOneFileWithItsStylesAndScriptInline(): void
    {
        $page = $this->page($this->report([$this->finding('vendor/pkg')]));

        self::assertStringStartsWith('<!doctype html>', $page);
        self::assertSame(1, substr_count($page, '<style>'), 'the stylesheet is inline, not linked');
        self::assertStringContainsString('LockrotReport', $page, 'and so is the renderer');
        self::assertStringNotContainsString('{{DATA}}', $page);
        self::assertStringNotContainsString('{{TITLE}}', $page);
        self::assertStringNotContainsString('{{DESCRIPTION}}', $page);
    }

    /**
     * The page pins its one script and one stylesheet by hash and allows no connection: whatever a
     * package's name or an advisory's title manages to put in the markup cannot run, and nothing
     * the page renders can be sent anywhere. The renderer's build writes the policy. lockrot must
     * add nothing to the page beyond what the policy allows.
     */
    public function testThePageCarriesAPolicyThatAllowsOnlyItsOwnCode(): void
    {
        $page = $this->page($this->report([$this->finding('vendor/pkg')]));

        $matched = preg_match('{<meta http-equiv="Content-Security-Policy" content="([^"]+)">}', $page, $m);
        self::assertSame(1, $matched, 'the page carries a policy');
        self::assertStringContainsString("default-src 'none'", $m[1]);
        self::assertMatchesRegularExpression("{script-src 'sha256-[A-Za-z0-9+/=]+'}", $m[1]);
        self::assertStringNotContainsString('unsafe-inline', $m[1]);
        self::assertSame(1, substr_count($page, '<script>'), 'one inline script, the one the policy pins');
    }

    /**
     * A report about dependency risk does not phone home, and a CI artifact is often opened from
     * `file://` or under a strict CSP. Only the documentation links the page renders can point
     * outward, and the reader's click opens those, not a fetch.
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

    /**
     * @return iterable<string, array{list<array{string, string}>, int, string, string}>
     */
    public static function provideTheTitleAndTheDescriptionOfEachForm(): iterable
    {
        $tail = ' Each finding lists every flag that fired, the sum behind its grade, and its next step.';

        yield 'flagged' => [[['vendor/pkg', Verdict::STALE]], 12, 'lockrot: 1 of 12 packages flagged', 'lockrot flagged 1 of 12 packages in composer.lock: 1 low.'.$tail];
        yield 'flagged, one package checked' => [[['vendor/pkg', Verdict::STALE]], 1, 'lockrot: 1 of 1 package flagged', 'lockrot flagged 1 of 1 package in composer.lock: 1 low.'.$tail];
        yield 'nothing flagged' => [[['vendor/pkg', Verdict::OK]], 12, 'lockrot: nothing flagged in 12 packages', 'lockrot checked 12 packages in composer.lock and flagged none.'];
        yield 'nothing flagged, one package checked' => [[['vendor/pkg', Verdict::OK]], 1, 'lockrot: nothing flagged in 1 package', 'lockrot checked 1 package in composer.lock and flagged none.'];
        yield 'an empty lock' => [[], 0, 'lockrot: composer.lock holds no package', 'lockrot found no package in composer.lock.'];
    }

    /**
     * @param list<array{string, string}> $findings
     *
     * @dataProvider provideTheTitleAndTheDescriptionOfEachForm
     */
    #[DataProvider('provideTheTitleAndTheDescriptionOfEachForm')]
    public function testTheTitleAndTheDescriptionSayWhatTheRunFound(array $findings, int $checked, string $title, string $description): void
    {
        $page = $this->page($this->report(array_map(fn (array $f): Finding => $this->finding($f[0], $f[1]), $findings), $checked));

        self::assertStringContainsString('<title>'.$title.'</title>', $page);
        self::assertStringContainsString('<meta name="description" content="'.$description.'">', $page);
    }

    public function testThePayloadCarriesTheLibyearsBlock(): void
    {
        $measured = (new FindingBuilder())->withPackage('smalot/pdfparser')->withVersion('v1.1.0')->withVerdict(Verdict::LEFT_BEHIND)->withChain(['smalot/pdfparser'])->withDataDate(new \DateTimeImmutable(F::NOW))->withDirectDependents(['smalot/pdfparser'])->withLibyears(LibyearsMeasurement::of(4.7123))->build();
        $payload = self::payloadOf($this->page($this->report([$measured, $this->finding('vendor/pinned', Verdict::PINNED)], 2)));

        self::assertSame(4.71, J::arrayAt($payload, ['report', 'libyears'])['total']);
        self::assertSame(4.71, J::arrayAt($payload, ['report', 'findings', 0])['libyears']);
        self::assertNull(J::arrayAt($payload, ['report', 'findings', 1])['libyears']);
    }

    public function testThePayloadCarriesTheReportAndNothingItCannotShow(): void
    {
        $payload = self::payloadOf($this->page($this->report([$this->finding('vendor/pkg')])));

        // The run settings and each finding's standing against the baseline live in the report.
        self::assertSame(['report', 'details'], array_keys($payload));
        self::assertSame('https://lockrot.dev/schema/report-2.json', J::stringAt($payload, ['report', '$schema']));
        self::assertSame([], J::arrayAt($payload, ['details']), 'no facts were passed, so there is nothing to explain');
    }

    /**
     * Without `--all` the page explains the flagged packages and leaves the rest as rows, which keeps
     * the page small.
     */
    public function testTheDefaultIsTheFlaggedPackagesOnly(): void
    {
        $report = $this->report([$this->finding('vendor/rotten'), $this->finding('vendor/fine', Verdict::OK)], 2);
        $facts = [
            'vendor/rotten' => F::facts(F::package(['name' => 'vendor/rotten']), F::metadata([['1.0.0', '2020-01-01T00:00:00+00:00']])),
            'vendor/fine' => F::facts(F::package(['name' => 'vendor/fine']), F::metadata([['1.0.0', '2026-01-01T00:00:00+00:00']])),
        ];
        $formatter = new HtmlFormatter(new PageData(new Analysis($report, $facts), new Thresholds(), '8.4'), [JsonFormatter::SCHEMA]);

        self::assertSame(['vendor/rotten'], array_keys(J::arrayAt(self::payloadOf($formatter->format($report)), ['details'])));
        self::assertSame(['vendor/fine', 'vendor/rotten'], array_keys(J::arrayAt(self::payloadOf($formatter->format($report, true)), ['details'])));
    }

    /**
     * Escaping every slash inflates a page full of URLs for nothing: the payload sits in a
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
     * Whether a published report can be indexed is the publisher's call, made in their robots.txt
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
        self::assertMatchesRegularExpression('/lockrot flagged \\d+ of 40 packages in composer\\.lock: \\d+ (critical|high|medium|low)/', $page);
        self::assertStringContainsString('<meta property="og:image" content="https://lockrot.dev/assets/og.png">', $page);
        self::assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $page);
    }

    /**
     * The sentence under the title is what a search result and a Slack unfurl show. It counts each
     * grade in verdict order, and a cause word that the grades replace does not appear in it.
     */
    public function testTheDescriptionCountsEachGradeInVerdictOrder(): void
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
            'lockrot flagged 14 of 60 packages in composer.lock: 2 high, 8 medium, 4 low. Each finding lists every flag that fired, the sum behind its grade, and its next step.',
            $page
        );
        self::assertStringNotContainsString('1 abandoned', $page, 'the description counts grades, not flags');
        self::assertStringNotContainsString('1 silent', $page);
    }

    /**
     * The page states nothing about the run outside its payload. A PHP version, a lock file or a
     * verdict can appear only in the payload and in the two lines that lockrot writes about it, the
     * title and the description.
     */
    public function testThePageSaysNothingAboutTheRunOutsideItsPayload(): void
    {
        $page = $this->page($this->report([$this->finding('vendor/pkg')]));

        $withoutPayload = preg_replace('{<script id="lockrot-data" type="application/json">.*?</script>}s', '', $page);
        self::assertIsString($withoutPayload);
        self::assertStringNotContainsString('vendor/pkg', $withoutPayload, 'package names come from the payload');
        self::assertDoesNotMatchRegularExpression('{target PHP \d}', $withoutPayload);
    }

    public function testTheFormatIsReachableByName(): void
    {
        self::assertInstanceOf(HtmlFormatter::class, Formatters::for('html'));
    }

    /** The numbers given to the formatter win over the page's, which win over the manifest's. */
    public function testTheReadsGivenToTheFormatterWinOverThePages(): void
    {
        $report = new Report([], [], new \DateTimeImmutable('2026-09-14T00:00:00+00:00'), 0, 0);

        $this->expectException(ConfigException::class);
        (new HtmlFormatter(new PageData(null, null, null, null, [JsonFormatter::SCHEMA]), [1]))->format($report);
    }

    public function testThePagesReadsWinOverTheManifests(): void
    {
        $report = new Report([], [], new \DateTimeImmutable('2026-09-14T00:00:00+00:00'), 0, 0);

        self::assertStringContainsString('<html', (new HtmlFormatter(new PageData(null, null, null, null, [JsonFormatter::SCHEMA])))->format($report));
    }
}
