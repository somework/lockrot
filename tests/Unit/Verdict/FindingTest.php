<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Lockrot\Signal\Signal;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Priority;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\TestCase;

final class FindingTest extends TestCase
{
    public function testEvidenceAndArray(): void
    {
        $signals = [
            new Signal('S2', 'high', 'last release 2015-11-16 (10.8 years ago)', ['years' => 10.8]),
            new Signal('S4', 'high', 'last push 2015-11-16 (10.8 years ago)', ['years' => 10.8]),
        ];
        $finding = new Finding('phpzip/phpzip', '2.0.8', Verdict::SILENT, $signals, ['wallabag/wallabag', 'phpzip/phpzip'], null, new \DateTimeImmutable('2026-09-14T10:00:00+00:00'));
        self::assertSame('last release 2015-11-16 (10.8 years ago); last push 2015-11-16 (10.8 years ago)', $finding->evidence());
        $array = $finding->toArray();
        self::assertSame('phpzip/phpzip', $array['package']);
        self::assertSame('2.0.8', $array['version']);
        self::assertSame('silent', $array['verdict']);
        self::assertIsArray($array['signals']);
        self::assertSame(['S2', 'S4'], array_column($array['signals'], 'id'));
        self::assertSame(['high', 'high'], array_column($array['signals'], 'level'));
        self::assertSame(
            ['last release 2015-11-16 (10.8 years ago)', 'last push 2015-11-16 (10.8 years ago)'],
            array_column($array['signals'], 'summary')
        );
        self::assertSame([['years' => 10.8], ['years' => 10.8]], array_column($array['signals'], 'data'));
        self::assertSame('last release 2015-11-16 (10.8 years ago); last push 2015-11-16 (10.8 years ago)', $array['evidence']);
        self::assertSame('2026-09-14T10:00:00+00:00', $array['data_date']);
        self::assertSame(['wallabag/wallabag', 'phpzip/phpzip'], $array['chain']);
    }

    public function testAnAdvisoryOnAnAbandonedPackageRaisesThePriorityAndSaysNoFixIsExpected(): void
    {
        $signals = [
            new Signal('S1', 'high', 'marked abandoned by its repository'),
            new Signal('S9', 'warn', '1 security advisory affects 1.0.0 (CVE-2024-0001)', ['advisories' => []]),
        ];
        $direct = new Finding('vendor/pkg', '1.0.0', Verdict::ABANDONED, $signals, ['vendor/pkg'], null, null, null, false, ['vendor/pkg']);
        $transitiveDev = new Finding('vendor/pkg', '1.0.0', Verdict::ABANDONED, $signals, ['root/app', 'vendor/pkg'], null, null, null, true, ['root/app']);

        self::assertTrue($direct->hasUnfixableAdvisory());
        self::assertSame(Priority::CRITICAL, $direct->priority());
        self::assertSame(Priority::HIGH, $transitiveDev->priority(), 'medium raised one step');
        self::assertSame('marked abandoned by its repository; 1 security advisory affects 1.0.0 (CVE-2024-0001); no fix expected', $direct->ownEvidence());
        self::assertSame($direct->ownEvidence(), $direct->evidence());
    }

    public function testAnAdvisoryAloneChangesNeitherThePriorityNorTheWording(): void
    {
        $s9 = new Signal('S9', 'warn', '1 security advisory affects 1.0.0 (CVE-2024-0001)', ['advisories' => []]);
        $pinned = new Finding('vendor/pkg', 'dev-main', Verdict::PINNED, [new Signal('S6', 'warn', 'pinned to branch snapshot dev-main'), $s9], ['vendor/pkg'], null, null, null, false, ['vendor/pkg']);
        $ok = new Finding('vendor/pkg', '1.0.0', Verdict::OK, [$s9], ['vendor/pkg'], null, null, null, false, ['vendor/pkg']);

        self::assertFalse($pinned->hasUnfixableAdvisory());
        self::assertSame(Priority::HIGH, $pinned->priority());
        self::assertSame('pinned to branch snapshot dev-main; 1 security advisory affects 1.0.0 (CVE-2024-0001)', $pinned->ownEvidence());
        self::assertFalse($ok->hasUnfixableAdvisory());
        self::assertSame(Priority::NONE, $ok->priority());
        self::assertSame('1 security advisory affects 1.0.0 (CVE-2024-0001)', $ok->ownEvidence());
    }

