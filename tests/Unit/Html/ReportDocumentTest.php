<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Html;

use Lockrot\Analyzer\Analysis;
use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunSettings;
use Lockrot\Baseline\Baseline;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Html\PageData;
use Lockrot\Html\ReportDocument;
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
        // The baseline lives in the report now, not beside it: the page reads a finding's standing
        // out of the same document `--format=json` writes.
        return new ReportDocument(
            $baseline === null ? $report : $report->withBaseline($baseline),
            new PageData($facts === [] ? null : new Analysis($report, $facts), new Thresholds(), '8.4')
        );
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

    /**
     * What the run was told to do used to be a key of its own beside the report. It is in the
     * report now — the page reads it from there, and so does anyone with `jq` — so what this asserts
     * is that the page did not keep a second copy of it.
     */
    public function testThePayloadKeepsNoSecondCopyOfTheRun(): void
    {
        $report = $this->report([])->withRun(new RunSettings('Acme app', 'acme/app', '8.4', '/home/someone/acme/composer.lock', FailOn::NONE, new Thresholds()));

        $document = (new ReportDocument($report))->toArray();

        self::assertSame(['report', 'details'], array_keys($document));
        self::assertSame('8.4', J::stringAt($document, ['report', 'run', 'target_php']));
        self::assertSame('composer.lock', J::stringAt($document, ['report', 'run', 'lock_file']));
        self::assertSame('acme/app', J::stringAt($document, ['report', 'run', 'root_package']));
        self::assertSame('Acme app', J::stringAt($document, ['report', 'run', 'project']));
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

        $noThresholds = new ReportDocument($report, new PageData($analysis, null, '8.4'));
        $noTarget = new ReportDocument($report, new PageData($analysis, new Thresholds(), null));

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
            ['1' => ['version' => '1.0.0', 'at' => $at, 'highest' => ['normalized' => '1.0.0.0', 'pretty' => '1.0.0', 'at' => $at], 'php' => null]]
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

        $document = (new ReportDocument($report))->toArray();

        self::assertSame([], J::arrayAt($document, ['details']), 'the install-time path keeps no facts');
        self::assertNotSame([], J::arrayAt($document, ['report', 'findings']));
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
