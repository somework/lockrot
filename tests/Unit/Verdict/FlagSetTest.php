<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Lockrot\Allowlist\AllowlistEntry;
use Lockrot\Clock;
use Lockrot\Data\Abandoned\AbandonedIgnoreMatch;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\Rule\AbandonedRule;
use Lockrot\Signal\Rule\ArchivedRule;
use Lockrot\Signal\Rule\NoPushRule;
use Lockrot\Signal\Rule\NoReleaseRule;
use Lockrot\Signal\Signal;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use Lockrot\Verdict\FlagSet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FlagSetTest extends TestCase
{
    /**
     * S2 and S4 collapse into one liveness word, and S1 or S3 make it `abandoned`.
     *
     * @dataProvider liveness
     *
     * @param list<array{string, string}> $signals id and level
     */
    #[DataProvider('liveness')]
    public function testTheLivenessSignalsCollapseIntoOneWord(array $signals, ?string $word, ?string $hidden): void
    {
        $flags = FlagSet::fromSignals(self::signals($signals), null, []);

        self::assertSame($word === null ? [] : [$word], $flags->fired());
        self::assertSame($hidden, $flags->hidden());
    }

    /** @return iterable<string, array{list<array{string, string}>, ?string, ?string}> */
    public static function liveness(): iterable
    {
        yield 'no liveness signal' => [[], null, null];
        yield 'S2 and S4 at high' => [[['S2', 'high'], ['S4', 'high']], 'silent', null];
        yield 'S2 at high alone' => [[['S2', 'high']], 'stale', null];
        yield 'S4 at high alone' => [[['S4', 'high']], 'stale', null];
        yield 'S2 at warn, S4 at high' => [[['S2', 'warn'], ['S4', 'high']], 'stale', null];
        yield 'S2 at high, S4 at warn' => [[['S2', 'high'], ['S4', 'warn']], 'stale', null];
        yield 'S4 at warn alone' => [[['S4', 'warn']], 'stale', null];
        yield 'S1 hides silent' => [[['S1', 'high'], ['S2', 'high'], ['S4', 'high']], 'abandoned', 'silent'];
        yield 'S3 hides stale' => [[['S2', 'warn'], ['S3', 'high']], 'abandoned', 'stale'];
        yield 'S1 alone' => [[['S1', 'high']], 'abandoned', null];
    }

    /**
     * `degree.reasons` names the sources of `abandoned` in a closed order, and `archived` is the pill.
     *
     * @dataProvider reasons
     *
     * @param list<array{string, string}> $signals
     * @param list<string>                $reasons
     */
    #[DataProvider('reasons')]
    public function testAbandonedNamesTheSignalsThatRaisedIt(array $signals, array $reasons): void
    {
        self::assertSame($reasons, FlagSet::fromSignals(self::signals($signals), null, [])->reasons());
    }

    /** @return iterable<string, array{list<array{string, string}>, list<string>}> */
    public static function reasons(): iterable
    {
        yield 'marked' => [[['S1', 'high']], ['marked']];
        yield 'archived' => [[['S3', 'high']], ['archived']];
        yield 'both' => [[['S1', 'high'], ['S3', 'high']], ['marked', 'archived']];
        yield 'not abandoned' => [[['S2', 'high'], ['S4', 'high']], []];
    }

    public function testEachSignalRaisesItsFlagAndTheAdvisoriesRaiseVulnerable(): void
    {
        $flags = FlagSet::fromSignals(self::signals([['S5', 'high'], ['S8', 'warn'], ['S2', 'warn'], ['S6', 'high'], ['S7', 'warn'], ['S10', 'warn']]), null, [self::advisory('A0')]);

        self::assertSame(['pinned', 'left-behind', 'old-promise', 'stale', 'vulnerable'], $flags->fired());
        self::assertSame(['pinned', 'left-behind', 'old-promise', 'stale'], $flags->countedMaintenance());
        self::assertSame([self::advisory('A0')], $flags->advisories());
        self::assertSame([], FlagSet::fromSignals(self::signals([['S9', 'warn']]), null, [])->fired(), 'S9 without a counted advisory raises nothing');
    }

    /**
     * An accepted flag stays listed. A liveness word an entry lists also accepts the words after it.
     *
     * @dataProvider acceptance
     *
     * @param list<array{string, string}> $signals
     * @param ?list<string>               $entryFlags null: the entry accepts every maintenance flag
     * @param list<string>                $accepted
     * @param list<string>                $counted
     * @param list<string>                $acceptSet
     */
    #[DataProvider('acceptance')]
    public function testAnEntryAcceptsFlagsThatStayListed(array $signals, ?array $entryFlags, array $accepted, array $counted, array $acceptSet): void
    {
        $flags = FlagSet::fromSignals(self::signals($signals), new AllowlistEntry('vendor/pkg', null, 'kept on purpose', null, 'project', $entryFlags), [self::advisory('A0')]);

        self::assertContains('vulnerable', $flags->fired(), 'an entry never accepts an advisory');
        self::assertSame($accepted, $flags->accepted());
        self::assertSame($counted, $flags->countedMaintenance());
        self::assertSame($acceptSet, $flags->acceptSet());
    }

    /** @return iterable<string, array{list<array{string, string}>, ?list<string>, list<string>, list<string>, list<string>}> */
    public static function acceptance(): iterable
    {
        $staleOldPromise = [['S2', 'warn'], ['S5', 'high']];
        yield 'an entry that lists stale' => [$staleOldPromise, ['stale'], ['stale'], ['old-promise'], ['stale']];
        yield 'an entry that lists nothing that fired' => [$staleOldPromise, ['pinned'], [], ['old-promise', 'stale'], ['pinned']];
        yield 'a whole-package entry' => [$staleOldPromise, null, ['old-promise', 'stale'], [], ['abandoned', 'silent', 'pinned', 'left-behind', 'old-promise', 'stale']];
        yield 'abandoned covers silent and stale' => [$staleOldPromise, ['abandoned'], ['stale'], ['old-promise'], ['abandoned', 'silent', 'stale']];
        yield 'silent covers stale' => [$staleOldPromise, ['silent'], ['stale'], ['old-promise'], ['silent', 'stale']];
        yield 'a liveness word beside another flag' => [$staleOldPromise, ['old-promise', 'silent'], ['old-promise', 'stale'], [], ['silent', 'old-promise', 'stale']];
        yield 'stale covers no other word' => [[['S2', 'high'], ['S4', 'high']], ['stale'], [], ['silent'], ['stale']];
    }

    /**
     * Removing a flag removes the signals that raise it, then the flags are derived again: removing
     * `abandoned` brings back the word S2 and S4 give, removing a liveness word leaves none.
     */
    public function testRemovingAFlagRemovesItsSignalsAndDerivesTheFlagsAgain(): void
    {
        $flags = FlagSet::fromSignals(self::signals([['S1', 'high'], ['S3', 'high'], ['S2', 'high'], ['S4', 'high'], ['S5', 'high']]), null, [self::advisory('A0'), self::advisory('A1')]);

        self::assertSame(['silent', 'old-promise', 'vulnerable'], $flags->without('abandoned')->fired());
        self::assertSame(['abandoned', 'vulnerable'], $flags->without('old-promise')->fired());
        self::assertSame(['abandoned', 'old-promise'], $flags->without('vulnerable')->fired());
        self::assertSame([self::advisory('A1')], $flags->withoutAdvisory('A0')->advisories());

        $silent = FlagSet::fromSignals(self::signals([['S2', 'high'], ['S4', 'high'], ['S6', 'high']]), null, []);
        self::assertSame(['pinned'], $silent->without('silent')->fired());
        self::assertSame(['pinned'], FlagSet::fromSignals(self::signals([['S2', 'warn'], ['S6', 'high']]), null, [])->without('stale')->fired());
        self::assertSame(['silent'], $silent->without('pinned')->fired());
    }

    public function testAReRunKeepsTheEntrysWholeAcceptedSet(): void
    {
        $entry = new AllowlistEntry('vendor/pkg', null, 'kept on purpose', null, 'project', ['silent']);
        $flags = FlagSet::fromSignals(self::signals([['S1', 'high'], ['S2', 'high'], ['S4', 'high']]), $entry, []);

        self::assertSame([], $flags->accepted(), 'silent is hidden while abandoned shows');
        $without = $flags->without('abandoned');
        self::assertSame(['silent'], $without->fired());
        self::assertSame(['silent'], $without->accepted(), 'the brought-back word stays accepted');

        $counting = FlagSet::fromSignals(self::signals([['S2', 'warn'], ['S5', 'high']]), $entry, [])->counting('stale');
        self::assertSame(['old-promise', 'stale'], $counting->countedMaintenance());
        self::assertSame(['silent'], $counting->acceptSet());
    }

    public function testTheReleaseLevelIsS2s(): void
    {
        self::assertSame('warn', FlagSet::fromSignals(self::signals([['S2', 'warn']]), null, [])->releaseLevel());
        self::assertSame('high', FlagSet::fromSignals(self::signals([['S1', 'high'], ['S2', 'high']]), null, [])->releaseLevel());
        self::assertNull(FlagSet::fromSignals(self::signals([['S4', 'high']]), null, [])->releaseLevel());
    }

    /**
     * Composer's abandoned ignore list removes S1, never the flag: the flags come from the signals
     * that remain.
     *
     * @dataProvider ignoredMarking
     *
     * @param ?list<string> $entryFlags
     * @param list<string>  $fired
     * @param list<string>  $reasons
     * @param list<string>  $accepted
     */
    #[DataProvider('ignoredMarking')]
    public function testAnIgnoredMarkingIsNoS1(bool $listed, bool $archived, string $released, string $pushed, ?array $entryFlags, array $fired, array $reasons, array $accepted): void
    {
        $match = $listed ? new AbandonedIgnoreMatch(AbandonedIgnoreMatch::BY_POLICY, [['pattern' => 'vendor/*', 'reason' => 'migration planned', 'constraints' => []]]) : null;
        $facts = new PackageFacts(F::package(), F::metadata([['1.0.0', $released]], true, 'other/pkg'), F::activity($archived, $pushed), [], null, $match);
        $clock = Clock::fixed(F::NOW);
        $signals = [];
        foreach ([new AbandonedRule(), new NoReleaseRule($clock, new Thresholds()), new ArchivedRule(), new NoPushRule($clock, new Thresholds())] as $rule) {
            $signal = $rule->evaluate($facts);
            if ($signal !== null) {
                $signals[] = $signal;
            }
        }
        $entry = $entryFlags === null ? null : new AllowlistEntry('vendor/pkg', null, 'kept on purpose', null, 'project', $entryFlags);
        $flags = FlagSet::fromSignals($signals, $entry, []);

        self::assertSame($fired, $flags->fired());
        self::assertSame($reasons, $flags->reasons());
        self::assertSame($accepted, $flags->accepted());
    }

    /** @return iterable<string, array{bool, bool, string, string, ?list<string>, list<string>, list<string>, list<string>}> */
    public static function ignoredMarking(): iterable
    {
        $recent = '2026-06-01T00:00:00+00:00';
        $warn = '2022-06-01T00:00:00+00:00';
        $old = '2019-01-01T00:00:00+00:00';
        yield 'not listed: S1 decides' => [false, false, $recent, $recent, null, ['abandoned'], ['marked'], []];
        yield 'listed, archived' => [true, true, $recent, $recent, null, ['abandoned'], ['archived'], []];
        yield 'listed, S2 and S4 at high' => [true, false, $old, $old, null, ['silent'], [], []];
        yield 'listed, S2 at warn' => [true, false, $warn, $recent, null, ['stale'], [], []];
        yield 'listed alone: no maintenance flag' => [true, false, $recent, $recent, null, [], [], []];
        yield 'listed, S2 at warn, an entry lists abandoned' => [true, false, $warn, $recent, ['abandoned'], ['stale'], [], ['stale']];
    }

    /**
     * @param list<array{string, string}> $signals
     *
     * @return list<Signal>
     */
    private static function signals(array $signals): array
    {
        return array_map(static fn (array $s): Signal => new Signal($s[0], $s[1], $s[0].' fired'), $signals);
    }

    /** @return array{id: string, severity: string, fix_kind: string} */
    private static function advisory(string $id): array
    {
        return ['id' => $id, 'severity' => 'high', 'fix_kind' => 'update'];
    }
}