    public function testTheNoFixVerdictsAreExactlyTheThreeWithNoUpstreamToWaitFor(): void
    {
        self::assertSame([Verdict::ABANDONED, Verdict::SILENT, Verdict::LEFT_BEHIND], Finding::NO_FIX_VERDICTS);
        $s9 = new Signal('S9', 'warn', 'advisory', []);
        foreach (Verdict::all() as $verdict) {
            $finding = new Finding('vendor/pkg', '1.0.0', $verdict, [$s9], ['vendor/pkg'], null, null);
            self::assertSame(\in_array($verdict, Finding::NO_FIX_VERDICTS, true), $finding->hasUnfixableAdvisory(), $verdict);
        }
    }

    /** The label's reason is read first: S6 before S5 on a pinned finding, S8 before S5 on a left-behind one. */
    public function testTheSignalThatDecidedTheVerdictOpensTheEvidence(): void
    {
        $s5 = new Signal('S5', 'warn', 'released 2020-09-28, before PHP 8.4 GA (2024-11-21); php constraint ">=7.3" has no upper bound');
        $s6 = new Signal('S6', 'warn', 'pinned to branch snapshot dev-master');
        $s8 = new Signal('S8', 'high', 'branch 3.x last released 2020-09-28 (6.0 years ago); 7.x released 7.0.0 (2026-02-06)');
        $s7 = new Signal('S7', 'info', 'pulls in 1 flagged package: a/b (stale)');

        $pinned = new Finding('vendor/pkg', 'dev-master', Verdict::PINNED, [$s5, $s6, $s7], ['vendor/pkg'], null, null);
        $leftBehind = new Finding('vendor/pkg', '3.1.1', Verdict::LEFT_BEHIND, [$s5, $s8], ['root/app', 'vendor/pkg'], null, null);
        $oldPromise = new Finding('vendor/pkg', '3.1.1', Verdict::OLD_PROMISE, [$s5, new Signal('S8', 'warn', 'branch 3.x …')], ['vendor/pkg'], null, null);

        self::assertSame($s6->summary().'; '.$s5->summary().'; '.$s7->summary(), $pinned->evidence(), 'S7 still closes the line');
        self::assertSame($s8->summary().'; '.$s5->summary(), $leftBehind->ownEvidence());
        self::assertSame($s5->summary().'; branch 3.x …', $oldPromise->ownEvidence(), 'signal order when the deciding one is already first');
        $signals = $pinned->toArray()['signals'];
        self::assertIsArray($signals);
        self::assertSame(['S5', 'S6', 'S7'], array_column($signals, 'id'), 'the JSON keeps signal order');
    }

    public function testNoteWhenNoSignals(): void
    {
        $finding = new Finding('private/thing', '3.0.0', Verdict::UNKNOWN, [], [], null, null, 'not from a Composer repository, not checked');
        self::assertSame('not from a Composer repository, not checked', $finding->evidence());
        self::assertNull($finding->toArray()['data_date']);
    }

    public function testEvidenceIsEmptyStringWhenNoSignalsAndNoNote(): void
    {
        $finding = new Finding('private/thing', '3.0.0', Verdict::UNKNOWN, [], [], null, null);
        self::assertSame('', $finding->evidence());
    }

    public function testToArrayIncludesAllowlistReasonAndNote(): void
    {
        $finding = new Finding('vendor/pkg', '1.2.3', Verdict::FINISHED, [], ['vendor/pkg'], 'audited by security team', null, 'no signals fired');
        $array = $finding->toArray();
        self::assertSame('vendor/pkg', $array['package']);
        self::assertSame('1.2.3', $array['version']);
        self::assertSame('finished', $array['verdict']);
        self::assertSame('no signals fired', $array['evidence']);
        self::assertSame('audited by security team', $array['allowlist_reason']);
        self::assertSame('no signals fired', $array['note']);
        self::assertSame(['vendor/pkg'], $array['chain']);
    }

