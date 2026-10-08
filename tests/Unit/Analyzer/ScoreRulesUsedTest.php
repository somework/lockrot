<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Analyzer;

use Lockrot\Analyzer\ScoreRulesUsed;
use Lockrot\Verdict\ScoreModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** `run.score_rules_used`: each rule counts the findings on which it changed or decided a number. */
final class ScoreRulesUsedTest extends TestCase
{
    /**
     * @param array<string, mixed> $finding
     * @param array<string, int>   $used    the rules that count the finding, every other at 0
     *
     * @dataProvider findings
     */
    #[DataProvider('findings')]
    public function testEachRuleCountsTheFindingsItActsOn(array $finding, array $used): void
    {
        self::assertSame(array_merge(ScoreModel::rulesUnused(), $used), ScoreRulesUsed::of([$finding]));
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, int>}> */
    public static function findings(): iterable
    {
        $halves = ['if_counted' => ['modifiers' => [
            ['applies_to' => 'maintenance', 'reason' => 'transitive', 'before' => 16, 'after' => 8],
            ['applies_to' => 'total', 'reason' => 'dev', 'before' => 8, 'after' => 4],
        ]]];
        $keeps = ['if_counted' => ['modifiers' => [
            ['applies_to' => 'maintenance', 'reason' => 'transitive', 'before' => 8, 'after' => 8],
            ['applies_to' => 'total', 'reason' => 'dev', 'before' => 4, 'after' => 4],
        ]]];

        yield 'score 0, an accepted flag whose rerun halves for reach and dev' => [
            ['reach' => 'transitive', 'score' => ['accepted' => [$halves]]],
            ['counted' => 1, 'divide-reach' => 1, 'divide-dev' => 1, 'zero-verdicts' => 1],
        ];
        yield 'score 0, an accepted flag whose rerun halves nothing' => [
            ['reach' => 'transitive', 'score' => ['accepted' => [$keeps]]],
            ['counted' => 1, 'zero-verdicts' => 1],
        ];
        yield 'score 0, nothing accepted' => [
            ['reach' => 'direct', 'score' => ['accepted' => []]],
            ['zero-verdicts' => 1],
        ];
        yield 'graded by every rule' => [
            ['reach' => 'transitive', 'score' => [
                'terms' => [['part' => 'maintenance', 'role' => 'lead'], ['part' => 'maintenance', 'role' => 'corroborating'], ['part' => 'security', 'role' => 'security', 'multiplier' => 2]],
                'modifiers' => [['applies_to' => 'maintenance', 'reason' => 'transitive', 'before' => 16, 'after' => 8], ['applies_to' => 'total', 'reason' => 'dev', 'before' => 40, 'after' => 20]],
                'parts' => ['maintenance' => ['status' => 'counted'], 'security' => ['status' => 'counted', 'of' => 2]],
                'rounded_down' => true,
                'accepted' => [],
            ]],
            ['lead-first' => 1, 'corroborating-share' => 1, 'advisory-points' => 1, 'no-reachable-fix-multiplier' => 1, 'security-max' => 1, 'divide-reach' => 1, 'security-exempt-from-reach' => 1, 'sum' => 1, 'divide-dev' => 1, 'floor-once' => 1, 'band-floors' => 1],
        ];
        yield 'graded by its lead alone, with modifiers that change nothing' => [
            ['reach' => 'direct', 'score' => [
                'terms' => [['part' => 'maintenance', 'role' => 'lead', 'multiplier' => 1]],
                'modifiers' => [['applies_to' => 'maintenance', 'reason' => 'transitive', 'before' => 8, 'after' => 8]],
                'parts' => ['maintenance' => ['status' => 'counted'], 'security' => ['status' => 'none', 'of' => 0]],
                'rounded_down' => false,
            ]],
            ['lead-first' => 1, 'band-floors' => 1],
        ];
        yield 'graded by one advisory of a direct requirement' => [
            ['reach' => 'direct', 'score' => [
                'terms' => [['part' => 'security', 'role' => 'security', 'multiplier' => 1]],
                'parts' => ['maintenance' => ['status' => 'none'], 'security' => ['status' => 'counted', 'of' => 1]],
            ]],
            ['advisory-points' => 1, 'band-floors' => 1],
        ];
        yield 'graded by one advisory of a transitive package' => [
            ['reach' => 'transitive', 'score' => [
                'terms' => [['part' => 'security', 'role' => 'security', 'multiplier' => 1]],
                'parts' => ['maintenance' => ['status' => 'none'], 'security' => ['status' => 'counted', 'of' => 1]],
            ]],
            ['advisory-points' => 1, 'security-exempt-from-reach' => 1, 'band-floors' => 1],
        ];
        yield 'graded, with an accepted flag whose rerun halves' => [
            ['reach' => 'transitive', 'score' => [
                'terms' => [['part' => 'maintenance', 'role' => 'lead']],
                'parts' => ['maintenance' => ['status' => 'counted'], 'security' => ['status' => 'none', 'of' => 0]],
                'accepted' => [$halves],
            ]],
            ['counted' => 1, 'lead-first' => 1, 'divide-reach' => 1, 'divide-dev' => 1, 'band-floors' => 1],
        ];
    }

    public function testTheCountsAddUpOverTheFindings(): void
    {
        $zero = ['reach' => 'direct', 'score' => []];
        $graded = ['reach' => 'direct', 'score' => ['terms' => [['part' => 'maintenance', 'role' => 'lead']]]];

        $used = ScoreRulesUsed::of([$zero, $graded, $zero, $graded, $graded]);

        self::assertSame([2, 3, 3, null], [$used['zero-verdicts'], $used['band-floors'], $used['lead-first'], $used['sort']]);
    }
}
