<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Config\Gate;
use Lockrot\Config\LockrotConfig;
use Lockrot\Exception\ConfigException;
use Lockrot\Output\FormatContext;
use Lockrot\Tests\Support\BaselineEntries;
use Lockrot\Tests\Support\FindingBuilder;
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
        return (new FindingBuilder())->withPackage($package)->withVerdict($verdict)->withChain([$package])->withDataDate(new \DateTimeImmutable(self::AT))->build();
    }

    private function report(Finding ...$findings): Report
    {
        return new Report(array_values($findings), [], new \DateTimeImmutable(self::AT), \count($findings), 0);
    }

    /** @param list<array{0: string, 1: string}> $entries package, verdict */
    private function comparison(array $entries, Report $report): BaselineComparison
    {
        $rows = [];
        foreach ($entries as [$package, $verdict]) {
            $rows[] = BaselineEntries::of($package, '1.0.0', $verdict, '2026-01-15');
        }
        $names = [];
        foreach ($report->findings() as $finding) {
            $names[] = $finding->package();
        }

        return BaselineComparison::compare(BaselineEntries::baseline($rows, self::AT), $report, 'lockrot-baseline.json', $names);
    }

    /** docs/ci.md#how-each-format-marks-a-finding: the level follows the finding's gate standing. */
    public function testAFindingThatFailsTheGateIsAnError(): void
    {
        $report = $this->report($this->finding('a/gone', Verdict::ABANDONED));
        $gate = Gate::decide($report, FailOn::parse('high'), false, Gate::MODE_CHECK);

        self::assertSame(FormatContext::LEVEL_ERROR, FormatContext::create(null, 'high')->levelOf($report->findings()[0], $gate->standingOf('a/gone')));
    }

    public function testAFlaggedFindingThatPassesIsAWarningAndAnUnflaggedOneANote(): void
    {
        $report = $this->report($this->finding('a/stale', Verdict::STALE), $this->finding('a/fine', Verdict::OK));
        $gate = Gate::decide($report, FailOn::parse('critical'), false, Gate::MODE_CHECK);
        $context = FormatContext::create(null, 'critical');

        self::assertSame(FormatContext::LEVEL_WARNING, $context->levelOf($report->findings()[0], $gate->standingOf('a/stale')));
        self::assertSame(FormatContext::LEVEL_NOTE, $context->levelOf($this->finding('a/fine', Verdict::OK), $gate->standingOf('a/fine')));
        self::assertSame(FormatContext::LEVEL_WARNING, $context->levelOf($report->findings()[0], null), 'without a gate');
    }

    public function testAThresholdThatIsNotAValueIsRejected(): void
    {
        $this->expectException(ConfigException::class);
        FormatContext::create(null, 'dead');
    }

    public function testAFindingTheBaselineKnowsIsANote(): void
    {
        $report = $this->report($this->finding('a/stale', Verdict::STALE));
        $compared = $report->withBaseline($this->comparison([['a/stale', Verdict::STALE]], $report));
        $gate = Gate::decide($compared, FailOn::parse('stale'), false, Gate::MODE_CHECK);

        self::assertSame(FormatContext::LEVEL_NOTE, FormatContext::create(null, 'stale')->levelOf($compared->findings()[0], $gate->standingOf('a/stale'), $compared->baseline()));
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
    }

    /** SPEC §7.10: the own `lockrot/unchecked` record needs `unchecked` active, a missing check and no counted fact. */
    public function testAFindingGetsTheUncheckedRecordOnlyWhileUncheckedIsActive(): void
    {
        $offline = FindingBuilder::advisoriesUnchecked('a/offline', 'offline', true);

        self::assertTrue(FormatContext::create(null, 'unchecked')->isUncheckedRecord($offline));
        self::assertFalse(FormatContext::create(null, 'high')->isUncheckedRecord($offline));
        self::assertFalse(FormatContext::create(null, 'unchecked')->isUncheckedRecord($this->finding('a/fine', Verdict::OK)), 'no missing check');
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
        self::assertSame($names($report->findings()), $names(FormatContext::create(null, 'high')->recordFindings($report, true)), 'under --all, every finding');
    }
}