    public function testFindingsAreProdByDefault(): void
    {
        $finding = new Finding('vendor/pkg', '1.2.3', Verdict::ABANDONED, [], ['vendor/pkg'], null, null);
        self::assertFalse($finding->isDev());
        self::assertTrue($finding->isDirect());
        self::assertSame(Priority::CRITICAL, $finding->priority());
    }

    public function testDevIsTheLastConstructorParameterAndLowersThePriority(): void
    {
        $finding = new Finding('vendor/pkg', '1.2.3', Verdict::ABANDONED, [], ['vendor/pkg'], null, null, null, true);
        self::assertTrue($finding->isDev());
        self::assertSame(Priority::HIGH, $finding->priority());
    }

    public function testATransitiveDevFindingIsLoweredTwice(): void
    {
        $finding = new Finding('vendor/pkg', '1.2.3', Verdict::ABANDONED, [], ['vendor/root', 'vendor/pkg'], null, null, null, true);
        self::assertFalse($finding->isDirect());
        self::assertSame(Priority::MEDIUM, $finding->priority());
    }

    public function testAnEmptyChainCountsAsTransitive(): void
    {
        $finding = new Finding('vendor/pkg', '1.2.3', Verdict::ABANDONED, [], [], null, null);
        self::assertFalse($finding->isDirect());
        self::assertSame(Priority::HIGH, $finding->priority());
    }

    public function testAnUnflaggedFindingHasNoPriority(): void
    {
        $finding = new Finding('vendor/pkg', '1.2.3', Verdict::OK, [], ['vendor/pkg'], null, null);
        self::assertSame(Priority::NONE, $finding->priority());
    }

    public function testToArrayCarriesPriorityDirectAndDevRightAfterTheVerdict(): void
    {
        $finding = new Finding('vendor/pkg', '1.2.3', Verdict::STALE, [], ['vendor/root', 'vendor/pkg'], null, null, null, true);
        $array = $finding->toArray();
        self::assertSame(
            ['package', 'version', 'verdict', 'priority', 'direct', 'dev', 'signals', 'chain', 'direct_dependents', 'evidence', 'allowlist_reason', 'note', 'data_date'],
            array_keys($array)
        );
        self::assertSame(Priority::LOW, $array['priority']);
        self::assertFalse($array['direct']);
        self::assertTrue($array['dev']);
    }

    public function testDirectDependentsDefaultToNoneAndAreCarriedInTheArray(): void
    {
        $bare = new Finding('vendor/pkg', '1.0.0', Verdict::STALE, [], ['vendor/root', 'vendor/pkg'], null, null);
        self::assertSame([], $bare->directDependents());
        self::assertSame([], $bare->otherDirectDependents());
        self::assertSame([], $bare->toArray()['direct_dependents']);

        $finding = new Finding('vendor/pkg', '1.0.0', Verdict::STALE, [], ['vendor/root', 'vendor/pkg'], null, null, null, false, ['vendor/other', 'vendor/root']);
        self::assertSame(['vendor/other', 'vendor/root'], $finding->directDependents());
        self::assertSame(['vendor/other', 'vendor/root'], $finding->toArray()['direct_dependents']);
        // The chain's own root is what "via" already shows; the others are what is left.
        self::assertSame(['vendor/other'], $finding->otherDirectDependents());
    }

    public function testADirectPackageAlsoRequiredByAnotherRootListsThatRootAsOther(): void
    {
        $finding = new Finding('vendor/pkg', '1.0.0', Verdict::STALE, [], ['vendor/pkg'], null, null, null, false, ['vendor/other', 'vendor/pkg']);
        self::assertTrue($finding->isDirect());
        self::assertSame(['vendor/other'], $finding->otherDirectDependents());
    }

