<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Analyzer;

use Lockrot\Analyzer\Report2Root;
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
}
