<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Analyzer;

use Lockrot\Allowlist\AllowlistEntry;
use Lockrot\Analyzer\Libyears;
use Lockrot\Analyzer\LibyearsMeasurement;
use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\Report2Root;
use Lockrot\Baseline\Baseline;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Baseline\BaselineEntry;
use Lockrot\Config\Gate;
use Lockrot\Data\Advisory\Advisory;
use Lockrot\Data\Advisory\AdvisoryIgnoreMatch;
use Lockrot\Data\Advisory\IgnoredAdvisory;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\FindingDetails;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\Score;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\TestCase;

/** The root blocks that report-2 counts from its findings. */
final class Report2RootTest extends TestCase
{
    private const AT = '2026-09-14T00:00:00+00:00';

    public function testTheGateCountsTheReachingTheFailingAndTheBaselineExemptions(): void
    {
        $known = (new FindingBuilder())->withPackage('vendor/known')->withVerdict(Verdict::ABANDONED)->withChain(['vendor/known'])->build();
        $new = (new FindingBuilder())->withPackage('vendor/new')->withVerdict(Verdict::ABANDONED)->withChain(['vendor/new'])->build();
        $fine = (new FindingBuilder())->withPackage('vendor/fine')->withChain(['vendor/fine'])->build();
        $report = new Report([$known, $new, $fine], [], new \DateTimeImmutable(self::AT), 3, 0);
        $baseline = Baseline::of([new BaselineEntry('vendor/known', '1.0.0', Verdict::ABANDONED, '2026-01-15')], self::AT);
        $compared = $report->withBaseline(BaselineComparison::compare($baseline, $report, 'lockrot-baseline.json', ['vendor/known', 'vendor/new', 'vendor/fine']));

        $check = Report2Root::toGateArray(Gate::decide($compared, FailOn::fromString('low'), false, Gate::MODE_CHECK), Gate::MODE_CHECK);
        $generate = Report2Root::toGateArray(Gate::decide($compared, FailOn::fromString('low'), false, Gate::MODE_GENERATE_BASELINE), Gate::MODE_GENERATE_BASELINE);

        self::assertSame(['fails' => true, 'tripped_by' => ['fail_on'], 'fail_on_applied' => true, 'reaching' => 2, 'failing' => 1, 'exempt' => ['baseline' => 1]], $check);
        self::assertSame(['fails' => false, 'tripped_by' => [], 'fail_on_applied' => false, 'reaching' => 2, 'failing' => 0, 'exempt' => ['baseline' => 1]], $generate);
    }

    public function testWithoutAGateNothingReachesAndTheModeSaysWhetherFailOnApplies(): void
    {
        self::assertSame(['fails' => false, 'tripped_by' => [], 'fail_on_applied' => true, 'reaching' => 0, 'failing' => 0, 'exempt' => ['baseline' => 0]], Report2Root::toGateArray(null, Gate::MODE_CHECK));
        self::assertFalse(Report2Root::toGateArray(null, Gate::MODE_GENERATE_BASELINE)['fail_on_applied']);
    }

    public function testSecuritySumsTheVulnerableFindingsOnly(): void
    {
        $findings = [
            self::vulnerable('acme/a', [['critical', 'update'], ['medium', 'update'], ['medium', 'update']]),
            self::vulnerable('acme/b', [['high', 'update'], ['medium', 'unknown'], ['low', 'update'], ['low', 'update'], ['low', 'update']], 2),
            self::vulnerable('acme/c', [['unrated', 'update']]),
            self::withDetails((new FindingBuilder())->withPackage('acme/d')->build(), 'complete', 1),
        ];

        $security = Report2Root::toSecurityArray($findings);

        self::assertSame('complete', $security['check']);
        self::assertSame(['vulnerable' => 3, 'unchecked' => 0, 'ignored' => 2, 'clear' => 1], $security['packages']);
        self::assertSame(['counted' => 9, 'ignored' => 3], $security['advisories']);
        self::assertSame(['critical' => 1, 'high' => 1, 'medium' => 3, 'unrated' => 1, 'low' => 3], $security['severities']);
        self::assertSame(['update' => 2, 'upgrade' => 0, 'raise-php' => 0, 'unknown' => 1, 'blocked' => 0, 'none' => 0], $security['fixes']);
        self::assertSame(['acme/a', 'acme/c'], $security['update_now']);
        self::assertSame(['composer', 'update', 'acme/a', 'acme/c'], $security['update_now_command']);
        self::assertSame(1, $security['fix_unknown']);
    }

