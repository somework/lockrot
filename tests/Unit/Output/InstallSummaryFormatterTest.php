<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Output\ConsoleMarkup;
use Lockrot\Output\InstallSummaryFormatter;
use Lockrot\Signal\Signal;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\Attributes\DataProvider;
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
        ], ['first note', 'second note', 'third note', 'fourth note'], 3, true);

        $lines = (new InstallSummaryFormatter())->format($report);

        self::assertSame([
            '<warning>lockrot: 2 of 3 changed packages could not be checked</warning>',
            '  note: first note',
            '  note: second note',
            '  note: third note',
            'Run composer lockrot for details.',
        ], $lines);
    }

    /** Metadata arrived for every package, only the advisory request failed: nothing is unknown, and "0 of 3" would say the opposite of the note. */
    public function testALookupThatFailedWithNothingUnknownSaysSoInsteadOfCountingZero(): void
    {
        $report = $this->report([
            $this->finding('vendor/a', '1.0.0', Verdict::OK, ''),
            $this->finding('vendor/b', '2.0.0', Verdict::OK, ''),
        ], ['security advisories unavailable from packagist.org: timeout'], 2, true);

        self::assertSame([
            '<warning>lockrot: 2 changed packages checked, one check incomplete</warning>',
            '  note: security advisories unavailable from packagist.org: timeout',
            'Run composer lockrot for details.',
        ], (new InstallSummaryFormatter())->format($report));
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
        $report = $this->report($findings, ['first note', 'second note', 'third note', 'a fourth note the block has no room for'], 12);

        $lines = (new InstallSummaryFormatter())->format($report);

        self::assertCount(InstallSummaryFormatter::MAX_LINES, $lines);
        self::assertSame('<warning>lockrot: dependency rot in 12 of 12 changed packages</warning>', $lines[0]);
        self::assertStringContainsString('vendor/p01 1.0.0', $lines[1]);
        self::assertStringContainsString('vendor/p04 1.0.0', $lines[4]);
        self::assertSame('  … and 8 more', $lines[5]);
        self::assertSame('  note: first note', $lines[6]);
        self::assertSame('  note: second note', $lines[7]);
        self::assertSame('  note: third note', $lines[8]);
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

    /**
     * The package, its version, the evidence, the chain and the notes are the lock's and the
     * repository's text. Rendered by {@see ConsoleMarkup}, which is how the block is written, each
     * prints as written, with only lockrot's own `warning` and `comment` styles, each closed on its
     * own line. The block used to reach Composer's formatter unescaped.
     *
     * @dataProvider textsThatLookLikeMarkup
     */
    #[DataProvider('textsThatLookLikeMarkup')]
    public function testTextThatLooksLikeMarkupPrintsAsWrittenAndLeavesNoStyleBehind(string $text): void
    {
        $report = $this->report(
            [$this->finding('vendor/'.$text, $text, Verdict::ABANDONED, 'marked abandoned: '.$text, ['vendor/'.$text.'-parent', 'vendor/'.$text.'-middle', 'vendor/'.$text])],
            ['note: '.$text],
            2
        );

        $lines = (new InstallSummaryFormatter())->format($report);
        $plain = array_map(static fn (string $line): string => ConsoleMarkup::render($line, false), $lines);
        $decorated = array_map(static fn (string $line): string => ConsoleMarkup::render($line, true), $lines);

        self::assertSame([
            'lockrot: dependency rot in 1 of 2 changed packages',
            '  abandoned   vendor/'.$text.' '.$text.': marked abandoned: '.$text.' (via vendor/'.$text.'-parent > vendor/'.$text.'-middle)',
            '  note: note: '.$text,
            'Run composer lockrot for details.',
        ], $plain);
        self::assertSame("\033[30;43mlockrot: dependency rot in 1 of 2 changed packages\033[39;49m", $decorated[0]);
        self::assertSame("  \033[33mabandoned   \033[39m".substr($plain[1], 14), $decorated[1]);
        self::assertSame(\array_slice($plain, 2), \array_slice($decorated, 2));
    }

    /**
     * Written raw, the block no longer passes through Composer's sanitising, so what a terminal
     * would obey — an escape sequence in a version, a line break in the evidence — is shown as an
     * escape instead of acted on.
     */
    public function testControlCharactersInTheProjectsTextAreShownRatherThanObeyed(): void
    {
        $report = $this->report([$this->finding("vendor/a\033[2J", "1.0\r", Verdict::SILENT, "last release\nlong ago")], ["a note\x07"], 1);

        $lines = array_map(static fn (string $line): string => ConsoleMarkup::render($line, false), (new InstallSummaryFormatter())->format($report));

        self::assertSame([
            'lockrot: dependency rot in 1 of 1 changed package',
            '  silent      vendor/a\\x1B[2J 1.0\\r: last release\\nlong ago',
            '  note: a note\\x07',
            'Run composer lockrot for details.',
        ], $lines);
    }

    /** @return iterable<string, array{string}> */
    public static function textsThatLookLikeMarkup(): iterable
    {
        yield 'a style tag' => ['<<fg=red>>'];
        yield 'a link tag' => ['<<href=x>>'];
        yield 'an escaped tag' => ['a\\<b'];
        yield 'a trailing backslash' => ['a\\'];
        yield 'a long run of <b' => [str_repeat('<b', 4000)];
    }

    /** Header and footer take two of the lines; with no notes, exactly eight findings fit and none is counted away. */
    public function testExactlyAsManyFindingsAsThereAreSlotsAreAllShownWithoutACountLine(): void
    {
        $slots = InstallSummaryFormatter::MAX_LINES - 2;
        $findings = [];
        for ($i = 1; $i <= $slots; ++$i) {
            $findings[] = $this->finding(\sprintf('vendor/p%02d', $i), '1.0.0', Verdict::SILENT, 'no stable release');
        }

        $lines = (new InstallSummaryFormatter())->format($this->report($findings));

        self::assertCount(InstallSummaryFormatter::MAX_LINES, $lines);
        self::assertStringContainsString('vendor/p01 1.0.0', $lines[1]);
        self::assertStringContainsString(\sprintf('vendor/p%02d 1.0.0', $slots), $lines[$slots]);
        self::assertSame('Run composer lockrot for details.', $lines[$slots + 1]);
        self::assertSame([], preg_grep('/ more$/', $lines));
    }
}
