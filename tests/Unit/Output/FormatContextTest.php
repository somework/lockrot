<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Config\LockrotConfig;
use Lockrot\Exception\ConfigException;
use Lockrot\Output\FormatContext;
use Lockrot\Tests\Support\BaselineEntries;
use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\FindingBuilder;
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
        return (new FindingBuilder())->withPackage($package)->withVerdict($verdict)->withChain([$package])->withDataDate(new \DateTimeImmutable(self::AT))->build();
    }

    private function report(Finding ...$findings): Report
    {
        return new Report(array_values($findings), [], new \DateTimeImmutable(self::AT), \count($findings), 0);
    }


    public function testAThresholdThatIsNotAValueIsRejected(): void
    {
        $this->expectException(ConfigException::class);
        FormatContext::create(null, 'dead');
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

    /** SPEC §6.2: the run-level record's level, from the gap and the active values. */
    public function testTheAdvisoriesRecordLevelFollowsTheGapAndTheActiveValues(): void
    {
        $offline = $this->report(FindingBuilder::advisoriesUnchecked('a/offline', 'offline', true));
        $skipped = $this->report(FindingBuilder::advisoriesUnchecked('a/vcs', 'not_from_composer_repository', false));

        self::assertSame(FormatContext::LEVEL_ERROR, FormatContext::create(null, 'unchecked')->advisoriesNotCheckedLevel($offline));
        self::assertSame(FormatContext::LEVEL_WARNING, FormatContext::create(null, 'high')->advisoriesNotCheckedLevel($offline));
        self::assertSame(FormatContext::LEVEL_WARNING, FormatContext::create(null, 'unchecked')->advisoriesNotCheckedLevel($skipped));
        self::assertNull(FormatContext::create(null, 'unchecked')->advisoriesNotCheckedLevel($this->report($this->finding('a/fine', Verdict::OK))));
        self::assertSame(FormatContext::LEVEL_ERROR, FormatContext::create(null, 'unchecked')->advisoriesNotCheckedLevel($this->report($this->finding('a/fine', Verdict::OK), FindingBuilder::advisoriesUnchecked('a/offline', 'offline', true))), 'a checked finding before the gap');
    }

    /** A gap of a finding that the baseline knows exempts it from `unchecked`: the gate passes, so the record is a `warning`. */
    public function testTheAdvisoriesRecordIsAWarningWhenTheBaselineKnowsTheFinding(): void
    {
        $report = $this->report(FindingBuilder::advisoriesUnchecked('a/offline', 'offline', true));
        $known = $report->withBaseline($this->comparison([['a/offline', 'stale']], $report));

        self::assertFalse(FormatContext::create(null, 'unchecked')->gateOf($known)->fails());
        self::assertSame(FormatContext::LEVEL_WARNING, FormatContext::create(null, 'unchecked')->advisoriesNotCheckedLevel($known));

        $mixed = $this->report(FindingBuilder::advisoriesUnchecked('a/offline', 'offline', true), FindingBuilder::advisoriesUnchecked('b/offline', 'offline', true));
        $oneKnown = $mixed->withBaseline($this->comparison([['a/offline', 'stale']], $mixed));
        self::assertSame(FormatContext::LEVEL_ERROR, FormatContext::create(null, 'unchecked')->advisoriesNotCheckedLevel($oneKnown), 'a known gap before a failing one');
    }

    /** SPEC §7.10: the own `lockrot/unchecked` record needs `unchecked` active, a missing check and no counted fact. */
    public function testAFindingGetsTheUncheckedRecordOnlyWhileUncheckedIsActive(): void
    {
        $offline = FindingBuilder::advisoriesUnchecked('a/offline', 'offline', true);

        self::assertTrue(FormatContext::create(null, 'unchecked')->isUncheckedRecord($offline));
        self::assertFalse(FormatContext::create(null, 'high')->isUncheckedRecord($offline));
        self::assertFalse(FormatContext::create(null, 'unchecked')->isUncheckedRecord($this->finding('a/fine', Verdict::OK)), 'no missing check');
    }

    public function testAMarkdownBudgetMakesACopyAndLeavesTheContextAsItWas(): void
    {
        $context = FormatContext::create(null, 'high');

        self::assertSame(1000, $context->withMarkdownMaxBytes(1000)->markdownMaxBytes());
        self::assertNull($context->markdownMaxBytes());
    }

    public function testTheRecordsAreTheGradedFindingsAndWithUncheckedActiveEveryOneWithAMissingCheck(): void
    {
        $report = CaseHydrator::everyCase();
        $names = static fn (array $findings): array => array_map(static fn ($finding): string => $finding instanceof Finding ? $finding->package() : '', $findings);
        $graded = $names(array_values(array_filter($report->sorted(), static fn (Finding $finding): bool => $finding->isGraded())));
        $missing = $names(array_values(array_filter($report->sorted(), static fn (Finding $finding): bool => $finding->isGraded() || $finding->checksMissing() !== [])));

        self::assertNotSame($graded, $missing);
        self::assertSame($graded, $names(FormatContext::create(null, 'critical')->recordFindings($report, false)), 'a graded finding that passes the gate still has its record');
        self::assertSame($missing, $names(FormatContext::create(null, 'critical,unchecked')->recordFindings($report, false)));
        self::assertSame($names($report->sorted()), $names(FormatContext::create(null, 'critical')->recordFindings($report, true)));
    }

    public function testAnAdvisoriesGapThatTheBaselineKnowsIsAWarningBecauseTheGatePasses(): void
    {
        $offline = $this->report(FindingBuilder::advisoriesUnchecked('a/offline', 'offline', true));
        $known = $offline->withBaseline(BaselineComparison::compare(BaselineEntries::baseline([BaselineEntries::of('a/offline', '1.0.0', Verdict::STALE, '2026-06-01')]), $offline, 'lockrot-baseline.json', []));
        $context = FormatContext::create(null, 'unchecked');

        self::assertFalse($context->gateOf($known)->fails());
        self::assertSame(FormatContext::LEVEL_WARNING, $context->advisoriesNotCheckedLevel($known));
    }

    /** I15b: the record set holds every flagged finding, every failing one, and with `unchecked` active every one with a missing check. */
    public function testTheRecordSetHoldsEveryFindingThatTheGateCanFail(): void
    {
        $report = $this->report($this->finding('a/stale', Verdict::STALE), FindingBuilder::advisoriesUnchecked('a/offline', 'offline', true), $this->finding('a/fine', Verdict::OK));
        $names = static function (array $findings): array {
            $out = [];
            foreach ($findings as $finding) {
                self::assertInstanceOf(Finding::class, $finding);
                $out[] = $finding->package();
            }

            return $out;
        };

        self::assertSame(['a/stale', 'a/offline'], $names(FormatContext::create(null, 'unchecked')->recordFindings($report, false)));
        self::assertSame(['a/stale'], $names(FormatContext::create(null, 'high')->recordFindings($report, false)));
        self::assertSame($names($report->sorted()), $names(FormatContext::create(null, 'high')->recordFindings($report, true)), 'under --all, every finding');
    }
}