    public function testSecurityWithMixedChecksIsPartialAndWithNoFindingComplete(): void
    {
        $finding = fn (string $check): Finding => self::withDetails((new FindingBuilder())->build(), $check, 0);

        self::assertSame('partial', Report2Root::toSecurityArray([$finding('complete'), $finding('not_run')])['check']);
        self::assertSame('not_run', Report2Root::toSecurityArray([$finding('not_run')])['check']);
        self::assertSame(['vulnerable' => 0, 'unchecked' => 1, 'ignored' => 0, 'clear' => 0], Report2Root::toSecurityArray([$finding('not_run')])['packages']);
        self::assertSame('complete', Report2Root::toSecurityArray([])['check']);
        self::assertNull(Report2Root::toSecurityArray([])['update_now_command']);
    }

    /** A severity outside the display order counts from zero after the listed ones. */
    public function testSecurityCountsASeverityItDoesNotListFromZero(): void
    {
        $security = Report2Root::toSecurityArray([self::vulnerable('acme/a', [['acme:severe', 'upgrade'], ['acme:severe', 'upgrade']])]);

        self::assertSame(['critical' => 0, 'high' => 0, 'medium' => 0, 'unrated' => 0, 'low' => 0, 'acme:severe' => 2], $security['severities']);
        self::assertSame(2, JsonPath::intAt($security, ['advisories', 'counted']));
        self::assertSame([], $security['update_now']);
    }

