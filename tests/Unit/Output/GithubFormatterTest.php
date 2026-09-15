<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Baseline\Baseline;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Baseline\BaselineEntry;
use Lockrot\Config\LockrotConfig;
use Lockrot\Exception\ConfigException;
use Lockrot\Output\FormatContext;
use Lockrot\Output\Formatters;
use Lockrot\Output\GithubFormatter;
use Lockrot\Output\TableFormatter;
use Lockrot\Signal\Signal;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\TestCase;

final class GithubFormatterTest extends TestCase
{
    private const AT = '2026-09-14T06:00:00+00:00';

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            if (is_file($dir.'/composer.lock')) {
                unlink($dir.'/composer.lock');
            }
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
        $this->tempDirs = [];
    }

    /**
     * A two-package lock whose line numbers are fixed by the literal below: acme/abandoned is on
     * line 4, acme/silent on line 8.
     */
    private function lockPath(): string
    {
        $dir = sys_get_temp_dir().'/lockrot-github-'.uniqid('', true);
        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create temp dir: '.$dir);
        }
        $this->tempDirs[] = $dir;
        file_put_contents($dir.'/composer.lock', <<<'JSON'
            {
                "packages": [
                    {
                        "name": "acme/abandoned",
                        "version": "1.0.0"
                    },
                    {
                        "name": "acme/silent",
                        "version": "2.0.8"
                    }
                ],
                "packages-dev": []
            }
            JSON);

        return $dir.'/composer.lock';
    }

    private function report(): Report
    {
        $at = new \DateTimeImmutable(self::AT);

        return new Report([
            new Finding('acme/abandoned', '1.0.0', Verdict::ABANDONED, [new Signal('S1', 'high', 'flagged abandoned by its repository')], ['acme/abandoned'], null, $at),
            new Finding('acme/silent', '2.0.8', Verdict::SILENT, [new Signal('S2', 'high', 'last release 2015-11-16 (10.8 years ago)')], ['a/parent', 'acme/silent'], null, $at),
            new Finding('acme/absent', '3.0.0', Verdict::STALE, [new Signal('S2', 'warn', 'last release 2022-05-20 (4.3 years ago)')], ['acme/absent'], null, $at),
            new Finding('acme/fine', '4.0.0', Verdict::OK, [], ['acme/fine'], null, $at),
        ], ['GitHub token not set: repository activity checked only for 2 candidate packages'], $at, 4, 0, false);
    }

    private function formatter(string $failOn, ?string $lockPath): GithubFormatter
    {
        return new GithubFormatter(FormatContext::create($lockPath, $failOn));
    }

    public function testLevelMappingAtTheFailOnBoundary(): void
    {
        $out = $this->formatter(Verdict::SILENT, $this->lockPath())->format($this->report(), true);
        $lines = explode("\n", trim($out));

        self::assertSame('::error file=composer.lock,line=4,title=lockrot%3A abandoned::acme/abandoned 1.0.0: flagged abandoned by its repository', $lines[0]);
        self::assertSame('::error file=composer.lock,line=8,title=lockrot%3A silent::acme/silent 2.0.8: last release 2015-11-16 (10.8 years ago) (via a/parent)', $lines[1]);
        self::assertSame('::warning file=composer.lock,title=lockrot%3A stale::acme/absent 3.0.0: last release 2022-05-20 (4.3 years ago)', $lines[2]);
        self::assertSame('::notice file=composer.lock,title=lockrot%3A ok::acme/fine 4.0.0', $lines[3]);
    }

    public function testFailOnNoneNeverProducesAnError(): void
    {
        $out = $this->formatter(LockrotConfig::FAIL_ON_NONE, $this->lockPath())->format($this->report());
        self::assertStringNotContainsString('::error ', $out);
        self::assertStringContainsString('::warning file=composer.lock,line=4,title=lockrot%3A abandoned::', $out);
        self::assertStringContainsString('::warning file=composer.lock,line=8,title=lockrot%3A silent::', $out);
    }

    public function testUnflaggedRowsAreOmittedWithoutShowAll(): void
    {
        $out = $this->formatter(Verdict::SILENT, $this->lockPath())->format($this->report());
        self::assertStringNotContainsString('acme/fine', $out);
        self::assertStringContainsString('acme/absent', $out);
    }

    public function testNotesAndSummary(): void
    {
        $out = $this->formatter(Verdict::SILENT, $this->lockPath())->format($this->report());
        $lines = explode("\n", trim($out));

        self::assertSame('::notice title=lockrot::GitHub token not set: repository activity checked only for 2 candidate packages', $lines[\count($lines) - 2]);
        self::assertSame(
            '4 packages checked · abandoned 1 · silent 1 · pinned 0 · old-promise 0 · stale 1 · unknown 0 · finished 0 · ok 1',
            $lines[\count($lines) - 1]
        );
    }

    /** The summary line must not drift from the table format's own summary. */
    public function testSummaryLineMatchesTheTableFormatter(): void
    {
        $github = explode("\n", trim($this->formatter(LockrotConfig::FAIL_ON_NONE, null)->format($this->report())));
        $table = explode("\n", trim((new TableFormatter())->format($this->report())));
        $summary = array_values(array_filter($table, static fn (string $line): bool => strpos($line, ' packages checked') !== false));

        self::assertSame($summary[0], $github[\count($github) - 1]);
    }

    public function testEmptyReportIsSummaryOnly(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([], [], $at, 0, 0, false);
        $out = $this->formatter(Verdict::SILENT, $this->lockPath())->format($report);

        self::assertSame(
            "0 packages checked · abandoned 0 · silent 0 · pinned 0 · old-promise 0 · stale 0 · unknown 0 · finished 0 · ok 0\n",
            $out
        );
    }

    public function testEscaping(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([
            new Finding('acme/abandoned', '1.0.0', Verdict::STALE, [new Signal('S2', 'warn', "100% behind, see http://x:8080, line 1\nline 2")], ['acme/abandoned'], null, $at),
        ], ["note with 100% and a comma, and a colon: here\nand a second line"], $at, 1, 0, false);

        $out = $this->formatter(LockrotConfig::FAIL_ON_NONE, $this->lockPath())->format($report);
        $lines = explode("\n", trim($out));

        // Message values escape % CR LF only; ":" and "," stay literal there.
        self::assertSame(
            '::warning file=composer.lock,line=4,title=lockrot%3A stale::acme/abandoned 1.0.0: 100%25 behind, see http://x:8080, line 1%0Aline 2',
            $lines[0]
        );
        self::assertSame(
            '::notice title=lockrot::note with 100%25 and a comma, and a colon: here%0Aand a second line',
            $lines[1]
        );
    }

    public function testAllowlistReasonIsCarriedAsEvidence(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([
            new Finding('acme/abandoned', '1.0.0', Verdict::FINISHED, [], ['acme/abandoned'], 'interfaces', $at),
        ], [], $at, 1, 0, false);

        self::assertStringContainsString(
            '::notice file=composer.lock,line=4,title=lockrot%3A finished::acme/abandoned 1.0.0: allowlisted: interfaces',
            $this->formatter(Verdict::SILENT, $this->lockPath())->format($report, true)
        );
    }

    public function testWithoutALockPathNoLineIsEmitted(): void
    {
        $out = $this->formatter(Verdict::SILENT, null)->format($this->report());
        self::assertStringNotContainsString('line=', $out);
        self::assertStringContainsString('file=composer.lock,title=lockrot%3A abandoned::', $out);
    }

    public function testWordingAvoidsBannedTerms(): void
    {
        $out = strtolower($this->formatter(Verdict::SILENT, $this->lockPath())->format($this->report(), true));
        foreach (['vulnerable', 'broken', 'insecure', 'dead'] as $banned) {
            self::assertStringNotContainsString($banned, $out);
        }
    }

    /** A finding the baseline already carries is annotated as a notice, so the colour still matches the exit code. */
    public function testABaselinedFindingIsAnnotatedAsANotice(): void
    {
        $report = $this->report();
        $names = [];
        foreach ($report->findings() as $finding) {
            $names[] = $finding->package();
        }
        $baseline = Baseline::of([
            new BaselineEntry('acme/abandoned', '1.0.0', Verdict::ABANDONED, '2026-01-15'),
            new BaselineEntry('acme/silent', '2.0.8', Verdict::STALE, '2026-01-15'),
        ], self::AT);
        $withBaseline = $report->withBaseline(BaselineComparison::compare($baseline, $report, 'lockrot-baseline.json', $names));

        $lines = explode("\n", trim($this->formatter(Verdict::SILENT, $this->lockPath())->format($withBaseline)));

        self::assertStringStartsWith('::notice file=composer.lock,line=4,', $lines[0], 'known: demoted to a notice');
        self::assertStringStartsWith('::error file=composer.lock,line=8,', $lines[1], 'worsened: still an error');
        self::assertStringStartsWith('::warning file=composer.lock,', $lines[2], 'new but below fail-on: a warning');
    }

    public function testFactory(): void
    {
        self::assertInstanceOf(
            GithubFormatter::class,
            Formatters::for('github', FormatContext::create(null, LockrotConfig::FAIL_ON_NONE))
        );
    }

    /** A context does not make an unknown format acceptable; LockrotConfig rejects it first. */
    public function testFactoryStillRejectsAnUnknownFormat(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Unknown output format "xml"');
        Formatters::for('xml', FormatContext::create(null, LockrotConfig::FAIL_ON_NONE));
    }
}
