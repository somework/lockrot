<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Lockrot\Security\Severity;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\ScoreSweep;
use Lockrot\Verdict\Score;
use Lockrot\Verdict\ScoreModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Score model 1: the self-describing `run.score_model`, the copies it derives from its own rules,
 * and the properties of the score over every flag set, reach and dev, per fix kind and per
 * two-advisory set.
 */
final class ScoreModelTest extends TestCase
{
    private const MODEL = __DIR__.'/../../fixtures/score/score-model-1.json';

    private const LIVENESS = [null, 'stale', 'silent', 'abandoned'];

    public function testTheModelEqualsTheCommittedDocument(): void
    {
        $json = file_get_contents(self::MODEL);
        self::assertIsString($json);

        self::assertSame(json_decode($json, true, 512, \JSON_THROW_ON_ERROR), ScoreModel::toArray());
    }

    public function testEachDerivedCopyEqualsTheRuleItDerivesFrom(): void
    {
        $model = ScoreModel::toArray();
        $rules = self::rules($model);
        $factor = JsonPath::intAt($rules, ['no-reachable-fix-multiplier', 'factor']);
        $whenFix = JsonPath::arrayAt($rules, ['no-reachable-fix-multiplier', 'when_fix']);

        foreach (self::rows($model, 'fix_kinds') as $fix) {
            $id = JsonPath::stringAt($fix, ['id']);
            self::assertSame(\in_array($id, $whenFix, true), JsonPath::boolAt($fix, ['doubles']), $id);
        }
        foreach (self::rows($model, 'severities') as $severity) {
            $id = JsonPath::stringAt($severity, ['id']);
            $points = JsonPath::intAt($severity, ['points']);
            self::assertSame($points * $factor, JsonPath::intAt($severity, ['points_no_reachable_fix']), $id);
            self::assertSame(ScoreModel::band($points * $factor), JsonPath::stringAt($severity, ['band_no_reachable_fix']), $id);
            self::assertSame(Severity::fromComposer($id)->points(), $points, 'Security\Severity holds the same points: '.$id);
        }
        foreach (self::rows($model, 'flags') as $flag) {
            $expected = JsonPath::boolAt($flag, ['can_corroborate'])
                ? intdiv(JsonPath::intAt($flag, ['points']) * JsonPath::intAt($rules, ['corroborating-share', 'share', 'num']), JsonPath::intAt($rules, ['corroborating-share', 'share', 'den']))
                : null;
            self::assertSame($expected, $flag['corroborating_points'], JsonPath::stringAt($flag, ['id']));
        }
        $floors = [];
        foreach (self::rows($model, 'bands') as $band) {
            $floors[JsonPath::stringAt($band, ['verdict'])] = JsonPath::intAt($band, ['floor']);
        }
        $ratio = JsonPath::intAt($rules, ['band-floors', 'ratio']);
        self::assertSame(1, $floors['low']);
        self::assertSame($floors[JsonPath::stringAt($rules, ['band-floors', 'ratio_from'])] * $ratio, $floors['high']);
        self::assertSame($floors['high'] * $ratio, $floors['critical']);
        self::assertSame(JsonPath::stringAt($model, ['rounding']) === 'floor_once', isset($rules['floor-once']));
    }

    public function testTheRulesHaveUniqueStagesAndADocAnchorEach(): void
    {
        $model = ScoreModel::toArray();

        self::assertSame(range(1, 16), JsonPath::column($model, ['rules'], 'stage'));
        self::assertSame(ScoreModel::RULES, JsonPath::column($model, ['rules'], 'id'));
        foreach (self::rules($model) as $id => $rule) {
            self::assertSame('score-'.$id, JsonPath::stringAt($rule, ['doc']));
        }
    }

    public function testNoMaximalMaintenanceSetHoldsAnExclusiveGroup(): void
    {
        $model = ScoreModel::toArray();
        foreach (self::rows($model, 'flags') as $flag) {
            $set = $flag['max_maintenance_flags'];
            foreach (self::rows($model, 'exclusive_groups') as $group) {
                $holds = \is_array($set);
                foreach (JsonPath::arrayAt($group, ['flag_ids']) as $id) {
                    $holds = $holds && \in_array($id, (array) $set, true);
                }
                self::assertFalse($holds, JsonPath::stringAt($flag, ['id']).' holds '.JsonPath::stringAt($group, ['id']));
            }
        }
    }

