<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Analyzer;

use Lockrot\Analyzer\Report2Root;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\TestCase;

/** The root blocks that report-2 sums from its findings. */
final class Report2RootTest extends TestCase
{
    public function testTheGateCountsTheReachingTheFailingAndEachExemption(): void
    {
        $findings = [
            ['gate' => ['reaches_fail_on' => true, 'fails' => true, 'exempt_by' => null]],
            ['gate' => ['reaches_fail_on' => true, 'fails' => false, 'exempt_by' => 'baseline']],
            ['gate' => ['reaches_fail_on' => true, 'fails' => false, 'exempt_by' => 'baseline']],
            ['gate' => ['reaches_fail_on' => true, 'fails' => false, 'exempt_by' => 'acme:waiver']],
            ['gate' => ['reaches_fail_on' => false, 'fails' => false, 'exempt_by' => null]],
        ];

        $gate = Report2Root::gate(['fails' => true, 'tripped_by' => ['high'], 'fail_on_applied' => true], $findings);

        self::assertSame(['fails' => true, 'tripped_by' => ['high'], 'fail_on_applied' => true, 'reaching' => 4, 'failing' => 1, 'exempt' => ['baseline' => 2, 'acme:waiver' => 1]], $gate);
    }

    public function testSecuritySumsTheVulnerableFindingsOnly(): void
    {
        $vulnerable = static fn (string $package, array $counts, string $kind, int $ignored = 0): array => ['package' => $package, 'security' => [
            'status' => 'vulnerable', 'check' => 'complete', 'ignored_count' => $ignored, 'counts' => $counts, 'fix_kind' => $kind,
        ]];
        $findings = [
            $vulnerable('acme/a', ['critical' => 1, 'high' => 0, 'medium' => 2, 'unrated' => 0, 'low' => 0], 'update'),
            $vulnerable('acme/b', ['critical' => 0, 'high' => 1, 'medium' => 1, 'unrated' => 0, 'low' => 3], 'unknown', 2),
            $vulnerable('acme/c', ['critical' => 0, 'high' => 0, 'medium' => 0, 'unrated' => 1, 'low' => 0], 'update'),
            ['package' => 'acme/d', 'security' => ['status' => 'clear', 'check' => 'complete', 'ignored_count' => 1, 'counts' => ['critical' => 5]]],
        ];

        $security = Report2Root::security($findings);

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
        $finding = static fn (string $check): array => ['package' => 'acme/x', 'security' => ['status' => 'unchecked', 'check' => $check, 'ignored_count' => 0]];

        self::assertSame('partial', Report2Root::security([$finding('complete'), $finding('not_run')])['check']);
        self::assertSame('not_run', Report2Root::security([$finding('not_run')])['check']);
        self::assertSame('complete', Report2Root::security([])['check']);
        self::assertNull(Report2Root::security([])['update_now_command']);
    }

    public function testFlagsCountCarryingLeadingAcceptedAndGrades(): void
    {
        $findings = [
            self::graded('high', 'silent', ['stale']),
            self::graded('medium', 'silent', ['stale', 'pinned']),
            ['verdict' => 'finished', 'lead' => null, 'score' => [], 'flags' => [['id' => 'stale', 'role' => 'accepted'], ['id' => 'pinned', 'role' => 'lead']]],
        ];

        $flags = Report2Root::flags($findings);

        self::assertSame(['carrying' => 2, 'leading' => 2, 'accepted' => ['all' => 0, 'in_graded' => 0], 'by_verdict' => ['critical' => 0, 'high' => 1, 'medium' => 1, 'low' => 0]], $flags['silent']);
        self::assertSame(['all' => 3, 'in_graded' => 2], $flags['stale']['accepted']);
        self::assertSame(['all' => 1, 'in_graded' => 1], $flags['pinned']['accepted']);
        self::assertSame(['carrying' => 2, 'leading' => null, 'accepted' => null, 'by_verdict' => ['critical' => 0, 'high' => 1, 'medium' => 1, 'low' => 0]], $flags['vulnerable']);
    }

