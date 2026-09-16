<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Output\InstallSummaryFormatter;
use Lockrot\Signal\Signal;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\TestCase;

final class InstallSummaryFormatterTest extends TestCase
{
    private const NOW = '2026-09-14T00:00:00+00:00';

    /** Words banned from every piece of lockrot output. */
    private const BANNED_WORDS = ['vulnerable', 'broken', 'insecure', 'dead'];

    /** @param list<string> $chain */
    private function finding(string $package, string $version, string $verdict, string $evidence, array $chain = []): Finding
    {
        // No signals: Finding::evidence() then falls back to the note, which keeps these cases
        // independent of the signal wording.
        return new Finding($package, $version, $verdict, [], $chain, null, null, $evidence);
    }

    /**
     * @param list<Finding> $findings
     * @param list<string>  $notes
     */
    private function report(array $findings, array $notes = [], ?int $packagesChecked = null, bool $hadNetworkFailures = false): Report
    {
        return new Report($findings, $notes, new \DateTimeImmutable(self::NOW), $packagesChecked ?? \count($findings), 0, $hadNetworkFailures);
    }

    public function testNothingFlaggedAndNothingFailedProducesNoLinesAtAll(): void
    {
        $report = $this->report([$this->finding('vendor/a', '1.0.0', Verdict::OK, '')]);

        self::assertSame([], (new InstallSummaryFormatter())->format($report));
    }

    /**
     * An exhausted budget (or an unreachable repository) leaves every affected package `unknown`,
     * which is below the flagged threshold — so without this block the install would print nothing
     * and read as clean when in fact nothing was checked.
     */
    public function testNothingFlaggedButAFailedLookupReportsTheUncheckedPackages(): void
    {
        $report = $this->report(
            [$this->finding('vendor/a', '1.0.0', Verdict::UNKNOWN, 'not checked: install-time budget exhausted')],
            ['Repository metadata unavailable for 1 package: not checked: install-time budget exhausted'],
            1,
            true
        );

        self::assertSame([
            '<warning>lockrot: 1 of 1 changed package could not be checked</warning>',
            '  note: Repository metadata unavailable for 1 package: not checked: install-time budget exhausted',
            'Run composer lockrot for details.',
        ], (new InstallSummaryFormatter())->format($report));
    }

    public function testTheUncheckedBlockCountsOnlyUnknownFindingsAndCapsItsNotes(): void
    {
        $report = $this->report([
            $this->finding('vendor/a', '1.0.0', Verdict::UNKNOWN, 'not checked: install-time budget exhausted'),
            $this->finding('vendor/b', '2.0.0', Verdict::UNKNOWN, 'not checked: install-time budget exhausted'),
            $this->finding('vendor/c', '3.0.0', Verdict::OK, ''),
        ], ['first note', 'second note', 'third note'], 3, true);

        $lines = (new InstallSummaryFormatter())->format($report);

        self::assertSame([
            '<warning>lockrot: 2 of 3 changed packages could not be checked</warning>',
            '  note: first note',
            '  note: second note',
            'Run composer lockrot for details.',
        ], $lines);
    }

    public function testTwoFlaggedPackagesRenderHeaderFindingsAndFooter(): void
    {
        $report = $this->report([
            $this->finding('vendor/a', '1.0.0', Verdict::SILENT, 'last release 2015-11-16 (10.8 years ago)'),
            $this->finding('vendor/b', '2.0.0', Verdict::ABANDONED, 'marked abandoned by its repository', ['vendor/a', 'vendor/b']),
        ], [], 5);

        self::assertSame([
            '<warning>lockrot: dependency rot in 2 of 5 changed packages</warning>',
            '  <comment>abandoned   </comment>vendor/b 2.0.0: marked abandoned by its repository (via vendor/a)',
            '  <comment>silent      </comment>vendor/a 1.0.0: last release 2015-11-16 (10.8 years ago)',
            'Run composer lockrot for details.',
        ], (new InstallSummaryFormatter())->format($report));
    }

