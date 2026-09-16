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
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use Lockrot\Version;
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

    public function testUnknownContextCarriesNothing(): void
    {
        $context = FormatContext::unknown();

        self::assertNull($context->lockPath());
        self::assertSame(LockrotConfig::FAIL_ON_NONE, $context->failOn());
        self::assertSame(Version::STRING, $context->toolVersion());
        self::assertSame(FormatContext::DEFAULT_WIDTH, $context->terminalWidth());
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
