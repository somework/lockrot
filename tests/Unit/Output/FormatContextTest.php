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
use Lockrot\Signal\Signal;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use Lockrot\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormatContextTest extends TestCase
{
    private const AT = '2026-09-14T00:00:00+00:00';

    private function finding(string $package, string $verdict): Finding
    {
        return new Finding($package, '1.0.0', $verdict, [], [$package], null, new \DateTimeImmutable(self::AT));
    }

    private function report(Finding ...$findings): Report
    {
        return new Report(array_values($findings), [], new \DateTimeImmutable(self::AT), \count($findings), 0, false);
    }

    /** @param list<array{0: string, 1: string}> $entries package, verdict */
    private function comparison(array $entries, Report $report): BaselineComparison
    {
        $rows = [];
        foreach ($entries as [$package, $verdict]) {
            $rows[] = new BaselineEntry($package, '1.0.0', $verdict, '2026-01-15');
        }
        $names = [];
        foreach ($report->findings() as $finding) {
            $names[] = $finding->package();
        }

        return BaselineComparison::compare(Baseline::of($rows, self::AT), $report, 'lockrot-baseline.json', $names);
    }

    public function testDefaultsWithoutABaseline(): void
    {
        $context = FormatContext::create(null, Verdict::SILENT, Version::STRING);

        self::assertSame(FormatContext::LEVEL_ERROR, $context->levelOf($this->finding('a/b', Verdict::ABANDONED)));
        self::assertSame(FormatContext::LEVEL_ERROR, $context->levelOf($this->finding('a/b', Verdict::SILENT)));
        self::assertSame(FormatContext::LEVEL_WARNING, $context->levelOf($this->finding('a/b', Verdict::STALE)));
        self::assertSame(FormatContext::LEVEL_NOTE, $context->levelOf($this->finding('a/b', Verdict::OK)));
    }

    /** The annotation level follows a priority threshold the way it follows a verdict one. */
    public function testAPriorityThresholdDrawsTheErrorLineByPriority(): void
    {
        $context = FormatContext::create(null, 'high', Version::STRING);
        $directAbandoned = $this->finding('a/direct', Verdict::ABANDONED);
        $transitiveStale = new Finding('a/deep', '1.0.0', Verdict::STALE, [], ['a/root', 'a/deep'], null, new \DateTimeImmutable(self::AT));

        self::assertSame('high', $context->failOn());
        self::assertSame(FormatContext::LEVEL_ERROR, $context->levelOf($directAbandoned));
        self::assertSame(FormatContext::LEVEL_WARNING, $context->levelOf($transitiveStale));
        self::assertSame(FormatContext::LEVEL_NOTE, $context->levelOf($this->finding('a/b', Verdict::OK)));
        self::assertSame(FormatContext::LEVEL_NOTE, $context->levelOf($directAbandoned, $this->comparison([['a/direct', Verdict::ABANDONED]], $this->report($directAbandoned))), 'accepted stays a note');
    }

    public function testAThresholdThatIsNeitherAVerdictNorAPriorityIsRejected(): void
    {
        $this->expectException(ConfigException::class);
        FormatContext::create(null, 'dead', Version::STRING);
    }

    public function testFailOnNoneNeverProducesAnError(): void
    {
        $context = FormatContext::create(null, LockrotConfig::FAIL_ON_NONE, Version::STRING);

        self::assertSame(FormatContext::LEVEL_WARNING, $context->levelOf($this->finding('a/b', Verdict::ABANDONED)));
    }

    public function testABaselinedFindingIsDemotedToANote(): void
    {
        $context = FormatContext::create(null, Verdict::SILENT, Version::STRING);
        $finding = $this->finding('a/b', Verdict::ABANDONED);
        $report = $this->report($finding);

        self::assertSame(
            FormatContext::LEVEL_NOTE,
            $context->levelOf($finding, $this->comparison([['a/b', Verdict::ABANDONED]], $report))
        );
    }

    public function testANewFindingKeepsTheNormalMapping(): void
    {
        $context = FormatContext::create(null, Verdict::SILENT, Version::STRING);
        $finding = $this->finding('a/new', Verdict::ABANDONED);
        $report = $this->report($finding);

        self::assertSame(
            FormatContext::LEVEL_ERROR,
            $context->levelOf($finding, $this->comparison([['a/other', Verdict::ABANDONED]], $report))
        );
    }

    public function testAWorsenedFindingKeepsTheNormalMapping(): void
    {
        $context = FormatContext::create(null, Verdict::SILENT, Version::STRING);
        $finding = $this->finding('a/b', Verdict::ABANDONED);
        $report = $this->report($finding);

        self::assertSame(
            FormatContext::LEVEL_ERROR,
            $context->levelOf($finding, $this->comparison([['a/b', Verdict::STALE]], $report))
        );
    }

    /**
     * `unchecked` reads S10, not the verdict, so a row only `--all` shows becomes an error when its
     * check did not run — docs/compatibility.md states the mapping in this order: known first, then
     * the threshold, then flagged.
     */
    public function testUnderUncheckedAnUnflaggedFindingWhoseCheckDidNotRunIsAnError(): void
    {
        $context = FormatContext::create(null, FailOn::UNCHECKED, Version::STRING);
        $unchecked = new Finding('a/b', '1.0.0', Verdict::OK, [new Signal(Signal::S10, Signal::LEVEL_INFO, 'not checked')], ['a/b'], null, new \DateTimeImmutable(self::AT));

        self::assertSame(FormatContext::LEVEL_ERROR, $context->levelOf($unchecked));
        self::assertSame(FormatContext::LEVEL_WARNING, $context->levelOf($this->finding('a/c', Verdict::ABANDONED)), 'flagged without S10');
        self::assertSame(FormatContext::LEVEL_NOTE, $context->levelOf($this->finding('a/d', Verdict::OK)));
    }

    /**
     * The mapping's first rule wins: a finding the baseline already accepted is a note even when
     * `--fail-on=unchecked` would otherwise make it an error for carrying S10.
     */
    public function testUnderUncheckedAFindingTheBaselineKnowsStaysANote(): void
    {
        $context = FormatContext::create(null, FailOn::UNCHECKED, Version::STRING);
        $unchecked = new Finding('a/b', '1.0.0', Verdict::ABANDONED, [new Signal(Signal::S10, Signal::LEVEL_INFO, 'not checked')], ['a/b'], null, new \DateTimeImmutable(self::AT));

        self::assertSame(FormatContext::LEVEL_ERROR, $context->levelOf($unchecked), 'without the baseline');
        self::assertSame(FormatContext::LEVEL_NOTE, $context->levelOf($unchecked, $this->comparison([['a/b', Verdict::ABANDONED]], $this->report($unchecked))));
    }

    public function testUnknownContextCarriesNothing(): void
    {
        $context = FormatContext::unknown();

        self::assertNull($context->lockPath());
        self::assertSame(LockrotConfig::FAIL_ON_NONE, $context->failOn());
        self::assertSame(Version::STRING, $context->toolVersion());
        self::assertSame(FormatContext::DEFAULT_WIDTH, $context->terminalWidth());
    }

    /**
     * Lock path, project directory, the name, the directory the name is relative to.
     *
     * @return iterable<string, array{0: null|string, 1: null|string, 2: string, 3: null|string}>
     */
    public static function lockNames(): iterable
    {
        yield 'no lock at all: the name every lock has by default' => [null, '/p', 'composer.lock', null];
        yield 'the default lock in the project directory' => ['/p/composer.lock', '/p', 'composer.lock', '/p'];
        yield 'COMPOSER=alt.json' => ['/p/alt.lock', '/p', 'alt.lock', '/p'];
        yield 'COMPOSER=app/alt.json: the directory stays in the name' => ['/p/app/alt.lock', '/p', 'app/alt.lock', '/p'];
        yield 'a project directory spelled with a trailing separator' => ['/p/app/alt.lock', '/p/', 'app/alt.lock', '/p/'];
        yield 'the filesystem root as the project directory' => ['/app/alt.lock', '/', 'app/alt.lock', '/'];
        yield 'a drive root as the project directory' => ['C:\\app\\alt.lock', 'C:\\', 'app/alt.lock', 'C:\\'];
        yield 'dot segments fold before the comparison' => ['/p/app/../alt.lock', '/p', 'alt.lock', '/p'];
        yield 'Windows separators' => ['C:\\p\\app\\alt.lock', 'C:\\p', 'app/alt.lock', 'C:\\p'];
        yield 'a lock outside the project directory: its file name, beside it' => ['/elsewhere/alt.lock', '/p', 'alt.lock', '/elsewhere'];
        yield 'a sibling directory sharing the prefix is outside' => ['/project-b/alt.lock', '/project', 'alt.lock', '/project-b'];
        yield 'no project directory: the file name, beside it' => ['/p/app/alt.lock', null, 'alt.lock', '/p/app'];
    }

    /**
     * The annotation formats name the analysed lock the way the checkout does — relative to the
     * project directory — so `COMPOSER=alt.json` annotates alt.lock and the default stays
     * `composer.lock`.
     *
     * @dataProvider lockNames
     */
    #[DataProvider('lockNames')]
    public function testTheLockIsNamedRelativeToTheProjectDirectory(?string $lockPath, ?string $projectDirectory, string $name, ?string $directory): void
    {
        $context = FormatContext::create($lockPath, LockrotConfig::FAIL_ON_NONE, Version::STRING, FormatContext::DEFAULT_WIDTH, $projectDirectory);

        self::assertSame($name, $context->lockName());
        self::assertSame($directory, $context->lockDirectory());
        self::assertSame($lockPath, $context->lockPath(), 'the path the line index reads is untouched');
    }

    public function testTheUnknownContextNamesTheDefaultLock(): void
    {
        self::assertSame('composer.lock', FormatContext::unknown()->lockName());
        self::assertNull(FormatContext::unknown()->lockDirectory());
    }

    public function testTheTerminalWidthDefaultsToOneHundredAndTwenty(): void
    {
        self::assertSame(120, FormatContext::DEFAULT_WIDTH);
        self::assertSame(
            FormatContext::DEFAULT_WIDTH,
            FormatContext::create(null, LockrotConfig::FAIL_ON_NONE, Version::STRING)->terminalWidth()
        );
    }

    public function testTheTerminalWidthIsCarried(): void
    {
        self::assertSame(
            72,
            FormatContext::create(null, LockrotConfig::FAIL_ON_NONE, Version::STRING, 72)->terminalWidth()
        );
    }

    public function testATerminalTooNarrowToRenderIntoIsClampedToTheMinimum(): void
    {
        self::assertSame(40, FormatContext::MIN_WIDTH);
        self::assertSame(
            FormatContext::MIN_WIDTH,
            FormatContext::create(null, LockrotConfig::FAIL_ON_NONE, Version::STRING, 3)->terminalWidth()
        );
    }
}
