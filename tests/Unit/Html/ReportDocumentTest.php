<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Html;

use Lockrot\Analyzer\Analysis;
use Lockrot\Analyzer\Report;
use Lockrot\Baseline\Baseline;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Html\PageData;
use Lockrot\Html\ReportDocument;
use Lockrot\Output\FormatContext;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\Signal;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\JsonPath as J;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use Lockrot\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportDocumentTest extends TestCase
{
    /** @param list<Signal> $signals */
    private function finding(string $package, string $verdict, array $signals = [], string $version = '1.0.0'): Finding
    {
        return new Finding($package, $version, $verdict, $signals, ['root/app', $package], null, new \DateTimeImmutable(F::NOW));
    }

    /** @param list<Finding> $findings */
    private function report(array $findings): Report
    {
        return new Report($findings, [], new \DateTimeImmutable(F::NOW), \count($findings), 0, false);
    }

    /** @param array<string, PackageFacts> $facts */
    private function document(Report $report, array $facts = [], ?BaselineComparison $baseline = null): ReportDocument
    {
        return new ReportDocument($report, FormatContext::unknown(), new PageData(
            $facts === [] ? null : new Analysis($report, $facts),
            $baseline,
            new Thresholds(),
            '8.4'
        ));
    }

    public function testTheReportKeyIsWhatFormatJsonWrites(): void
    {
        $report = $this->report([$this->finding('vendor/pkg', Verdict::STALE)]);

        $document = $this->document($report)->toArray();

        self::assertSame(
            ['$schema' => 'https://lockrot.dev/schema/report-1.json', 'lockrot' => ['version' => Version::STRING, 'schema' => 1]] + $report->toArray(),
            J::arrayAt($document, ['report']),
            'a consumer pulling the payload out of the page gets the published document'
        );
    }

    public function testTheContextCarriesWhatTheRunWasToldToDo(): void
    {
        $document = $this->document($this->report([]))->toArray();

        self::assertSame('8.4', J::stringAt($document, ['context', 'target_php']));
        self::assertSame([
            'release-warn-years' => 3,
            'release-high-years' => 5,
            'push-warn-years' => 3,
            'push-high-years' => 5,
        ], J::arrayAt($document, ['context', 'thresholds']));
    }

    public function testOnlyTheFlaggedPackagesAreExplainedUnlessEverythingIsAsked(): void
    {
        $flagged = $this->finding('vendor/rotten', Verdict::LEFT_BEHIND);
        $fine = $this->finding('vendor/fine', Verdict::OK);
        $report = $this->report([$flagged, $fine]);
        $facts = [
            'vendor/rotten' => F::facts(F::package(['name' => 'vendor/rotten']), F::metadata([['1.0.0', '2020-01-01T00:00:00+00:00']])),
            'vendor/fine' => F::facts(F::package(['name' => 'vendor/fine']), F::metadata([['1.0.0', '2026-01-01T00:00:00+00:00']])),
        ];

        self::assertSame(['vendor/rotten'], array_keys(J::arrayAt($this->document($report, $facts)->toArray(), ['details'])));
        self::assertSame(['vendor/rotten', 'vendor/fine'], array_keys(J::arrayAt($this->document($report, $facts)->toArray(true), ['details'])));
    }

    /**
     * A package on a branch that still ships security releases has no rot verdict, so it is `ok`
     * and no format lists it. Its advisories are still the most urgent thing in the lock, so the
     * page has to be able to show them without `--all`.
     */
    public function testAnUnflaggedPackageCarryingAnAdvisoryIsExplainedAnyway(): void
    {
        $advisory = new Signal(Signal::S9, Signal::LEVEL_WARN, '1 security advisory affects 1.0.0', ['advisories' => []]);
        $report = $this->report([$this->finding('vendor/patched', Verdict::OK, [$advisory])]);
        $facts = ['vendor/patched' => F::facts(F::package(['name' => 'vendor/patched']), F::metadata([['1.0.0', '2026-01-01T00:00:00+00:00']]))];

        self::assertSame(['vendor/patched'], array_keys(J::arrayAt($this->document($report, $facts)->toArray(), ['details'])));
    }

    public function testADetailCarriesTheReleaseBranchesTheTimelineIsDrawnFrom(): void
    {
        $report = $this->report([$this->finding('vendor/pkg', Verdict::LEFT_BEHIND, [], '1.4.0')]);
        $metadata = F::metadata([['2.1.0', '2026-01-01T00:00:00+00:00'], ['1.5.0', '2021-06-01T00:00:00+00:00']]);
        $facts = ['vendor/pkg' => F::facts(F::package(['version' => '1.4.0']), $metadata)];

        $detail = J::arrayAt($this->document($report, $facts)->toArray(), ['details', 'vendor/pkg']);

        self::assertSame(['2.x', '1.x'], J::column($detail, ['metadata', 'branches'], 'branch'));
        self::assertSame([false, true], J::column($detail, ['metadata', 'branches'], 'installed'));
        self::assertArrayNotHasKey('finding', $detail, 'the report already carries it');
    }

    public function testWithoutFactsThePageStillHasAReportAndNoDetails(): void
    {
        $report = $this->report([$this->finding('vendor/pkg', Verdict::STALE)]);

        $document = (new ReportDocument($report, FormatContext::unknown()))->toArray();

        self::assertSame([], J::arrayAt($document, ['details']), 'the install-time path keeps no facts');
        self::assertSame([], J::arrayAt($document, ['baseline']));
        self::assertNotSame([], J::arrayAt($document, ['report', 'findings']));
    }

    public function testTheBaselineStateIsGivenPerPackage(): void
    {
        $report = $this->report([
            $this->finding('vendor/known', Verdict::STALE),
            $this->finding('vendor/worse', Verdict::ABANDONED),
            $this->finding('vendor/fresh', Verdict::LEFT_BEHIND),
        ]);
        // The baseline a run wrote a month ago, when both packages were merely stale.
        $before = $this->report([
            $this->finding('vendor/known', Verdict::STALE),
            $this->finding('vendor/worse', Verdict::STALE),
        ]);
        $baseline = Baseline::fromReport($before);

        $comparison = BaselineComparison::compare($baseline, $report, 'lockrot-baseline.json', ['vendor/known', 'vendor/worse', 'vendor/fresh']);

        $states = J::arrayAt($this->document($report, [], $comparison)->toArray(), ['baseline']);

        self::assertSame('known', J::stringAt($states, ['vendor/known', 'status']));
        self::assertSame('worsened', J::stringAt($states, ['vendor/worse', 'status']));
        self::assertSame(Verdict::STALE, J::stringAt($states, ['vendor/worse', 'previous_verdict']));
        self::assertSame('new', J::stringAt($states, ['vendor/fresh', 'status']));
    }

    /**
     * The URL comes out of the package's own `source.url` or `support.source`, and lands in a page
     * somebody opens in a browser.
     *
     * @dataProvider repositoryUrls
     */
    #[DataProvider('repositoryUrls')]
    public function testOnlyAnHttpUrlBecomesALink(?string $raw, ?string $expected): void
    {
        self::assertSame($expected, ReportDocument::linkable($raw));
    }

    /** @return iterable<string, array{?string, ?string}> */
    public static function repositoryUrls(): iterable
    {
        yield 'the usual one' => ['https://github.com/vendor/pkg.git', 'https://github.com/vendor/pkg'];
        yield 'plain http' => ['http://example.test/vendor/pkg', 'http://example.test/vendor/pkg'];
        yield 'composer prefixes it' => ['git+https://github.com/vendor/pkg.git', 'https://github.com/vendor/pkg'];
        yield 'an scp-style remote means https' => ['git@github.com:vendor/pkg.git', 'https://github.com/vendor/pkg'];
        yield 'script in an attribute' => ['javascript:alert(1)', null];
        yield 'a data url' => ['data:text/html;base64,PHNjcmlwdD4=', null];
        yield 'a local checkout' => ['/home/someone/vendor/pkg', null];
        yield 'ssh, which a browser cannot open' => ['ssh://git@github.com/vendor/pkg.git', null];
        yield 'nothing at all' => [null, null];
        yield 'an empty string' => ['', null];
        yield 'a quote that would break out of the attribute' => ['https://github.com/vendor/pkg"onmouseover="x', null];
    }
}
