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
    /** An S9 advisory row no listed release fixes, as {@see \Lockrot\Signal\Rule\AdvisoryRule} writes it. */
    private const OPEN = ['id' => 'PKSA-open', 'fixed_by' => null, 'fixed_on_branch' => false];

    /** @return array{id: string, fixed_by: string, fixed_on_branch: bool} */
    private static function fixed(string $by, bool $onBranch): array
    {
        return ['id' => 'PKSA-fixed-'.$by, 'fixed_by' => $by, 'fixed_on_branch' => $onBranch];
    }

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
            new Signal('S9', 'warn', '1 security advisory affects 1.0.0 (CVE-2024-0001)', ['advisories' => [self::OPEN]]),
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
        $s9 = new Signal('S9', 'warn', '1 security advisory affects 1.0.0 (CVE-2024-0001)', ['advisories' => [self::OPEN]]);
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
        $s9 = new Signal('S9', 'warn', 'advisory', ['advisories' => [self::OPEN]]);
        foreach (Verdict::all() as $verdict) {
            $finding = new Finding('vendor/pkg', '1.0.0', $verdict, [$s9], ['vendor/pkg'], null, null);
            self::assertSame(\in_array($verdict, Finding::NO_FIX_VERDICTS, true), $finding->hasUnfixableAdvisory(), $verdict);
        }
    }

    /** A row without the fix keys (an older JSON, a hand-built signal) is an advisory nothing fixes; a list that is not one is no advisory. */
    public function testAnAdvisoryRowWithoutFixKeysCountsAsUnfixedAndAMalformedListAsNone(): void
    {
        $bare = new Finding('vendor/pkg', '1.0.0', Verdict::LEFT_BEHIND, [new Signal('S9', 'warn', 'x', ['advisories' => [['id' => 'PKSA-1']]])], ['vendor/pkg'], null, null);
        self::assertTrue($bare->hasUnfixableAdvisory());
        self::assertSame('x; no fix expected', $bare->ownEvidence());

        $fixedSomewhere = new Finding('vendor/pkg', '1.0.0', Verdict::LEFT_BEHIND, [new Signal('S9', 'warn', 'x', ['advisories' => [['id' => 'PKSA-1', 'fixed_by' => '2.0.0']]])], ['vendor/pkg'], null, null);
        self::assertSame('x; no fix expected on 1.x', $fixedSomewhere->ownEvidence(), 'no fixed_on_branch key reads as off the branch');

        $onBranchAndOpen = new Finding('vendor/pkg', '1.0.0', Verdict::LEFT_BEHIND, [new Signal('S9', 'warn', 'x', ['advisories' => [self::fixed('1.9.0', true), self::OPEN]])], ['vendor/pkg'], null, null);
        self::assertSame('x; no fix expected', $onBranchAndOpen->ownEvidence(), 'a fix on the branch is not the kind that qualifies the clause');

        $malformed = new Finding('vendor/pkg', '1.0.0', Verdict::ABANDONED, [new Signal('S9', 'warn', 'x', ['advisories' => 'not a list'])], ['vendor/pkg'], null, null);
        self::assertFalse($malformed->hasUnfixableAdvisory());
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
        $oldPromise = new Finding('vendor/pkg', '3.1.1', Verdict::OLD_PROMISE, [$s5, new Signal('S2', 'warn', 'last release 2022-01-01 …')], ['vendor/pkg'], null, null);

        self::assertSame($s6->summary().'; '.$s5->summary().'; '.$s7->summary(), $pinned->evidence(), 'S7 still closes the line');
        self::assertSame($s8->summary().'; '.$s5->summary(), $leftBehind->ownEvidence());
        self::assertSame($s5->summary().'; last release 2022-01-01 …', $oldPromise->ownEvidence(), 'signal order when the deciding one is already first');
        $signals = $pinned->toArray()['signals'];
        self::assertIsArray($signals);
        self::assertSame(['S5', 'S6', 'S7'], array_column($signals, 'id'), 'the JSON keeps signal order');

        $s7First = new Finding('vendor/pkg', 'dev-master', Verdict::PINNED, [$s7, $s6, $s5], ['vendor/pkg'], null, null);
        self::assertSame($s6->summary().'; '.$s5->summary(), $s7First->ownEvidence(), 'S7 anywhere in the list is skipped, not a stop');
    }

    /** S8 at either level makes the verdict `left-behind` ({@see VerdictEngine}); the raise follows the verdict, not the signal. */
    public function testAnAdvisoryOnALeftBehindBranchIsUnfixableAtEitherLevel(): void
    {
        $s9 = new Signal('S9', 'warn', '14 security advisories affect 6.5.5 (CVE-a, CVE-b, CVE-c and 11 more)', ['advisories' => [self::OPEN]]);
        $s8warn = new Signal('S8', 'warn', 'branch 6.x last released 2022-06-20 (4.2 years ago); 8.x released 8.2.0 (2026-09-06)');
        $s5 = new Signal('S5', 'warn', 'released 2020-06-16, before PHP 8.4 GA (2024-11-21); php constraint ">=5.5" has no upper bound');

        $leftBehind = new Finding('guzzlehttp/guzzle', '6.5.5', Verdict::LEFT_BEHIND, [$s5, $s8warn, $s9], ['guzzlehttp/guzzle'], null, null, null, false, ['guzzlehttp/guzzle']);
        $oldPromise = new Finding('guzzlehttp/guzzle', '6.5.5', Verdict::OLD_PROMISE, [$s5, $s9], ['guzzlehttp/guzzle'], null, null, null, false, ['guzzlehttp/guzzle']);
        $finished = new Finding('guzzlehttp/guzzle', '6.5.5', Verdict::FINISHED, [$s8warn, $s9], ['guzzlehttp/guzzle'], 'frozen', null, null, false, ['guzzlehttp/guzzle']);

        self::assertTrue($leftBehind->hasUnfixableAdvisory());
        self::assertSame(Priority::CRITICAL, $leftBehind->priority(), 'high raised to critical');
        self::assertSame($s8warn->summary().'; '.$s5->summary().'; '.$s9->summary().'; no fix expected', $leftBehind->ownEvidence());
        self::assertFalse($oldPromise->hasUnfixableAdvisory(), 'an open php constraint says nothing about whether a fix is coming');
        self::assertSame(Priority::HIGH, $oldPromise->priority());
        self::assertFalse($finished->hasUnfixableAdvisory(), 'an allowlisted package is never raised: the allowlist decided');
    }

    /**
     * swiftmailer 6.1.3, abandoned: CVE-2024-28859 is fixed by 6.3.0, the package's last release.
     * The fix is out, the raise is not earned, and the line must not say "no fix expected".
     */
    public function testAnAdvisoryAlreadyFixedByAListedReleaseNeitherRaisesNorSaysNoFixExpected(): void
    {
        $s9 = new Signal('S9', 'warn', '1 security advisory affects v6.1.3 (CVE-2024-28859); fixed by 6.3.0', ['advisories' => [self::fixed('6.3.0', false)]]);
        $abandoned = new Finding('swiftmailer/swiftmailer', 'v6.1.3', Verdict::ABANDONED, [new Signal('S1', 'high', 'marked abandoned by its repository'), $s9], ['swiftmailer/swiftmailer'], null, null, null, false, ['swiftmailer/swiftmailer']);

        self::assertFalse($abandoned->hasUnfixableAdvisory());
        self::assertSame(Priority::CRITICAL, $abandoned->priority(), 'the base, not a raise');
        self::assertSame('marked abandoned by its repository; 1 security advisory affects v6.1.3 (CVE-2024-28859); fixed by 6.3.0', $abandoned->ownEvidence());
    }

    /**
     * symfony/http-foundation v3.4.18 on the 3.x branch the upstream left: one CVE fixed by
     * v3.4.47 (on the branch — reachable), three by v8.1.7 (the fix the branch will not get). The
     * three earn the raise, and the clause names the branch, since "no fix expected" alone would
     * contradict "fixed by v8.1.7" on the same line.
     */
    public function testOnALeftBehindBranchOnlyAFixOnTheBranchCounts(): void
    {
        $s8 = new Signal('S8', 'high', 'branch 3.x last released 2020-10-24 (5.9 years ago); 8.x released v8.1.7 (2026-09-14)');
        $s9 = new Signal('S9', 'warn', '4 security advisories affect v3.4.18 (a, b, c and 1 more); 3 fixed by v8.1.7, 1 fixed by v3.4.47', ['advisories' => [self::fixed('v8.1.7', false), self::fixed('v8.1.7', false), self::fixed('v8.1.7', false), self::fixed('v3.4.47', true)]]);
        $finding = new Finding('symfony/http-foundation', 'v3.4.18', Verdict::LEFT_BEHIND, [$s8, $s9], ['symfony/http-foundation'], null, null, null, false, ['symfony/http-foundation']);

        self::assertTrue($finding->hasUnfixableAdvisory());
        self::assertSame(Priority::CRITICAL, $finding->priority());
        self::assertStringEndsWith('; 3 fixed by v8.1.7, 1 fixed by v3.4.47; no fix expected on 3.x', $finding->ownEvidence());

        $allOnBranch = new Finding('symfony/http-foundation', 'v3.4.18', Verdict::LEFT_BEHIND, [$s8, new Signal('S9', 'warn', '1 security advisory affects v3.4.18 (a); fixed by v3.4.47', ['advisories' => [self::fixed('v3.4.47', true)]])], ['symfony/http-foundation'], null, null, null, false, ['symfony/http-foundation']);
        self::assertFalse($allOnBranch->hasUnfixableAdvisory(), 'a composer update inside the constraint gets the fix');
        self::assertSame(Priority::HIGH, $allOnBranch->priority());
        self::assertStringEndsWith('; fixed by v3.4.47', $allOnBranch->ownEvidence());

        $openEverywhere = new Finding('symfony/http-foundation', 'v3.4.18', Verdict::LEFT_BEHIND, [$s8, new Signal('S9', 'warn', '1 security advisory affects v3.4.18 (a)', ['advisories' => [self::OPEN]])], ['symfony/http-foundation'], null, null, null, false, ['symfony/http-foundation']);
        self::assertStringEndsWith('(a); no fix expected', $openEverywhere->ownEvidence(), 'nothing fixes it anywhere: no branch to point away from');

        $abandonedWithAFixElsewhere = new Finding('vendor/pkg', '1.0.0', Verdict::ABANDONED, [new Signal('S1', 'high', 'marked abandoned by its repository'), new Signal('S9', 'warn', '2 security advisories affect 1.0.0 (a, b); 1 fixed by 2.0.0', ['advisories' => [self::fixed('2.0.0', false), self::OPEN]])], ['vendor/pkg'], null, null, null, false, ['vendor/pkg']);
        self::assertTrue($abandonedWithAFixElsewhere->hasUnfixableAdvisory(), 'one of the two has no fix at all');
        self::assertStringEndsWith('; 1 fixed by 2.0.0; no fix expected', $abandonedWithAFixElsewhere->ownEvidence(), 'an abandoned package has no branch to qualify with');
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
