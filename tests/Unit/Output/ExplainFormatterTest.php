<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Composer\Package\Loader\ArrayLoader;
use Lockrot\Analyzer\Report;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Explain\Explanation;
use Lockrot\Output\ExplainFormatter;
use Lockrot\Signal\Signal;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use Lockrot\Version;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter;

final class ExplainFormatterTest extends TestCase
{
    /** The text as a terminal without colour shows it: tags applied, escapes resolved. */
    private function plain(Explanation $explanation): string
    {
        $plain = (new OutputFormatter(false))->format((new ExplainFormatter())->text($explanation));
        self::assertIsString($plain);

        return $plain;
    }

    /** @param list<string> $notes */
    private function report(array $notes = []): Report
    {
        return new Report([], $notes, new \DateTimeImmutable(F::NOW), 3, 0, false);
    }

    public function testAFlaggedPackageReadsVerdictSignalsDataAndBranches(): void
    {
        $s8 = new Signal(Signal::S8, Signal::LEVEL_WARN, 'branch 1.x last released 2021-06-01 (5.3 years ago); 2.x released 2.1.0 (2026-01-01)', ['branch' => '1.x', 'years' => 5.3, 'newest_release' => '2026-01-01T00:00:00+00:00']);
        $s9 = new Signal(Signal::S9, Signal::LEVEL_WARN, '2 security advisories affect 1.5.0 (CVE-2026-1, PKSA-2)', ['advisories' => [
            ['id' => 'PKSA-1', 'cve' => 'CVE-2026-1', 'severity' => 'high', 'fixed_by' => '2.1.0', 'fixed_on_branch' => false, 'reported_at' => '2026-03-01T00:00:00+00:00', 'link' => 'https://example.test/a'],
            ['id' => 'PKSA-2', 'cve' => null, 'severity' => null, 'fixed_by' => null, 'fixed_on_branch' => false],
        ]]);
        $finding = new Finding('vendor/pkg', '1.5.0', Verdict::LEFT_BEHIND, [$s8, $s9], ['root/app', 'vendor/mid', 'vendor/pkg'], null, new \DateTimeImmutable(F::NOW), null, false, ['root/app', 'root/other']);
        $metadata = F::metadata([['2.1.0', '2026-01-01T00:00:00+00:00'], ['1.5.0', '2021-06-01T00:00:00+00:00'], ['1.4.9', '2021-01-01T00:00:00+00:00']]);
        $package = F::package(['version' => '1.5.0', 'php' => '>=7.1 <8.0', 'time' => '2021-06-01T00:00:00+00:00']);
        $explanation = new Explanation($finding, F::facts($package, $metadata, F::activity(false, '2026-02-01T00:00:00+00:00')), new Thresholds(), '8.4', $this->report(['GitHub token not set']));

        $text = $this->plain($explanation);

        self::assertStringContainsString("vendor/pkg 1.5.0 — left-behind, priority high\n  via root/app > vendor/mid > vendor/pkg; also reached from root/other\n", $text, 'transitive: one step below the verdict\'s critical');
        self::assertStringContainsString("  S8 warn branch 1.x last released 2021-06-01 (5.3 years ago); 2.x released 2.1.0 (2026-01-01)\n           branch 1.x · years 5.3 · newest_release 2026-01-01T00:00:00+00:00\n", $text);
        self::assertStringContainsString("           CVE-2026-1 (PKSA-1) high · fixed by 2.1.0, not on the installed branch · reported 2026-03-01 · https://example.test/a\n", $text);
        self::assertStringContainsString("           PKSA-2 · no listed release fixes it\n", $text);
        self::assertStringContainsString("composer.lock\n  version 1.5.0 · php >=7.1 <8.0 · released 2021-06-01 · from a Composer repository\n  source https://github.com/vendor/pkg.git\n", $text, 'a `<` in the constraint survives the console formatter');
        self::assertStringContainsString("repository metadata (as of 2026-09-14)\n  3 versions listed · library · not abandoned\n", $text);
        self::assertStringContainsString("  last stable release 2.1.0 (2026-01-01)\n", $text);
        self::assertStringContainsString("    2.x        2.1.0              2026-01-01   2.1.0 (2026-01-01)\n  * 1.x        1.5.0              2021-06-01   1.5.0 (2021-06-01)\n", $text);
        self::assertStringNotContainsString('highest tag is undated', $text);
        self::assertStringContainsString("repository activity\n  GitHub vendor/pkg · not archived · last push 2026-02-01 · fetched 2026-09-14\n", $text);
        self::assertStringContainsString("thresholds: release-warn-years 3 · release-high-years 5 · push-warn-years 3 · push-high-years 5 · target PHP 8.4\nnote: GitHub token not set\n", $text);
    }