    public function testAbandonedCountsTheReplacementsAndTheSuggestions(): void
    {
        $abandoned = static fn (?string $replacement, array $signals): array => ['score' => ['terms' => [['flag' => 'abandoned']]], 'replacement' => $replacement, 'signals' => $signals];
        $s1 = static fn (?string $suggestion): array => ['id' => 'S1', 'data' => ['replacement' => $suggestion]];
        $findings = [
            $abandoned('acme/new', [$s1('acme/other')]),
            $abandoned(null, [$s1('acme/next')]),
            $abandoned(null, [$s1('acme/later')]),
            $abandoned(null, [$s1(null)]),
            $abandoned(null, [['id' => 'S5', 'data' => ['replacement' => 'acme/not-s1']]]),
            ['score' => ['terms' => [['flag' => 'stale']]], 'replacement' => null, 'signals' => [$s1('acme/not-abandoned')]],
        ];

        self::assertSame(['total' => 5, 'with_replacement' => 1, 'with_suggestion' => 2], Report2Root::abandoned($findings, ['abandoned' => ['carrying' => 5]]));
        self::assertSame(0, Report2Root::abandoned([], ['abandoned' => []])['total']);
    }

    public function testLibyearsSumsThePublishedValuesToTwoDecimals(): void
    {
        $findings = [
            ['direct' => true, 'libyears' => 1.111],
            ['direct' => false, 'libyears' => 2.227],
            ['libyears' => 1.0],
            ['direct' => true, 'libyears' => null],
        ];

        self::assertSame(['unmeasured' => [], 'total' => 4.34, 'direct_requirements' => 1.11, 'packages' => 4], Report2Root::libyears(['unmeasured' => []], $findings));
        self::assertSame(['total' => 2.23, 'direct_requirements' => 0.0, 'packages' => 1], Report2Root::libyears([], [['direct' => false, 'libyears' => 2.227]]));
        self::assertSame(['total' => null, 'direct_requirements' => null, 'packages' => 1], Report2Root::libyears([], [['direct' => true, 'libyears' => null]]));
    }

    public function testTheDataDateIsTheOldestAndKeepsTheFirstOfOneInstant(): void
    {
        $findings = [
            ['data_date' => '2026-03-01T00:00:00+00:00'],
            ['data_date' => '2026-01-01T00:00:00+00:00'],
            ['data_date' => null],
            ['data_date' => '2026-01-01T01:00:00+01:00'],
            ['data_date' => '2026-02-01T00:00:00+00:00'],
        ];

        self::assertSame('2026-01-01T00:00:00+00:00', Report2Root::dataDate($findings));
        self::assertNull(Report2Root::dataDate([['data_date' => null]]));
    }

    public function testAFindingWithoutAGateNeitherReachesNorFails(): void
    {
        self::assertSame(['reaching' => 0, 'failing' => 0, 'exempt' => ['baseline' => 0]], \array_slice(Report2Root::gate(['fails' => false, 'tripped_by' => [], 'fail_on_applied' => true], [['package' => 'acme/a']]), 3));
    }

    /** Values that lockrot does not write count from zero: a status, a severity or a fix kind of a later release. */
    public function testSecurityCountsAValueItDoesNotListFromZero(): void
    {
        $findings = [
            ['package' => 'acme/a', 'security' => ['status' => 'acme:waived', 'check' => 'complete', 'ignored_count' => 0]],
            ['package' => 'acme/b', 'security' => ['status' => 'vulnerable', 'check' => 'complete', 'ignored_count' => 0, 'counts' => ['acme:severe' => 2, 'high' => 'x'], 'fix_kind' => 'acme:patch']],
        ];

        $security = Report2Root::security($findings);

        self::assertSame(1, JsonPath::intAt($security, ['packages', 'acme:waived']));
        self::assertSame(2, JsonPath::intAt($security, ['severities', 'acme:severe']));
        self::assertSame(0, JsonPath::intAt($security, ['severities', 'high']));
        self::assertSame(2, JsonPath::intAt($security, ['advisories', 'counted']));
        self::assertSame(1, JsonPath::intAt($security, ['fixes', 'acme:patch']));
    }

    /**
     * A graded finding that counts `silent` and `vulnerable`, with the flags its allowlist accepts.
     *
     * @param list<string> $accepted
     *
     * @return array<string, mixed>
     */
    private static function graded(string $verdict, string $lead, array $accepted): array
    {
        $flags = [];
        foreach ($accepted as $id) {
            $flags[] = ['id' => $id, 'role' => 'accepted'];
        }

        return [
            'verdict' => $verdict,
            'lead' => $lead,
            'score' => ['terms' => [['flag' => 'silent'], ['flag' => 'vulnerable'], ['part' => 'maintenance']]],
            'flags' => $flags,
        ];
    }
}
