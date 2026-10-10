<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Analyzer;

use Lockrot\Allowlist\AllowlistEntry;
use Lockrot\Analyzer\ScoreRulesUsed;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\Score;
use Lockrot\Verdict\ScoreModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** `run.score_rules_used`: each rule counts the findings on which it changed or decided a number. */
final class ScoreRulesUsedTest extends TestCase
{
    private const DIRECT = ['vendor/pkg'];
    private const TRANSITIVE = ['vendor/root', 'vendor/pkg'];
    private const UNREACHED = [];

    /**
     * @param array<string, int> $used the rules that count the finding, every other at 0
     *
     * @dataProvider findings
     */
    #[DataProvider('findings')]
    public function testEachRuleCountsTheFindingsItActsOn(Finding $finding, array $used): void
    {
        self::assertSame(array_merge(ScoreModel::rulesUnused(), $used), ScoreRulesUsed::of([$finding]));
    }

    /** @return iterable<string, array{Finding, array<string, int>}> */
    public static function findings(): iterable
    {
        yield 'score 0, an accepted flag whose rerun halves for reach and dev' => [
            self::finding(['S2' => 'warn'], 'stale', [], self::TRANSITIVE, true),
            ['counted' => 1, 'divide-reach' => 1, 'divide-dev' => 1, 'zero-verdicts' => 1],
        ];
        yield 'score 0, an accepted flag whose rerun halves for dev only' => [
            self::finding(['S2' => 'warn'], 'stale', [], self::DIRECT, true),
            ['counted' => 1, 'divide-dev' => 1, 'zero-verdicts' => 1],
        ];
        yield 'score 0, an accepted flag whose rerun halves for reach only' => [
            self::finding(['S2' => 'warn'], 'stale', [], self::TRANSITIVE, false),
            ['counted' => 1, 'divide-reach' => 1, 'zero-verdicts' => 1],
        ];
        yield 'score 0, an accepted flag whose rerun halves nothing' => [
            self::finding(['S2' => 'warn'], 'stale', [], self::DIRECT, false),
            ['counted' => 1, 'zero-verdicts' => 1],
        ];
        yield 'score 0, nothing accepted' => [
            self::finding([], null, [], self::DIRECT, false),
            ['zero-verdicts' => 1],
        ];
        yield 'graded by every rule' => [
            self::finding(['S5' => 'warn', 'S2' => 'warn'], null, [['critical', 'none'], ['critical', 'none']], self::TRANSITIVE, true),
            ['lead-first' => 1, 'corroborating-share' => 1, 'advisory-points' => 1, 'no-reachable-fix-multiplier' => 1, 'security-max' => 1, 'divide-reach' => 1, 'security-exempt-from-reach' => 1, 'sum' => 1, 'divide-dev' => 1, 'floor-once' => 1, 'band-floors' => 1],
        ];
        yield 'graded by its lead alone' => [
            self::finding(['S5' => 'warn'], null, [], self::DIRECT, false),
            ['lead-first' => 1, 'band-floors' => 1],
        ];
        yield 'graded by one advisory of a direct requirement' => [
            self::finding([], null, [['high', 'update']], self::DIRECT, false),
            ['advisory-points' => 1, 'band-floors' => 1],
        ];
        yield 'graded by one advisory of an unreached package: the reach halving of 0 changes nothing' => [
            self::finding([], null, [['high', 'upgrade']], self::UNREACHED, false),
            ['advisory-points' => 1, 'security-exempt-from-reach' => 1, 'band-floors' => 1],
        ];
        yield 'graded by one advisory of a dev package' => [
            self::finding([], null, [['high', 'update']], self::DIRECT, true),
            ['advisory-points' => 1, 'divide-dev' => 1, 'band-floors' => 1],
        ];
        yield 'graded by a transitive lead, halved for reach' => [
            self::finding(['S5' => 'warn'], null, [], self::TRANSITIVE, false),
            ['lead-first' => 1, 'divide-reach' => 1, 'band-floors' => 1],
        ];
        yield 'graded by one advisory, with an accepted flag whose rerun halves for reach' => [
            self::finding(['S2' => 'warn'], 'stale', [['high', 'update']], self::TRANSITIVE, false),
            ['counted' => 1, 'advisory-points' => 1, 'divide-reach' => 1, 'security-exempt-from-reach' => 1, 'band-floors' => 1],
        ];
        yield 'graded by one advisory, with an accepted flag whose rerun halves for dev' => [
            self::finding(['S2' => 'warn'], 'stale', [['high', 'update']], self::DIRECT, true),
            ['counted' => 1, 'advisory-points' => 1, 'divide-dev' => 1, 'band-floors' => 1],
        ];
        yield 'graded by two advisories that a fix reaches' => [
            self::finding([], null, [['high', 'update'], ['low', 'update']], self::DIRECT, false),
            ['advisory-points' => 1, 'security-max' => 1, 'band-floors' => 1],
        ];
    }

    /** Each rule adds up over the findings: every case twice counts twice. */
    public function testEveryRuleAddsUpOverTheFindings(): void
    {
        $findings = [];
        $expected = ScoreModel::rulesUnused();
        foreach (self::findings() as [$finding, $used]) {
            $findings[] = $finding;
            $findings[] = $finding;
            foreach ($used as $rule => $count) {
                $expected[$rule] = (int) $expected[$rule] + 2 * $count;
            }
        }

        self::assertSame($expected, ScoreRulesUsed::of($findings));
        self::assertNull(ScoreRulesUsed::of($findings)['sort']);
    }

    /**
     * @param array<string, string>       $signals    the level of each signal that fires
     * @param ?string                     $accepted   the flag that the allowlist entry accepts
     * @param list<array{string, string}> $advisories the severity and the fix kind of each counted advisory
     * @param list<string>                $chain
     */
    private static function finding(array $signals, ?string $accepted, array $advisories, array $chain, bool $dev): Finding
    {
        $fired = [];
        foreach ($signals as $id => $level) {
            $fired[] = new Signal($id, $level, $id);
        }
        $counted = [];
        foreach ($advisories as $i => [$severity, $kind]) {
            $counted[] = Score::advisory('PKSA-'.$i, $severity, $kind);
        }
        $entry = $accepted === null ? null : new AllowlistEntry('vendor/pkg', null, 'kept', null, AllowlistEntry::BY_PROJECT, [$accepted]);

        return (new FindingBuilder())->withChain($chain)->withDev($dev)->withFlags(FlagSet::fromSignals($fired, $entry, $counted))->build();
    }
}
