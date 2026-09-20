<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Html;

use Lockrot\Analyzer\Analysis;
use Lockrot\Analyzer\Report;
use Lockrot\Baseline\Baseline;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Html\PageData;
use Lockrot\Html\ReportDocument;
use Lockrot\Output\FormatContext;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\Signal;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\JsonPath as J;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use Lockrot\Verdict\FailOn;
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

        self::assertSame([
            'target_php' => '8.4',
            'lock_file' => null,
            'fail_on' => FailOn::NONE,
            'flagged_verdicts' => [
                Verdict::ABANDONED,
                Verdict::SILENT,
                Verdict::PINNED,
                Verdict::LEFT_BEHIND,
                Verdict::OLD_PROMISE,
                Verdict::STALE,
            ],
            'thresholds' => [
                'release-warn-years' => 3,
                'release-high-years' => 5,
                'push-warn-years' => 3,
                'push-high-years' => 5,
            ],
        ], J::arrayAt($document, ['context']));
    }

    /**
     * The page counts findings for itself, so it has to be told which verdicts are findings. If it
     * decided on its own that anything other than `ok` and `finished` counts, `unknown` — a package
     * lockrot could not check — would be listed as a finding, and the page would show one more than
     * its own title, the text table and `--fail-on` all say.
     */
    public function testThePageIsToldWhichVerdictsAreFindings(): void
    {
        $document = $this->document($this->report([]))->toArray();

        $flagged = J::arrayAt($document, ['context', 'flagged_verdicts']);

        self::assertNotContains(Verdict::UNKNOWN, $flagged);
        self::assertNotContains(Verdict::FINISHED, $flagged);
        self::assertNotContains(Verdict::OK, $flagged);
        foreach (array_keys($flagged) as $at) {
            $verdict = J::stringAt($flagged, [$at]);
            self::assertTrue(Verdict::flagged($verdict), $verdict.' is not a flagged verdict');
        }
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

    /**
     * Explaining a package needs the thresholds and the target PHP as much as it needs the facts:
     * the branch table is read against both. Any one missing and there is nothing to explain.
     */
    public function testFactsWithoutThresholdsExplainNothing(): void
    {
        $report = $this->report([$this->finding('vendor/pkg', Verdict::LEFT_BEHIND)]);
        $analysis = new Analysis($report, ['vendor/pkg' => F::facts(F::package(), F::metadata([['1.0.0', '2020-01-01T00:00:00+00:00']]))]);

        $noThresholds = new ReportDocument($report, FormatContext::unknown(), new PageData($analysis, null, null, '8.4'));
        $noTarget = new ReportDocument($report, FormatContext::unknown(), new PageData($analysis, null, new Thresholds(), null));

        self::assertSame([], J::arrayAt($noThresholds->toArray(), ['details']));
        self::assertSame([], J::arrayAt($noTarget->toArray(), ['details']));
    }

    /** A package the run could not analyse is stepped over, not a full stop for the ones behind it. */
    public function testAPackageWithoutFactsDoesNotCutTheListShort(): void
    {
        $report = $this->report([
            $this->finding('vendor/unknown', Verdict::UNKNOWN),
            $this->finding('vendor/rotten', Verdict::LEFT_BEHIND),
        ]);
        $facts = ['vendor/rotten' => F::facts(F::package(['name' => 'vendor/rotten']), F::metadata([['1.0.0', '2020-01-01T00:00:00+00:00']]))];

        self::assertSame(['vendor/rotten'], array_keys(J::arrayAt($this->document($report, $facts)->toArray(), ['details'])));
    }

    /**
     * The link the page may turn into an `href`, decided here so the page does not have to trust
     * its own payload. The metadata's repository wins over the lock's: it is the one that follows a
     * package that moved house.
     *
     * @dataProvider repositorySources
     */
    #[DataProvider('repositorySources')]
    public function testTheDetailCarriesTheLinkThePageMayRender(?string $metadataUrl, ?string $lockSource, ?string $expected): void
    {
        $report = $this->report([$this->finding('vendor/pkg', Verdict::LEFT_BEHIND)]);
        $at = new \DateTimeImmutable('2020-01-01T00:00:00+00:00');
        $metadata = new PackageMetadata(
            'vendor/pkg',
            false,
            null,
            true,
            $at,
            '1.0.0',
            1,
            $metadataUrl,
            'library',
            new \DateTimeImmutable(F::NOW),
            ['1' => ['version' => '1.0.0', 'at' => $at, 'highest' => ['normalized' => '1.0.0.0', 'pretty' => '1.0.0', 'at' => $at]]]
        );
        $facts = ['vendor/pkg' => F::facts(F::package(['source' => $lockSource]), $metadata)];

        $detail = J::arrayAt($this->document($report, $facts)->toArray(), ['details', 'vendor/pkg']);

        self::assertSame($expected, $detail['repository_link']);
    }

    /** @return iterable<string, array{?string, ?string, ?string}> */
    public static function repositorySources(): iterable
    {
        yield 'the metadata knows' => ['https://github.com/vendor/pkg.git', null, 'https://github.com/vendor/pkg'];
        yield 'only the lock knows' => [null, 'https://gitlab.test/vendor/pkg.git', 'https://gitlab.test/vendor/pkg'];
        yield 'the metadata wins' => ['https://github.com/vendor/moved.git', 'https://github.com/vendor/old.git', 'https://github.com/vendor/moved'];
        // A path-repository package: the lock records no source URL at all.
        yield 'neither knows' => [null, '', null];
        yield 'the metadata is not a url' => ['javascript:alert(1)', 'https://github.com/vendor/pkg.git', null];
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
        yield 'whitespace around it' => ["  https://github.com/vendor/pkg.git\n", 'https://github.com/vendor/pkg'];
        yield 'a quote that would break out of the attribute' => ['https://github.com/vendor/pkg"onmouseover="x', null];
    }
}