    public function testWithSignalsReturnsANewFindingWithTheVerdictAndEverythingElseKept(): void
    {
        $at = new \DateTimeImmutable('2026-09-14T10:00:00+00:00');
        $original = new Finding('vendor/pkg', '1.0.0', Verdict::OK, [], ['vendor/pkg'], null, $at, null, true, ['vendor/pkg']);
        $s7 = new Signal(Signal::S7, Signal::LEVEL_INFO, 'pulls in 1 flagged package: vendor/dep (stale)', ['flagged' => 1, 'packages' => []]);

        $annotated = $original->withSignals([$s7]);

        self::assertNotSame($original, $annotated);
        self::assertSame([], $original->signals());
        self::assertSame([$s7], $annotated->signals());
        self::assertSame(Verdict::OK, $annotated->verdict());
        self::assertSame(Priority::NONE, $annotated->priority());
        self::assertSame('pulls in 1 flagged package: vendor/dep (stale)', $annotated->evidence());
        self::assertTrue($annotated->isDev());
        self::assertSame($at, $annotated->dataDate());
        self::assertSame(['vendor/pkg'], $annotated->directDependents());
    }

    public function testTheNoteSurvivesS7AndOwnEvidenceLeavesS7Out(): void
    {
        $s7 = new Signal(Signal::S7, Signal::LEVEL_INFO, 'pulls in 2 flagged packages: a/b (stale), c/d (stale)', ['flagged' => 2, 'packages' => []]);
        $unchecked = new Finding('local/pkg', 'dev-main', Verdict::UNKNOWN, [$s7], ['local/pkg'], null, null, 'not from a Composer repository, not checked');
        self::assertSame('not from a Composer repository, not checked; pulls in 2 flagged packages: a/b (stale), c/d (stale)', $unchecked->evidence());
        self::assertSame('not from a Composer repository, not checked', $unchecked->ownEvidence());

        $own = new Signal('S2', 'warn', 'last release 2022-05-20 (4.3 years ago)');
        $stale = new Finding('vendor/pkg', '1.0.0', Verdict::STALE, [$own, $s7], ['vendor/pkg'], null, null, 'a note nobody reads');
        self::assertSame('last release 2022-05-20 (4.3 years ago); pulls in 2 flagged packages: a/b (stale), c/d (stale)', $stale->evidence());
        self::assertSame('last release 2022-05-20 (4.3 years ago)', $stale->ownEvidence());

        $clean = new Finding('vendor/ok', '1.0.0', Verdict::OK, [], ['vendor/ok'], null, null);
        self::assertSame('', $clean->evidence());
        self::assertSame('', $clean->ownEvidence());
    }

    public function testEvidenceLineAppendsTheAllowlistReasonAfterEverythingElse(): void
    {
        $own = new Signal('S2', 'warn', 'last release 2022-05-20 (4.3 years ago)');
        $s7 = new Signal(Signal::S7, 'info', 'pulls in 1 flagged package: vendor/leaf (stale)');
        $allowlisted = new Finding('vendor/pkg', '1.0.0', Verdict::FINISHED, [$own, $s7], ['vendor/pkg'], 'interfaces', null);
        $allowlistedWithoutEvidence = new Finding('vendor/pkg', '1.0.0', Verdict::FINISHED, [], ['vendor/pkg'], 'interfaces', null);
        $notAllowlisted = new Finding('vendor/pkg', '1.0.0', Verdict::STALE, [$own], ['vendor/pkg'], null, null);

        self::assertSame(
            'last release 2022-05-20 (4.3 years ago); pulls in 1 flagged package: vendor/leaf (stale); allowlisted: interfaces',
            $allowlisted->evidenceLine()
        );
        self::assertSame('allowlisted: interfaces', $allowlistedWithoutEvidence->evidenceLine());
        self::assertSame('last release 2022-05-20 (4.3 years ago)', $notAllowlisted->evidenceLine());
    }
}