    public function testEachGradeHasItsFloorAndTheBandAboveIt(): void
    {
        self::assertSame(['critical' => [32, null], 'high' => [16, 'critical'], 'medium' => [8, 'high'], 'low' => [1, 'medium']], array_combine(
            ScoreModel::GRADES,
            array_map(static fn (string $grade): array => [ScoreModel::floorOf($grade), ScoreModel::nextBand($grade)], ScoreModel::GRADES)
        ));
        self::assertNull(ScoreModel::band(0));
    }

    public function testAWordThatIsNoGradeHasNoFloor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ScoreModel::floorOf('ok');
    }

    public function testAWordThatIsNoGradeHasNoBandAbove(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ScoreModel::nextBand('finished');
    }

    public function testTheEngineRefusesAReachThatItDoesNotKnow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Score::compute(['stale'], [], 'indirect', false);
    }

    public function testTwoEqualAdvisoriesTieOnTheLowerId(): void
    {
        $score = Score::compute([], [['id' => 'GHSA-b', 'severity' => 'high', 'fix_kind' => 'update'], ['id' => 'GHSA-a', 'severity' => 'high', 'fix_kind' => 'update'], ['id' => 'GHSA-c', 'severity' => 'medium', 'fix_kind' => 'none']], 'direct', false);

        self::assertSame('GHSA-a', $score->deciding()['id'] ?? null);
        self::assertSame(['GHSA-b', 'GHSA-c'], $score->tied(), 'a medium advisory nothing fixes scores 16 too, and comes after by severity');
        self::assertSame(3, $score->advisoryCount());
    }

    public function testTheLargestTotalIsAbandonedPinnedOldPromiseAndACriticalAdvisoryNothingFixes(): void
    {
        $largest = 0;
        foreach (self::flagSets() as $flags) {
            foreach (['update', 'none'] as $fix) {
                $largest = max($largest, self::total($flags, [['critical', $fix]], 'direct', false));
            }
        }

        self::assertSame(104, $largest);
        self::assertSame(104, ScoreModel::toArray()['max_total']);
        self::assertSame(104, self::total(['abandoned', 'pinned', 'old-promise'], [['critical', 'none']], 'direct', false));
    }

    /**
     * Adding a flag, moving up the liveness words, adding an advisory, raising its severity, losing
     * its fix, becoming direct or leaving packages-dev never lowers the score.
     *
     * @dataProvider fixKinds
     */
    #[DataProvider('fixKinds')]
    public function testEveryInputThatAddsRiskKeepsOrRaisesTheScore(string $fix): void
    {
        foreach (self::cases([[]] + [1 => [['medium', $fix]]]) as [$flags, $advisories, $reach, $dev]) {
            $total = self::total($flags, $advisories, $reach, $dev);
            $label = self::label($flags, $advisories, $reach, $dev);
            foreach (['pinned', 'left-behind', 'old-promise'] as $flag) {
                if (!\in_array($flag, $flags, true) && !self::excluded($flag, $flags)) {
                    self::assertGreaterThanOrEqual($total, self::total(array_merge($flags, [$flag]), $advisories, $reach, $dev), $label.' + '.$flag);
                }
            }
            $up = self::livenessUp($flags);
            if ($up !== null) {
                self::assertGreaterThanOrEqual($total, self::total($up, $advisories, $reach, $dev), $label.' liveness up');
            }
            self::assertGreaterThanOrEqual($total, self::total($flags, array_merge($advisories, [['low', $fix]]), $reach, $dev), $label.' + an advisory');
            foreach ($advisories as $i => [$severity]) {
                $raised = $advisories;
                $raised[$i] = ['critical', $fix];
                self::assertGreaterThanOrEqual($total, self::total($flags, $raised, $reach, $dev), $label.' severity up');
                $lost = $advisories;
                $lost[$i] = [$severity, 'none'];
                self::assertGreaterThanOrEqual($total, self::total($flags, $lost, $reach, $dev), $label.' loses the fix');
            }
            self::assertGreaterThanOrEqual($total, self::total($flags, $advisories, 'direct', $dev), $label.' becomes direct');
            self::assertGreaterThanOrEqual($total, self::total($flags, $advisories, $reach, false), $label.' leaves packages-dev');
        }
    }

    /**
     * One advisory alone lands in its severity's band, or one band up when nothing reachable fixes
     * it. A low advisory nothing fixes stays low: 4 points.
     *
     * @dataProvider fixKinds
     */
    #[DataProvider('fixKinds')]
    public function testOneAdvisoryAloneLandsInItsSeveritysBand(string $fix): void
    {
        $up = ['critical' => 'critical', 'high' => 'critical', 'medium' => 'high', 'unrated' => 'high', 'low' => 'low'];
        $own = ['critical' => 'critical', 'high' => 'high', 'medium' => 'medium', 'unrated' => 'medium', 'low' => 'low'];
        foreach (ScoreModel::SEVERITIES as $severity) {
            foreach (['direct', 'transitive', 'unreached'] as $reach) {
                $total = self::total([], [[$severity, $fix]], $reach, false);
                self::assertSame(\in_array($fix, ['none', 'blocked'], true) ? $up[$severity] : $own[$severity], ScoreModel::band($total), $severity.' '.$fix.' '.$reach);
            }
        }
    }

    /**
     * A reachable low advisory never moves the band of what it joins.
     *
     * @dataProvider fixKinds
     */
    #[DataProvider('fixKinds')]
    public function testAReachableLowAdvisoryNeverMovesABand(string $fix): void
    {
        if (\in_array($fix, ['none', 'blocked'], true)) {
            self::assertSame(8, self::total(['stale'], [['low', $fix]], 'transitive', false), 'two low-band parts make medium: stale 4 + low 4');

            return;
        }
        foreach (self::cases([[]]) as [$flags, , $reach, $dev]) {
            if ($flags === []) {
                continue;
            }
            self::assertSame(
                ScoreModel::band(self::total($flags, [], $reach, $dev)),
                ScoreModel::band(self::total($flags, [['low', $fix]], $reach, $dev)),
                self::label($flags, [['low', $fix]], $reach, $dev)
            );
        }
    }

    /**
     * A second advisory adds nothing unless it scores more, and a combination is at most one band
     * above its larger part.
     *
     * @dataProvider advisoryPairs
     */
    #[DataProvider('advisoryPairs')]
    public function testASecondAdvisoryAddsNothingUnlessItScoresMore(string $severityA, string $fixA, string $severityB, string $fixB): void
    {
        foreach (self::cases([[]]) as [$flags, , $reach, $dev]) {
            $a = self::total($flags, [[$severityA, $fixA]], $reach, $dev);
            $b = self::total($flags, [[$severityB, $fixB]], $reach, $dev);
            $label = self::label($flags, [[$severityA, $fixA], [$severityB, $fixB]], $reach, $dev);
            self::assertSame(max($a, $b), self::total($flags, [[$severityA, $fixA], [$severityB, $fixB]], $reach, $dev), $label);

            $larger = max(self::total($flags, [], $reach, $dev), self::total([], [[$severityA, $fixA]], $reach, $dev), self::total([], [[$severityB, $fixB]], $reach, $dev));
            self::assertLessThanOrEqual(1, self::rank(max($a, $b)) - self::rank($larger), $label.' moves more than one band');
        }
    }

    public function testMaintenanceNeverLeavesItsLeadsBandAndStaleOldPromiseStaysBelowAbandoned(): void
    {
        foreach (self::flagSets() as $flags) {
            if ($flags === []) {
                continue;
            }
            self::assertSame(ScoreModel::band(ScoreModel::POINTS[$flags[0]]), ScoreModel::band(self::total($flags, [], 'direct', false)), implode(',', $flags));
        }
        self::assertSame('high', ScoreModel::band(self::total(['old-promise', 'stale'], [], 'direct', false)));
    }

    /** @return iterable<string, array{string}> */
    public static function fixKinds(): iterable
    {
        foreach (ScoreModel::FIX_KINDS as $fix) {
            yield $fix => [$fix];
        }
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function advisoryPairs(): iterable
    {
        $one = [];
        foreach (ScoreModel::SEVERITIES as $severity) {
            foreach (ScoreModel::FIX_KINDS as $fix) {
                $one[] = [$severity, $fix];
            }
        }
        foreach ($one as $i => $a) {
            foreach (\array_slice($one, $i) as $b) {
                yield $a[0].':'.$a[1].' '.$b[0].':'.$b[1] => [$a[0], $a[1], $b[0], $b[1]];
            }
        }
    }

    /**
     * Every flag set the signals can raise: one liveness word, pinned or left-behind, old-promise.
     *
     * @return list<list<string>> each in flag order
     */
    private static function flagSets(): array
    {
        $sets = [];
        foreach (self::LIVENESS as $live) {
            foreach ([null, 'pinned', 'left-behind'] as $branch) {
                foreach ([false, true] as $oldPromise) {
                    $flags = array_values(array_filter([$live, $branch, $oldPromise ? 'old-promise' : null]));
                    usort($flags, static fn (string $a, string $b): int => array_search($a, ScoreModel::FLAG_ORDER, true) <=> array_search($b, ScoreModel::FLAG_ORDER, true));
                    $sets[] = $flags;
                }
            }
        }

        return $sets;
    }

    /**
     * @param array<int, list<array{string, string}>> $advisorySets
     *
     * @return \Generator<int, array{list<string>, list<array{string, string}>, string, bool}>
     */
    private static function cases(array $advisorySets): \Generator
    {
        foreach (self::flagSets() as $flags) {
            foreach ($advisorySets as $advisories) {
                foreach (['direct', 'transitive', 'unreached'] as $reach) {
                    foreach ([false, true] as $dev) {
                        yield [$flags, $advisories, $reach, $dev];
                    }
                }
            }
        }
    }

    /**
     * @param array<mixed, mixed> $model
     *
     * @return list<array<mixed, mixed>>
     */
    private static function rows(array $model, string $key): array
    {
        $rows = [];
        foreach (JsonPath::arrayAt($model, [$key]) as $i => $row) {
            $rows[] = JsonPath::arrayAt($model, [$key, $i]);
        }

        return $rows;
    }

    /**
     * @param array<mixed, mixed> $model
     *
     * @return array<string, array<mixed, mixed>> each rule by its id
     */
    private static function rules(array $model): array
    {
        $rules = [];
        foreach (self::rows($model, 'rules') as $rule) {
            $rules[JsonPath::stringAt($rule, ['id'])] = $rule;
        }

        return $rules;
    }

    /** @param list<string> $flags */
    private static function excluded(string $flag, array $flags): bool
    {
        return ($flag === 'pinned' && \in_array('left-behind', $flags, true)) || ($flag === 'left-behind' && \in_array('pinned', $flags, true));
    }

    /**
     * @param list<string> $flags
     *
     * @return ?list<string>
     */
    private static function livenessUp(array $flags): ?array
    {
        $live = array_values(array_intersect($flags, ['stale', 'silent', 'abandoned']));
        $at = array_search($live[0] ?? null, self::LIVENESS, true);
        if ($at === false || $at === 3) {
            return null;
        }
        $next = self::LIVENESS[$at + 1];
        $rest = array_values(array_diff($flags, ['stale', 'silent', 'abandoned']));

        return array_merge([$next], $rest);
    }

    private static function rank(int $total): int
    {
        return array_search(ScoreModel::band($total), [null, 'low', 'medium', 'high', 'critical'], true) ?: 0;
    }

    /**
     * @param list<string>                $flags
     * @param list<array{string, string}> $advisories
     */
    private static function total(array $flags, array $advisories, string $reach, bool $dev): int
    {
        $inputs = ['axis' => 'base', 'flags' => $flags, 'advisories' => $advisories, 'reach' => $reach, 'dev' => $dev, 'under' => null, 'accepted' => null];

        return Score::of(ScoreSweep::flagSet($inputs), $reach, $dev)->total();
    }

    /**
     * @param list<string>                $flags
     * @param list<array{string, string}> $advisories
     */
    private static function label(array $flags, array $advisories, string $reach, bool $dev): string
    {
        return implode(',', $flags).' | '.implode(',', array_map(static fn (array $a): string => $a[0].':'.$a[1], $advisories)).' | '.$reach.($dev ? ' dev' : '');
    }
}