    public function testTwelveFindingsAndNotesAreCappedAtTenLines(): void
    {
        $findings = [];
        for ($i = 1; $i <= 12; ++$i) {
            $findings[] = $this->finding(\sprintf('vendor/p%02d', $i), '1.0.0', Verdict::SILENT, 'no stable release');
        }
        $report = $this->report($findings, ['first note', 'second note', 'third note'], 12);

        $lines = (new InstallSummaryFormatter())->format($report);

        self::assertCount(InstallSummaryFormatter::MAX_LINES, $lines);
        self::assertSame('<warning>lockrot: dependency rot in 12 of 12 changed packages</warning>', $lines[0]);
        self::assertSame('  … and 7 more', $lines[6]);
        self::assertSame('  note: first note', $lines[7]);
        self::assertSame('  note: second note', $lines[8]);
        self::assertSame('Run composer lockrot for details.', $lines[9]);
    }

    public function testFlaggedPackagesKeepTheirBlockEvenWhenSomeLookupsFailed(): void
    {
        $report = $this->report([
            $this->finding('vendor/a', '1.0.0', Verdict::SILENT, 'no stable release'),
            $this->finding('vendor/b', '2.0.0', Verdict::UNKNOWN, 'not checked: install-time budget exhausted'),
        ], ['Repository metadata unavailable for 1 package: not checked: install-time budget exhausted'], 2, true);

        self::assertSame([
            '<warning>lockrot: dependency rot in 1 of 2 changed packages</warning>',
            '  <comment>silent      </comment>vendor/a 1.0.0: no stable release',
            '  note: Repository metadata unavailable for 1 package: not checked: install-time budget exhausted',
            'Run composer lockrot for details.',
        ], (new InstallSummaryFormatter())->format($report));
    }

    public function testASingleChangedPackageUsesTheSingularNoun(): void
    {
        $report = $this->report([$this->finding('vendor/a', '1.0.0', Verdict::SILENT, 'no stable release')], [], 1);

        self::assertSame(
            '<warning>lockrot: dependency rot in 1 of 1 changed package</warning>',
            (new InstallSummaryFormatter())->format($report)[0]
        );
    }

    public function testTheBlockUsesNoneOfTheBannedSeverityWords(): void
    {
        $report = $this->report([
            $this->finding('vendor/a', '1.0.0', Verdict::SILENT, 'last release 2015-11-16; last push 2015-11-16'),
            $this->finding('vendor/b', '2.0.0', Verdict::ABANDONED, 'marked abandoned by its repository', ['vendor/a', 'vendor/b']),
            $this->finding('vendor/c', 'dev-main', Verdict::PINNED, 'installed version is a branch snapshot'),
        ], ['GitHub token not set: repository activity checked for 0 candidate packages'], 3);

        $text = strtolower(implode("\n", (new InstallSummaryFormatter())->format($report)));

        foreach (self::BANNED_WORDS as $word) {
            self::assertStringNotContainsString($word, $text);
        }
    }

    /** The block is read in passing: the chain stays, the other parents and what a package pulls in (S7) do not. */
    public function testAFindingLineKeepsTheChainButNeitherTheOtherParentsNorS7(): void
    {
        $s7 = new Signal(Signal::S7, Signal::LEVEL_INFO, 'pulls in 1 flagged package: vendor/b (abandoned)', ['flagged' => 1, 'packages' => []]);
        $report = $this->report([
            new Finding('vendor/b', '2.0.0', Verdict::ABANDONED, [], ['vendor/a', 'vendor/b'], null, null, 'marked abandoned by its repository', false, ['vendor/a', 'vendor/c']),
            new Finding('vendor/a', '1.0.0', Verdict::STALE, [new Signal('S2', 'warn', 'last release 2022-05-20 (4.3 years ago)'), $s7], ['vendor/a'], null, null, null, false, ['vendor/a']),
        ], [], 3);

        $lines = (new InstallSummaryFormatter())->format($report);
        self::assertSame('  <comment>abandoned   </comment>vendor/b 2.0.0: marked abandoned by its repository (via vendor/a)', $lines[1]);
        self::assertSame('  <comment>stale       </comment>vendor/a 1.0.0: last release 2022-05-20 (4.3 years ago)', $lines[2]);
    }
}