    /** The question `--explain` exists for: a package the report does not flag, and the row that says why S8 stayed quiet. */
    public function testAnUnflaggedSplitPackageSaysWhyItsBranchIsNotMeasured(): void
    {
        $finding = new Finding('vendor/pkg', '10.48.28', Verdict::OK, [], ['vendor/pkg'], null, new \DateTimeImmutable(F::NOW));
        // Through fromPackages(), as the loader builds it: an undated highest tag leaves the package's last release unknown.
        $loader = new ArrayLoader();
        $metadata = PackageMetadata::fromPackages('vendor/pkg', [
            $loader->load(['name' => 'vendor/pkg', 'version' => '13.0.0']),
            $loader->load(['name' => 'vendor/pkg', 'version' => '10.49.0']),
            $loader->load(['name' => 'vendor/pkg', 'version' => '10.13.1', 'time' => '2023-03-17T00:00:00+00:00']),
        ], new \DateTimeImmutable(F::NOW));
        $explanation = new Explanation($finding, F::facts(F::package(['version' => '10.48.28']), $metadata), new Thresholds(), '8.4', $this->report());

        $text = $this->plain($explanation);

        self::assertStringContainsString("vendor/pkg 10.48.28 — ok, priority none\n  direct requirement\n\nsignals: none\n", $text);
        self::assertStringContainsString("  last stable release unknown: the highest tag 13.0.0 is undated, so S2 does not measure the package\n", $text);
        self::assertStringContainsString("  * 10.x       10.49.0            undated      10.13.1 (2023-03-17)\n", $text);
        self::assertStringContainsString("  * the installed branch's highest tag is undated", $text);
        self::assertStringContainsString("repository activity\n  not fetched — S3 and S4 have nothing to read", $text);
    }

    public function testAPackageWithoutMetadataShowsTheNoteInsteadOfATable(): void
    {
        $finding = new Finding('vendor/pkg', '1.0.0', Verdict::UNKNOWN, [], ['vendor/pkg'], null, null, 'not from a Composer repository, not checked', true);
        $explanation = new Explanation($finding, F::facts(F::package(['fromComposerRepository' => false, 'dev' => true, 'source' => null])), new Thresholds(), '8.4', $this->report());

        $text = $this->plain($explanation);

        self::assertStringContainsString("  direct requirement · packages-dev\n  note: not from a Composer repository, not checked\n", $text);
        self::assertStringContainsString("  version 1.0.0 · no php constraint · undated · not from a Composer repository\n", $text);
        self::assertStringContainsString("repository metadata\n  none — not from a Composer repository, not checked\n", $text);
        self::assertStringNotContainsString('branch     highest tag', $text);
    }

    public function testTheBranchTableIsCappedAndAnAllowlistedPackageSaysSo(): void
    {
        $releases = [];
        for ($i = 20; $i >= 1; --$i) {
            $releases[] = ['0.0.'.$i, '2020-01-01T00:00:00+00:00'];
        }
        $finding = new Finding('vendor/pkg', '0.0.3', Verdict::FINISHED, [], ['vendor/pkg'], 'interfaces only', new \DateTimeImmutable(F::NOW));
        $explanation = new Explanation($finding, F::facts(F::package(['version' => '0.0.3']), F::metadata($releases)), new Thresholds(), '8.4', $this->report());

        $text = $this->plain($explanation);

        self::assertStringContainsString("  allowlisted: interfaces only\n", $text);
        self::assertSame(Explanation::BRANCH_ROWS, preg_match_all('/^    0\.0\.\d+ /m', $text));
        self::assertStringContainsString('  … and 5 more', $text);
    }

    public function testJsonCarriesTheEnvelopeAndTheExplanation(): void
    {
        $finding = new Finding('vendor/pkg', '1.0.0', Verdict::OK, [], ['vendor/pkg'], null, new \DateTimeImmutable(F::NOW));
        $explanation = new Explanation($finding, F::facts(F::package(), F::metadata([['1.0.0', '2026-01-01T00:00:00+00:00']])), new Thresholds(), '8.4', $this->report());

        $json = json_decode((new ExplainFormatter())->json($explanation), true);

        self::assertIsArray($json);
        self::assertSame(['version' => Version::STRING, 'schema' => 1], $json['lockrot']);
        self::assertSame('vendor/pkg', $json['package']);
        self::assertIsArray($json['finding']);
        self::assertSame('ok', $json['finding']['verdict']);
        self::assertIsArray($json['metadata']);
        self::assertIsArray($json['metadata']['branches']);
        self::assertSame([['branch' => '1.x', 'installed' => true, 'highest' => '1.0.0', 'highest_released' => '2026-01-01T00:00:00+00:00', 'newest_dated' => '1.0.0', 'newest_dated_released' => '2026-01-01T00:00:00+00:00']], $json['metadata']['branches']);
    }
}