    /** The findings cover an accepted flag in a graded finding and in a finished one. */
    public function testFlagsCountCarryingLeadingAcceptedAndGrades(): void
    {
        $s = static fn (string $id, string $level): Signal => new Signal($id, $level, $id);
        $entry = static fn (string $flag): AllowlistEntry => new AllowlistEntry('vendor/pkg', null, 'kept', null, AllowlistEntry::BY_PROJECT, [$flag]);
        $finding = static fn (FlagSet $flags): Finding => (new FindingBuilder())->withFlags($flags)->build();
        $findings = [
            $finding(FlagSet::fromSignals([$s('S2', 'high'), $s('S4', 'high'), $s('S6', 'warn')], $entry('pinned'), [])),
            $finding(FlagSet::fromSignals([$s('S5', 'warn'), $s('S2', 'warn')], $entry('stale'), [])),
            $finding(FlagSet::fromSignals([$s('S5', 'warn')], null, [Score::advisory('PKSA-a', 'high', 'update')])),
            $finding(FlagSet::fromSignals([$s('S2', 'warn')], $entry('stale'), [])),
        ];

        $flags = Report2Root::toFlagsArray($findings);

        self::assertSame(['carrying' => 1, 'leading' => 1, 'accepted' => ['all' => 0, 'in_graded' => 0], 'by_verdict' => ['critical' => 1, 'high' => 0, 'medium' => 0, 'low' => 0]], $flags['silent']);
        self::assertSame(['carrying' => 2, 'leading' => 2, 'accepted' => ['all' => 0, 'in_graded' => 0], 'by_verdict' => ['critical' => 1, 'high' => 1, 'medium' => 0, 'low' => 0]], $flags['old-promise']);
        self::assertSame(['carrying' => 0, 'leading' => 0, 'accepted' => ['all' => 1, 'in_graded' => 1], 'by_verdict' => ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0]], $flags['pinned']);
        self::assertSame(['all' => 2, 'in_graded' => 1], $flags['stale']['accepted']);
        self::assertSame(['carrying' => 1, 'leading' => null, 'accepted' => null, 'by_verdict' => ['critical' => 1, 'high' => 0, 'medium' => 0, 'low' => 0]], $flags['vulnerable']);
        self::assertSame(['abandoned', 'silent', 'pinned', 'left-behind', 'old-promise', 'stale', 'vulnerable'], array_keys($flags));
    }

    public function testAbandonedCountsTheReplacementsAndTheSuggestions(): void
    {
        $abandoned = static fn (?string $replacement, ?string $reason = null): Finding => (new FindingBuilder())->withPackage('acme/old')->withVerdict(Verdict::ABANDONED)->withChain(['acme/old'])
            ->withSignals([new Signal('S1', 'high', 'marked abandoned', ['replacement' => $replacement])])->withAllowlistReason($reason)->build();
        $findings = [
            $abandoned('acme/new'),
            $abandoned('Symfony'),
            $abandoned('acme/old'),
            $abandoned(null),
            $abandoned('acme/accepted', 'kept'),
            (new FindingBuilder())->withVerdict(Verdict::STALE)->build(),
        ];

        self::assertSame(['total' => 4, 'with_replacement' => 1, 'with_suggestion' => 2], Report2Root::toAbandonedArray($findings));
        self::assertSame(['total' => 0, 'with_replacement' => 0, 'with_suggestion' => 0], Report2Root::toAbandonedArray([]));
    }

    public function testPrioritiesCountTheGradesAndNone(): void
    {
        $findings = [
            (new FindingBuilder())->withVerdict(Verdict::OLD_PROMISE)->build(),
            (new FindingBuilder())->withVerdict(Verdict::ABANDONED)->build(),
            (new FindingBuilder())->withVerdict(Verdict::OLD_PROMISE)->build(),
            (new FindingBuilder())->build(),
        ];

        self::assertSame(['critical' => 1, 'high' => 2, 'medium' => 0, 'low' => 0, 'none' => 1], Report2Root::toPrioritiesArray($findings));
    }

    public function testLibyearsSumsTheWrittenValuesToTwoDecimals(): void
    {
        $finding = static fn (bool $direct, ?float $years): Finding => (new FindingBuilder())->withChain($direct ? ['vendor/pkg'] : ['vendor/root', 'vendor/pkg'])
            ->withLibyears($years === null ? LibyearsMeasurement::unmeasured(Libyears::NO_STABLE_RELEASE_DATE) : LibyearsMeasurement::of($years))->build();
        $findings = [$finding(true, 1.111), $finding(false, 2.227), $finding(true, 0.5), $finding(false, 1.0), $finding(true, null)];
        $block = Report2Root::toLibyearsArray(Libyears::fromFindings($findings), $findings);
        $only = static fn (Finding $one): array => array_intersect_key(Report2Root::toLibyearsArray(Libyears::fromFindings([$one]), [$one]), ['total' => 0, 'direct_requirements' => 0, 'packages' => 0]);

        self::assertSame(['total' => 4.84, 'direct_requirements' => 1.61, 'packages' => 5], array_intersect_key($block, ['total' => 0, 'direct_requirements' => 0, 'packages' => 0]));
        self::assertSame(['total', 'direct_requirements', 'measured', 'unmeasured', 'furthest_behind', 'packages'], array_keys($block));
        self::assertSame(['total' => 2.23, 'direct_requirements' => 0.0, 'packages' => 1], $only($finding(false, 2.227)));
        self::assertSame(['total' => null, 'direct_requirements' => null, 'packages' => 1], $only($finding(true, null)));
        $pair = [$finding(true, 1.114), $finding(true, 1.114), $finding(false, 1.114), $finding(false, 0.286)];
        self::assertSame(['total' => 3.62, 'direct_requirements' => 2.22, 'packages' => 4], array_intersect_key(Report2Root::toLibyearsArray(Libyears::fromFindings($pair), $pair), ['total' => 0, 'direct_requirements' => 0, 'packages' => 0]), 'the sums add the written values, not the measured 3.628');
    }

    /** report-2 writes the date to the second: of two dates in one second, the first stays. */
    public function testLibyearsWritesTheBlockTheSchemaDescribes(): void
    {
        $finding = static fn (string $package, LibyearsMeasurement $years, bool $direct, string $version): Finding => (new FindingBuilder())->withPackage($package)->withVersion($version)
            ->withChain($direct ? [$package] : ['vendor/root', $package])->withLibyears($years)->build();
        $findings = [
            $finding('smalot/pdfparser', LibyearsMeasurement::of(4.7123), true, 'v1.1.0'),
            $finding('psr/log', LibyearsMeasurement::of(3.36), false, '1.1.4'),
            $finding('wallabag/rulerz', LibyearsMeasurement::unmeasured(Libyears::BRANCH_SNAPSHOT), true, 'dev-master'),
        ];

        self::assertSame([
            'total' => 8.07,
            'direct_requirements' => 4.71,
            'measured' => 2,
            'unmeasured' => [Libyears::BRANCH_SNAPSHOT => 1, Libyears::NO_STABLE_RELEASE_DATE => 0, Libyears::NOT_FROM_COMPOSER_REPOSITORY => 0, Libyears::METADATA_UNAVAILABLE => 0],
            'furthest_behind' => ['package' => 'smalot/pdfparser', 'version' => 'v1.1.0', 'libyears' => 4.71],
            'packages' => 3,
        ], Report2Root::toLibyearsArray(Libyears::fromFindings($findings), $findings));
    }

    public function testTheDataDateIsTheOldestAndKeepsTheFirstOfOneSecond(): void
    {
        $at = static fn (?string $date): Finding => (new FindingBuilder())->withDataDate($date === null ? null : new \DateTimeImmutable($date))->build();
        $findings = [
            $at('2026-03-01T00:00:00+00:00'),
            $at('2026-01-01T01:00:00.900000+01:00'),
            $at(null),
            $at('2026-01-01T00:00:00.100000+00:00'),
            $at('2026-02-01T00:00:00+00:00'),
        ];

        self::assertSame('2026-01-01T01:00:00+01:00', Report2Root::dataDate($findings));
        self::assertSame('2025-12-31T23:59:59+00:00', Report2Root::dataDate([$at('2026-01-01T00:00:00+00:00'), $at('2025-12-31T23:59:59.999999+00:00')]));
        self::assertNull(Report2Root::dataDate([$at(null)]));
    }

    /**
     * A finding whose S9 lists one row per advisory, with the lookup complete.
     *
     * @param list<array{string, string}> $advisories the severity and the fix kind of each row
     */
    private static function vulnerable(string $package, array $advisories, int $ignored = 0): Finding
    {
        $rows = [];
        foreach ($advisories as $i => [$severity, $kind]) {
            $rows[] = ['id' => 'PKSA-'.$i, 'severity' => $severity, 'fix' => ['kind' => $kind]];
        }
        $finding = (new FindingBuilder())->withPackage($package)->withChain([$package])->withSignals([new Signal('S9', 'warn', 'advisories', ['advisories' => $rows])])->build();

        return self::withDetails($finding, 'complete', $ignored);
    }

    private static function withDetails(Finding $finding, string $check, int $ignored): Finding
    {
        $advisory = new IgnoredAdvisory(new Advisory('PKSA-i', null, null, null, 'low', null), new AdvisoryIgnoreMatch(AdvisoryIgnoreMatch::ID, 'PKSA-i', null, AdvisoryIgnoreMatch::BY_AUDIT));

        $list = [];
        for ($i = 0; $i < $ignored; ++$i) {
            $list[] = $advisory;
        }

        return $finding->withDetails(new FindingDetails('read', null, null, [], ['requires' => null, 'target_runs' => null, 'project_allows' => null], null, $check, null, $list, null));
    }
}
