<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Analyzer\Report;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Config\Gate;
use Lockrot\Legacy\Verdict013;
use Lockrot\Tests\Support\BaselineEntries;
use Lockrot\Tests\Support\CorpusFloor;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\ScoreModel;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A flag value of `--fail-on` reaches what 0.13's verdict value of the same word reached: a verdict
 * at or above it in verdict order (SPEC-0.14.md §6.1). No Composer abandoned ignore list applies
 * on the corpus floor. Under a baseline, the coverage rule of baseline-2 fails what 0.13's
 * worsened rule failed.
 *
 * @coversNothing
 *
 * @group covers-nothing
 */
#[CoversNothing]
#[Group('covers-nothing')]
final class FlagGateParityTest extends TestCase
{
    private const MAINTENANCE = ['abandoned', 'silent', 'pinned', 'left-behind', 'old-promise', 'stale'];

    public function testEachFlagValueReachesWhat013sVerdictValueReachedOnTheCorpusFloor(): void
    {
        self::assertSame(self::MAINTENANCE, \array_slice(ScoreModel::FLAG_ORDER, 0, 6));
        $differ = [];
        $failing = 0;
        foreach (CorpusFloor::reports() as $recorded) {
            $findings = array_map(static fn (array $f): Finding => CorpusFloor::finding($f, CorpusFloor::advisories($f)), $recorded['findings']);
            $report = new Report($findings, [], new \DateTimeImmutable($recorded['generated_at']), \count($findings), 0);
            foreach (self::MAINTENANCE as $value) {
                $gate = Gate::decide($report, FailOn::parse($value), false, Gate::MODE_CHECK);
                foreach ($recorded['findings'] as $i => $f) {
                    $legacy = Verdict013::severity($f['verdict']) >= Verdict013::severity($value);
                    $standing = $gate->standingOf($findings[$i]->package());
                    $fails = $standing !== null && $standing->fails();
                    $failing += $fails ? 1 : 0;
                    if ($fails !== $legacy) {
                        $differ[] = \sprintf('%s %s at %s: 0.13 verdict %s', $recorded['name'], $f['package'], $value, $f['verdict']);
                    }
                }
            }
        }

        self::assertGreaterThan(0, $failing, 'the floor gives failing findings, or the parity proves little');
        self::assertSame([], \array_slice($differ, 0, 20), \count($differ).' findings differ');
    }

    /**
     * Every flag value, recorded lead and current lead: the coverage rule fails exactly when 0.13
     * failed a worsened finding at or above the value. A finding whose lead is the recorded one or
     * below it is known.
     */
    public function testTheCoverageRuleFailsWhat013sWorsenedRuleFailed(): void
    {
        $differ = [];
        foreach (self::MAINTENANCE as $value) {
            foreach (self::MAINTENANCE as $recorded) {
                foreach (self::MAINTENANCE as $lead) {
                    $finding = (new FindingBuilder())->withPackage('acme/pkg')->withVerdict($lead)->withChain(['acme/pkg'])->build();
                    $report = new Report([$finding], [], new \DateTimeImmutable('2026-09-14T00:00:00+00:00'), 1, 0);
                    $baseline = BaselineEntries::baseline([BaselineEntries::of('acme/pkg', '1.0.0', $recorded, '2026-01-01')]);
                    $compared = $report->withBaseline(BaselineComparison::compare($baseline, $report, 'lockrot-baseline.json', ['acme/pkg']));
                    $standing = Gate::decide($compared, FailOn::parse($value), false, Gate::MODE_CHECK)->standingOf('acme/pkg');
                    $legacy = Verdict013::severity($lead) >= Verdict013::severity($value) && Verdict013::severity($lead) > Verdict013::severity($recorded);
                    if ($standing === null || $standing->fails() !== $legacy) {
                        $differ[] = \sprintf('fail-on=%s recorded %s lead %s: 0.13 %s', $value, $recorded, $lead, $legacy ? 'fails' : 'passes');
                    }
                }
            }
        }

        self::assertSame([], $differ);
    }
}
