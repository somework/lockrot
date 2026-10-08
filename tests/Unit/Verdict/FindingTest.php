<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Lockrot\Allowlist\AllowlistEntry;
use Lockrot\Analyzer\Libyears;
use Lockrot\Analyzer\LibyearsMeasurement;
use Lockrot\Data\Advisory\Advisory;
use Lockrot\Legacy\Priority013;
use Lockrot\Lock\LockFile;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\PhpFloor;
use Lockrot\Signal\Rule\NotCheckedRule;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\Origins;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\FindingDetails;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\Score;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $finding = (new FindingBuilder())->withPackage('phpzip/phpzip')->withVersion('2.0.8')->withVerdict(Verdict::SILENT)->withSignals($signals)->withChain(['wallabag/wallabag', 'phpzip/phpzip'])->withDataDate(new \DateTimeImmutable('2026-09-14T10:00:00+00:00'))->build();
        self::assertSame('last release 2015-11-16 (10.8 years ago); last push 2015-11-16 (10.8 years ago)', $finding->evidence());
        $array = $finding->toArray();
        self::assertSame('phpzip/phpzip', $array['package']);
        self::assertSame('2.0.8', $array['version']);
        self::assertSame('high', $array['verdict'], 'report-2 writes the grade: silent halved for reach');
        self::assertSame('silent', $array['lead']);
        self::assertIsArray($array['signals']);
        self::assertSame(['S2', 'S4'], array_column($array['signals'], 'id'));
        self::assertSame(['high', 'high'], array_column($array['signals'], 'level'));
        self::assertSame(
            ['last release 2015-11-16 (10.8 years ago)', 'last push 2015-11-16 (10.8 years ago)'],
            array_column($array['signals'], 'summary')
        );
        self::assertSame([['years' => 10.8], ['years' => 10.8]], array_column($array['signals'], 'data'));
        self::assertStringStartsWith('silent: last release ', JsonPath::stringAt($array, ['evidence']), 'the counted flag and its sentence');
        self::assertSame('2026-09-14T10:00:00+00:00', $array['data_date']);
        self::assertSame(['wallabag/wallabag', 'phpzip/phpzip'], $array['chain']);
    }

    public function testAnAdvisoryOnAnAbandonedPackageRaisesThePriorityAndSaysNoFixIsExpected(): void
    {
        $signals = [
            new Signal('S1', 'high', 'marked abandoned by its repository'),
            new Signal('S9', 'warn', '1 security advisory affects 1.0.0 (CVE-2024-0001)', ['advisories' => [self::OPEN]]),
        ];
        $direct = (new FindingBuilder())->withVerdict(Verdict::ABANDONED)->withSignals($signals)->withDirectDependents(['vendor/pkg'])->build();
        $transitiveDev = (new FindingBuilder())->withVerdict(Verdict::ABANDONED)->withSignals($signals)->withChain(['root/app', 'vendor/pkg'])->withDev(true)->withDirectDependents(['root/app'])->build();

        self::assertTrue($direct->hasUnfixableAdvisory());
        self::assertSame(Priority013::CRITICAL, $direct->priority());
        self::assertSame(Priority013::HIGH, $transitiveDev->priority(), 'medium raised one step');
        self::assertSame('marked abandoned by its repository; 1 security advisory affects 1.0.0 (CVE-2024-0001); no fix expected', $direct->ownEvidence());
        self::assertSame($direct->ownEvidence(), $direct->evidence());
    }

    public function testAnAdvisoryAloneChangesNeitherThePriorityNorTheWording(): void
    {
        $s9 = new Signal('S9', 'warn', '1 security advisory affects 1.0.0 (CVE-2024-0001)', ['advisories' => [self::OPEN]]);
        $pinned = (new FindingBuilder())->withVersion('dev-main')->withVerdict(Verdict::PINNED)->withSignals([new Signal('S6', 'warn', 'pinned to branch snapshot dev-main'), $s9])->withDirectDependents(['vendor/pkg'])->build();
        $ok = (new FindingBuilder())->withSignals([$s9])->withDirectDependents(['vendor/pkg'])->build();

        self::assertFalse($pinned->hasUnfixableAdvisory());
        self::assertSame(Priority013::HIGH, $pinned->priority());
        self::assertSame('pinned to branch snapshot dev-main; 1 security advisory affects 1.0.0 (CVE-2024-0001)', $pinned->ownEvidence());
        self::assertFalse($ok->hasUnfixableAdvisory());
        self::assertSame(Priority013::NONE, $ok->priority());
        self::assertSame('1 security advisory affects 1.0.0 (CVE-2024-0001)', $ok->ownEvidence());
    }

    public function testTheNoFixVerdictsAreExactlyTheThreeWithNoUpstreamToWaitFor(): void
    {
        self::assertSame([Verdict::ABANDONED, Verdict::SILENT, Verdict::LEFT_BEHIND], Finding::NO_FIX_VERDICTS);
        $s9 = new Signal('S9', 'warn', 'advisory', ['advisories' => [self::OPEN]]);
        foreach (Verdict::all() as $verdict) {
            $finding = (new FindingBuilder())->withVerdict($verdict)->withSignals([$s9])->build();
            self::assertSame(\in_array($verdict, Finding::NO_FIX_VERDICTS, true), $finding->hasUnfixableAdvisory(), $verdict);
        }
    }

    /** A row without the fix keys (an earlier report, a hand-built signal) is an advisory nothing fixes. A list that is not one is no advisory. */
    public function testAnAdvisoryRowWithoutFixKeysCountsAsUnfixedAndAMalformedListAsNone(): void
    {
        $bare = (new FindingBuilder())->withVerdict(Verdict::LEFT_BEHIND)->withSignals([new Signal('S9', 'warn', 'x', ['advisories' => [['id' => 'PKSA-1']]])])->build();
        self::assertTrue($bare->hasUnfixableAdvisory());
        self::assertSame('x; no fix expected', $bare->ownEvidence());

        $fixedSomewhere = (new FindingBuilder())->withVerdict(Verdict::LEFT_BEHIND)->withSignals([new Signal('S9', 'warn', 'x', ['advisories' => [['id' => 'PKSA-1', 'fixed_by' => '2.0.0']]])])->build();
        self::assertSame('x; no fix expected on 1.x', $fixedSomewhere->ownEvidence(), 'no fixed_on_branch key reads as off the branch');

        $onBranchAndOpen = (new FindingBuilder())->withVerdict(Verdict::LEFT_BEHIND)->withSignals([new Signal('S9', 'warn', 'x', ['advisories' => [self::fixed('1.9.0', true), self::OPEN]])])->build();
        self::assertSame('x; no fix expected', $onBranchAndOpen->ownEvidence(), 'a fix on the branch is not the kind that qualifies the clause');

        $malformed = (new FindingBuilder())->withVerdict(Verdict::ABANDONED)->withSignals([new Signal('S9', 'warn', 'x', ['advisories' => 'not a list'])])->build();
        self::assertFalse($malformed->hasUnfixableAdvisory());
    }

    /** The label's reason is read first: S6 before S5 on a pinned finding, S8 before S5 on a left-behind one. */
    public function testTheSignalThatDecidedTheVerdictOpensTheEvidence(): void
    {
        $s5 = new Signal('S5', 'warn', 'released 2020-09-28, before PHP 8.4 GA (2024-11-21); php constraint ">=7.3" has no upper bound');
        $s6 = new Signal('S6', 'warn', 'pinned to branch snapshot dev-master');
        $s8 = new Signal('S8', 'high', 'branch 3.x last released 2020-09-28 (6.0 years ago); 7.x released 7.0.0 (2026-02-06)');
        $s7 = new Signal('S7', 'info', 'pulls in 1 flagged package: a/b (stale)');

        $pinned = (new FindingBuilder())->withVersion('dev-master')->withVerdict(Verdict::PINNED)->withSignals([$s5, $s6, $s7])->build();
        $leftBehind = (new FindingBuilder())->withVersion('3.1.1')->withVerdict(Verdict::LEFT_BEHIND)->withSignals([$s5, $s8])->withChain(['root/app', 'vendor/pkg'])->build();
        $oldPromise = (new FindingBuilder())->withVersion('3.1.1')->withVerdict(Verdict::OLD_PROMISE)->withSignals([$s5, new Signal('S2', 'warn', 'last release 2022-01-01 …')])->build();

        self::assertSame($s6->summary().'; '.$s5->summary().'; '.$s7->summary(), $pinned->evidence(), 'S7 still closes the line');
        self::assertSame($s8->summary().'; '.$s5->summary(), $leftBehind->ownEvidence());
        self::assertSame($s5->summary().'; last release 2022-01-01 …', $oldPromise->ownEvidence(), 'signal order when the deciding one is already first');
        $signals = $pinned->toArray()['signals'];
        self::assertIsArray($signals);
        self::assertSame(['S5', 'S6', 'S7'], array_column($signals, 'id'), 'the JSON keeps signal order');

        $s7First = (new FindingBuilder())->withVersion('dev-master')->withVerdict(Verdict::PINNED)->withSignals([$s7, $s6, $s5])->build();
        self::assertSame($s6->summary().'; '.$s5->summary(), $s7First->ownEvidence(), 'S7 anywhere in the list is skipped, not a stop');
    }

    /**
     * The constraint S8 suggests is printed for a direct requirement — the project's own line in
     * composer.json — and not for a transitive one, whose parent owns the requirement. The data
     * keeps it either way.
     */
    public function testALeftBehindDirectRequirementSaysWhatToRequire(): void
    {
        $s8 = new Signal('S8', 'warn', 'branch 6.x last released 2022-06-20 (4.2 years ago); 8.x released 8.2.0 (2026-09-06)', ['suggested_constraint' => '^8.2']);
        $s5 = new Signal('S5', 'warn', 'released 2022-06-20, before PHP 8.4 GA (2024-11-21); php constraint ">=5.5" has no upper bound');

        $direct = (new FindingBuilder())->withPackage('guzzlehttp/guzzle')->withVersion('6.5.8')->withVerdict(Verdict::LEFT_BEHIND)->withSignals([$s5, $s8])->withChain(['guzzlehttp/guzzle'])->build();
        $transitive = (new FindingBuilder())->withPackage('guzzlehttp/guzzle')->withVersion('6.5.8')->withVerdict(Verdict::LEFT_BEHIND)->withSignals([$s5, $s8])->withChain(['root/app', 'guzzlehttp/guzzle'])->build();
        $finished = (new FindingBuilder())->withPackage('guzzlehttp/guzzle')->withVersion('6.5.8')->withVerdict(Verdict::FINISHED)->withSignals([$s8])->withChain(['guzzlehttp/guzzle'])->withAllowlistReason('frozen')->build();
        $noConstraint = (new FindingBuilder())->withPackage('guzzlehttp/guzzle')->withVersion('6.5.8')->withVerdict(Verdict::LEFT_BEHIND)->withSignals([new Signal('S8', 'warn', $s8->summary())])->withChain(['guzzlehttp/guzzle'])->build();

        self::assertSame($s8->summary().'; require ^8.2 to follow; '.$s5->summary(), $direct->ownEvidence());
        self::assertSame($s8->summary().'; '.$s5->summary(), $transitive->ownEvidence(), 'not the project\'s line to change');
        self::assertSame($s8->summary(), $finished->ownEvidence(), 'the allowlist decided; nothing to follow');
        self::assertSame($s8->summary(), $noConstraint->ownEvidence(), 'no constraint on the signal, no clause');
        $signals = $direct->toArray()['signals'];
        self::assertIsArray($signals);
        self::assertSame(['id' => 'S8', 'level' => 'warn', 'summary' => $s8->summary(), 'data' => ['suggested_constraint' => '^8.2']], $signals[1] ?? null, 'the data travels into --format=json');
    }

    /** S8 at either level makes the verdict `left-behind` ({@see VerdictEngine}). The raise follows the verdict, not the signal. */
    public function testAnAdvisoryOnALeftBehindBranchIsUnfixableAtEitherLevel(): void
    {
        $s9 = new Signal('S9', 'warn', '14 security advisories affect 6.5.5 (CVE-a, CVE-b, CVE-c and 11 more)', ['advisories' => [self::OPEN]]);
        $s8warn = new Signal('S8', 'warn', 'branch 6.x last released 2022-06-20 (4.2 years ago); 8.x released 8.2.0 (2026-09-06)');
        $s5 = new Signal('S5', 'warn', 'released 2020-06-16, before PHP 8.4 GA (2024-11-21); php constraint ">=5.5" has no upper bound');

        $leftBehind = (new FindingBuilder())->withPackage('guzzlehttp/guzzle')->withVersion('6.5.5')->withVerdict(Verdict::LEFT_BEHIND)->withSignals([$s5, $s8warn, $s9])->withChain(['guzzlehttp/guzzle'])->withDirectDependents(['guzzlehttp/guzzle'])->build();
        $oldPromise = (new FindingBuilder())->withPackage('guzzlehttp/guzzle')->withVersion('6.5.5')->withVerdict(Verdict::OLD_PROMISE)->withSignals([$s5, $s9])->withChain(['guzzlehttp/guzzle'])->withDirectDependents(['guzzlehttp/guzzle'])->build();
        $finished = (new FindingBuilder())->withPackage('guzzlehttp/guzzle')->withVersion('6.5.5')->withVerdict(Verdict::FINISHED)->withSignals([$s8warn, $s9])->withChain(['guzzlehttp/guzzle'])->withAllowlistReason('frozen')->withDirectDependents(['guzzlehttp/guzzle'])->build();

        self::assertTrue($leftBehind->hasUnfixableAdvisory());
        self::assertSame(Priority013::CRITICAL, $leftBehind->priority(), 'high raised to critical');
        self::assertSame($s8warn->summary().'; '.$s5->summary().'; '.$s9->summary().'; no fix expected', $leftBehind->ownEvidence());
        self::assertFalse($oldPromise->hasUnfixableAdvisory(), 'an open php constraint says nothing about whether a fix is coming');
        self::assertSame(Priority013::HIGH, $oldPromise->priority());
        self::assertFalse($finished->hasUnfixableAdvisory(), 'an allowlisted package is never raised: the allowlist decided');
    }

    /**
     * swiftmailer 5.4.12, abandoned with symfony/mailer named as its replacement, an advisory no
     * release fixes: the fix is not coming here, and the line says where to go instead of only
     * that. Without a replacement, or under another no-fix verdict, the bare clause stays.
     */
    public function testAnAbandonedPackageWithAReplacementSaysWhereToMigrate(): void
    {
        $s9 = new Signal('S9', 'warn', '1 security advisory affects v5.4.12 (CVE-2016-10074)', ['advisories' => [self::OPEN]]);
        $replaced = new Signal('S1', 'high', 'marked abandoned by its repository, replacement: symfony/mailer', ['replacement' => 'symfony/mailer']);
        $bare = new Signal('S1', 'high', 'marked abandoned by its repository', ['replacement' => '']);

        $withReplacement = (new FindingBuilder())->withPackage('swiftmailer/swiftmailer')->withVersion('v5.4.12')->withVerdict(Verdict::ABANDONED)->withSignals([$replaced, $s9])->withChain(['root/app', 'swiftmailer/swiftmailer'])->build();
        $withoutReplacement = (new FindingBuilder())->withPackage('swiftmailer/swiftmailer')->withVersion('v5.4.12')->withVerdict(Verdict::ABANDONED)->withSignals([$bare, $s9])->withChain(['swiftmailer/swiftmailer'])->build();
        $silent = (new FindingBuilder())->withPackage('swiftmailer/swiftmailer')->withVersion('v5.4.12')->withVerdict(Verdict::SILENT)->withSignals([$replaced, $s9])->withChain(['swiftmailer/swiftmailer'])->build();
        $itself = (new FindingBuilder())->withPackage('swiftmailer/swiftmailer')->withVersion('v5.4.12')->withVerdict(Verdict::ABANDONED)->withSignals([new Signal('S1', 'high', 'marked abandoned by its repository, replacement: swiftmailer/swiftmailer', ['replacement' => 'swiftmailer/swiftmailer']), $s9])->withChain(['swiftmailer/swiftmailer'])->build();
        $fixed = (new FindingBuilder())->withPackage('swiftmailer/swiftmailer')->withVersion('v5.4.12')->withVerdict(Verdict::ABANDONED)->withSignals([$replaced, new Signal('S9', 'warn', 'x; fixed by 6.3.0', ['advisories' => [self::fixed('6.3.0', false)]])])->withChain(['swiftmailer/swiftmailer'])->build();

        self::assertSame($replaced->summary().'; '.$s9->summary().'; no fix expected; migrate to symfony/mailer', $withReplacement->ownEvidence(), 'transitive or not: the replacement is where the fix is');
        self::assertTrue($withReplacement->hasUnfixableAdvisory());
        self::assertSame($bare->summary().'; '.$s9->summary().'; no fix expected', $withoutReplacement->ownEvidence(), 'an empty replacement is none');
        self::assertSame($replaced->summary().'; '.$s9->summary().'; no fix expected', $silent->ownEvidence(), 'only abandoned points at the replacement; under silent the clause stays bare');
        self::assertSame($replaced->summary().'; x; fixed by 6.3.0', $fixed->ownEvidence(), 'the fix is out: nothing to migrate for');
        self::assertSame('marked abandoned by its repository, replacement: swiftmailer/swiftmailer; '.$s9->summary().'; no fix expected', $itself->ownEvidence(), 'a repository naming the package itself names nowhere to migrate');

        $freeText = (new FindingBuilder())->withPackage('sensiolabs/framework-extra-bundle')->withVersion('v6.2.10')->withVerdict(Verdict::ABANDONED)->withSignals([new Signal('S1', 'high', 'marked abandoned by its repository, replacement: Symfony', ['replacement' => 'Symfony']), $s9])->withChain(['sensiolabs/framework-extra-bundle'])->build();
        self::assertSame('marked abandoned by its repository, replacement: Symfony; '.$s9->summary().'; no fix expected', $freeText->ownEvidence(), 'free text is not a package to migrate to; the clause stays bare and the text is still read as text above it');
    }

    /**
     * Packagist's `replacement` is free text. swiftmailer names `symfony/mailer`,
     * sensio/framework-extra-bundle names `Symfony`, doctrine/inflector `EnglishInflector from the
     * String component`. Only a package name is a successor: something to migrate to, count and
     * link. The free text stays in the evidence. The JSON field holds the name or null.
     */
    public function testTheSuccessorIsTheReplacementWhenItNamesAPackage(): void
    {
        $s1 = static fn (string $replacement): Signal => new Signal('S1', 'high', 'marked abandoned by its repository, replacement: '.$replacement, ['replacement' => $replacement]);
        $abandoned = static fn (Signal $signal): Finding => (new FindingBuilder())->withPackage('vendor/old')->withVerdict(Verdict::ABANDONED)->withSignals([$signal])->withChain(['vendor/old'])->build();

        self::assertSame('symfony/mailer', $abandoned($s1('symfony/mailer'))->successor());
        self::assertSame('symfony/mailer', $abandoned($s1('symfony/mailer'))->toArray()['replacement']);
        self::assertNull($abandoned($s1('Symfony'))->successor(), 'a vendor is not a package');
        self::assertNull($abandoned($s1('EnglishInflector from the String component'))->successor(), 'a sentence is not a package');
        self::assertNull($abandoned($s1('vendor/Bad Name'))->successor(), 'a name Composer rejects is not one either');
        self::assertNull($abandoned(new Signal('S1', 'high', 'marked abandoned by its repository', ['replacement' => null]))->successor(), 'none named');
        self::assertSame('marked abandoned by its repository, replacement: Symfony', $abandoned($s1('Symfony'))->evidence(), 'the free text is still read as text');

        self::assertNull($abandoned($s1('vendor/old'))->successor(), 'a package is not its own successor');
        self::assertNull($abandoned($s1('Vendor/Old'))->successor(), 'Composer reads a package name without case, and so does this');
        self::assertSame('marked abandoned by its repository, replacement: vendor/old', $abandoned($s1('vendor/old'))->evidence(), 'the free text is still read as text');

        $archivedOnly = (new FindingBuilder())->withPackage('vendor/old')->withVerdict(Verdict::ABANDONED)->withSignals([new Signal('S3', 'high', 'repository archived', [])])->withChain(['vendor/old'])->build();
        self::assertNull($archivedOnly->successor(), 'an archived repository names nothing');
        $silent = (new FindingBuilder())->withPackage('vendor/old')->withVerdict(Verdict::SILENT)->withSignals([$s1('symfony/mailer')])->withChain(['vendor/old'])->build();
        self::assertNull($silent->successor(), 'only an abandoned finding has a successor, whatever S1 says underneath');
    }

    /**
     * swiftmailer 6.1.3, abandoned: CVE-2024-28859 is fixed by 6.3.0, the package's last release.
     * The fix is out, the raise is not earned, and the line must not say "no fix expected".
     */
    public function testAnAdvisoryAlreadyFixedByAListedReleaseNeitherRaisesNorSaysNoFixExpected(): void
    {
        $s9 = new Signal('S9', 'warn', '1 security advisory affects v6.1.3 (CVE-2024-28859); fixed by 6.3.0', ['advisories' => [self::fixed('6.3.0', false)]]);
        $abandoned = (new FindingBuilder())->withPackage('swiftmailer/swiftmailer')->withVersion('v6.1.3')->withVerdict(Verdict::ABANDONED)->withSignals([new Signal('S1', 'high', 'marked abandoned by its repository'), $s9])->withChain(['swiftmailer/swiftmailer'])->withDirectDependents(['swiftmailer/swiftmailer'])->build();

        self::assertFalse($abandoned->hasUnfixableAdvisory());
        self::assertSame(Priority013::CRITICAL, $abandoned->priority(), 'the base, not a raise');
        self::assertSame('marked abandoned by its repository; 1 security advisory affects v6.1.3 (CVE-2024-28859); fixed by 6.3.0', $abandoned->ownEvidence());
    }

    /**
     * symfony/http-foundation v3.4.18 on the 3.x branch the upstream left: one CVE fixed by
     * v3.4.47 (on the branch — reachable), three by v8.1.7 (the fix the branch will not get). The
     * three earn the raise, and the clause names the branch, since "no fix expected" alone
     * contradicts "fixed by v8.1.7" on the same line.
     */
    public function testOnALeftBehindBranchOnlyAFixOnTheBranchCounts(): void
    {
        $s8 = new Signal('S8', 'high', 'branch 3.x last released 2020-10-24 (5.9 years ago); 8.x released v8.1.7 (2026-09-14)');
        $s9 = new Signal('S9', 'warn', '4 security advisories affect v3.4.18 (a, b, c and 1 more); 3 fixed by v8.1.7, 1 fixed by v3.4.47', ['advisories' => [self::fixed('v8.1.7', false), self::fixed('v8.1.7', false), self::fixed('v8.1.7', false), self::fixed('v3.4.47', true)]]);
        $finding = (new FindingBuilder())->withPackage('symfony/http-foundation')->withVersion('v3.4.18')->withVerdict(Verdict::LEFT_BEHIND)->withSignals([$s8, $s9])->withChain(['symfony/http-foundation'])->withDirectDependents(['symfony/http-foundation'])->build();

        self::assertTrue($finding->hasUnfixableAdvisory());
        self::assertSame(Priority013::CRITICAL, $finding->priority());
        self::assertStringEndsWith('; 3 fixed by v8.1.7, 1 fixed by v3.4.47; no fix expected on 3.x', $finding->ownEvidence());

        $allOnBranch = (new FindingBuilder())->withPackage('symfony/http-foundation')->withVersion('v3.4.18')->withVerdict(Verdict::LEFT_BEHIND)->withSignals([$s8, new Signal('S9', 'warn', '1 security advisory affects v3.4.18 (a); fixed by v3.4.47', ['advisories' => [self::fixed('v3.4.47', true)]])])->withChain(['symfony/http-foundation'])->withDirectDependents(['symfony/http-foundation'])->build();
        self::assertFalse($allOnBranch->hasUnfixableAdvisory(), 'a composer update inside the constraint gets the fix');
        self::assertSame(Priority013::HIGH, $allOnBranch->priority());
        self::assertStringEndsWith('; fixed by v3.4.47', $allOnBranch->ownEvidence());

        $openEverywhere = (new FindingBuilder())->withPackage('symfony/http-foundation')->withVersion('v3.4.18')->withVerdict(Verdict::LEFT_BEHIND)->withSignals([$s8, new Signal('S9', 'warn', '1 security advisory affects v3.4.18 (a)', ['advisories' => [self::OPEN]])])->withChain(['symfony/http-foundation'])->withDirectDependents(['symfony/http-foundation'])->build();
        self::assertStringEndsWith('(a); no fix expected', $openEverywhere->ownEvidence(), 'nothing fixes it anywhere: no branch to point away from');

        $abandonedWithAFixElsewhere = (new FindingBuilder())->withVerdict(Verdict::ABANDONED)->withSignals([new Signal('S1', 'high', 'marked abandoned by its repository'), new Signal('S9', 'warn', '2 security advisories affect 1.0.0 (a, b); 1 fixed by 2.0.0', ['advisories' => [self::fixed('2.0.0', false), self::OPEN]])])->withDirectDependents(['vendor/pkg'])->build();
        self::assertTrue($abandonedWithAFixElsewhere->hasUnfixableAdvisory(), 'one of the two has no fix at all');
        self::assertStringEndsWith('; 1 fixed by 2.0.0; no fix expected', $abandonedWithAFixElsewhere->ownEvidence(), 'an abandoned package has no branch to qualify with');
    }

    public function testNoteWhenNoSignals(): void
    {
        $finding = (new FindingBuilder())->withPackage('private/thing')->withVersion('3.0.0')->withVerdict(Verdict::UNKNOWN)->withChain([])->withNote('not from a Composer repository, not checked')->withOrigin(Origins::of(false))->build();
        self::assertSame('not from a Composer repository, not checked', $finding->evidence());
        self::assertNull($finding->toArray()['data_date']);
    }

    public function testEvidenceIsEmptyStringWhenNoSignalsAndNoNote(): void
    {
        $finding = (new FindingBuilder())->withPackage('private/thing')->withVersion('3.0.0')->withVerdict(Verdict::UNKNOWN)->withChain([])->build();
        self::assertSame('', $finding->evidence());
    }

    public function testToArrayIncludesAllowlistReasonAndNote(): void
    {
        $finding = (new FindingBuilder())->withVersion('1.2.3')->withVerdict(Verdict::FINISHED)->withAllowlistReason('audited by security team')->withNote('no signals fired')->build();
        $array = $finding->toArray();
        self::assertSame('vendor/pkg', $array['package']);
        self::assertSame('1.2.3', $array['version']);
        self::assertSame('finished', $array['verdict']);
        self::assertSame('', $array['evidence'], 'no flag counts on the score-0 shape');
        self::assertSame('audited by security team', $array['allowlist_reason']);
        self::assertSame('no signals fired', $array['note']);
        self::assertSame(['vendor/pkg'], $array['chain']);
    }

    public function testFindingsAreProdByDefault(): void
    {
        $finding = (new FindingBuilder())->withVersion('1.2.3')->withVerdict(Verdict::ABANDONED)->build();
        self::assertFalse($finding->isDev());
        self::assertTrue($finding->isDirect());
        self::assertSame(Priority013::CRITICAL, $finding->priority());
    }

    public function testDevIsTheLastConstructorParameterAndLowersThePriority(): void
    {
        $finding = (new FindingBuilder())->withVersion('1.2.3')->withVerdict(Verdict::ABANDONED)->withDev(true)->build();
        self::assertTrue($finding->isDev());
        self::assertSame(Priority013::HIGH, $finding->priority());
    }

    public function testATransitiveDevFindingIsLoweredTwice(): void
    {
        $finding = (new FindingBuilder())->withVersion('1.2.3')->withVerdict(Verdict::ABANDONED)->withChain(['vendor/root', 'vendor/pkg'])->withDev(true)->build();
        self::assertFalse($finding->isDirect());
        self::assertSame(Priority013::MEDIUM, $finding->priority());
    }

    public function testAnEmptyChainCountsAsTransitive(): void
    {
        $finding = (new FindingBuilder())->withVersion('1.2.3')->withVerdict(Verdict::ABANDONED)->withChain([])->build();
        self::assertFalse($finding->isDirect());
        self::assertSame(Priority013::HIGH, $finding->priority());
    }

    public function testAnUnflaggedFindingHasNoPriority(): void
    {
        $finding = (new FindingBuilder())->withVersion('1.2.3')->build();
        self::assertSame(Priority013::NONE, $finding->priority());
    }

    /**
     * The link to the replacement is lockrot's, written where the registry that named it keeps a
     * page for the name: packagist.org. The page never builds one.
     */
    public function testAReplacementNamedByPackagistIsLinkedThere(): void
    {
        $s1 = static fn (string $replacement): Signal => new Signal('S1', 'high', 'marked abandoned by its repository, replacement: '.$replacement, ['replacement' => $replacement]);
        $abandoned = static fn (Signal $signal, ?string $namedBy, string $verdict = Verdict::ABANDONED): Finding => (new FindingBuilder())->withPackage('vendor/old')->withVerdict($verdict)->withSignals([$signal])->withChain(['vendor/old'])->withReplacementNamedBy($namedBy)->build();

        self::assertSame('https://packagist.org/packages/symfony/mailer', $abandoned($s1('symfony/mailer'), 'packagist.org')->toArray()['replacement_url']);
        self::assertNull($abandoned($s1('symfony/mailer'), 'repo.packagist.com')->toArray()['replacement_url'], 'Private Packagist named it, and keeps no public page');
        self::assertNull($abandoned($s1('symfony/mailer'), null)->toArray()['replacement_url'], 'no registry lockrot knows named it');
        self::assertNull($abandoned($s1('Symfony'), 'packagist.org')->toArray()['replacement_url'], 'free text is not a package');
        self::assertSame('https://packagist.org/packages/symfony/mailer', $abandoned($s1('symfony/mailer'), 'packagist.org', Verdict::SILENT)->toArray()['replacement_url'], 'S1 raises abandoned, which counts whatever the report-1 verdict');
        self::assertNull(((new FindingBuilder())->withVerdict(Verdict::STALE)->build())->toArray()['replacement_url']);
    }

    public function testToArrayWritesReport2sFindingKeysInOrder(): void
    {
        $finding = (new FindingBuilder())->withVersion('1.2.3')->withVerdict(Verdict::STALE)->withChain(['vendor/root', 'vendor/pkg'])->withDev(true)->build();
        $array = $finding->toArray();
        self::assertSame(
            ['package', 'version', 'branch', 'installed_php', 'verdict', 'priority', 'lead', 'flags', 'score', 'next_step', 'security', 'checks_missing', 'checks_skipped', 'maintenance_judged', 'metadata', 'allowlist',
                'from_composer_repository', 'origin', 'replacement', 'replacement_url', 'note', 'libyears_unmeasured', 'direct', 'dev', 'reach', 'chain', 'direct_dependents', 'signals', 'evidence', 'allowlist_reason', 'data_date', 'libyears'],
            array_keys($array)
        );
        self::assertSame('low', $array['priority'], 'the alias of the grade');
        self::assertFalse($array['direct']);
        self::assertTrue($array['dev']);
    }

    public function testAFindingIsFromAComposerRepositoryUnlessItSaysOtherwise(): void
    {
        $asked = (new FindingBuilder())->build();
        $notAsked = (new FindingBuilder())->withPackage('local/pkg')->withVersion('dev-main')->withVerdict(Verdict::UNKNOWN)->withChain(['local/pkg'])->withNote(Finding::NOTE_NOT_IN_REPOSITORY)->withOrigin(Origins::of(false))->build();

        self::assertTrue($asked->isFromComposerRepository());
        self::assertTrue($asked->toArray()['from_composer_repository']);
        self::assertFalse($notAsked->isFromComposerRepository());
        self::assertFalse($notAsked->toArray()['from_composer_repository']);
        self::assertFalse($notAsked->withSignals([new Signal(Signal::S7, Signal::LEVEL_INFO, 'pulls in 1 flagged package: a/b (stale)')])->toArray()['from_composer_repository'], 'S7 keeps it');
        // A note other than that one is no claim about the lock entry: metadata can fail for a
        // package a repository was asked about.
        $failed = (new FindingBuilder())->withPackage('vendor/gone')->withVerdict(Verdict::UNKNOWN)->withChain(['vendor/gone'])->withNote('Repository metadata unavailable: HTTP 503')->build();
        self::assertTrue($failed->toArray()['from_composer_repository']);
    }

    public function testTheNotInARepositoryNoteCannotBeBuiltOnAFindingFromOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new FindingBuilder())->withPackage('local/pkg')->withVersion('dev-main')->withVerdict(Verdict::UNKNOWN)->withChain(['local/pkg'])->withNote(Finding::NOTE_NOT_IN_REPOSITORY)->build();
    }

    public function testAFindingCarriesItsLibyearsUnroundedAndPrintsThemToTwoDecimals(): void
    {
        $measured = (new FindingBuilder())->withPackage('smalot/pdfparser')->withVersion('v1.1.0')->withVerdict(Verdict::LEFT_BEHIND)->withChain(['smalot/pdfparser'])->withLibyears(LibyearsMeasurement::of(4.7123))->build();
        $unmeasured = (new FindingBuilder())->withPackage('wallabag/rulerz')->withVersion('dev-master')->withVerdict(Verdict::PINNED)->withChain(['wallabag/rulerz'])->withLibyears(LibyearsMeasurement::unmeasured(Libyears::BRANCH_SNAPSHOT))->build();

        self::assertSame(4.7123, $measured->libyears());
        self::assertSame(4.71, $measured->toArray()['libyears']);
        self::assertNull($measured->libyearsUnmeasured());
        self::assertNull($measured->toArray()['libyears_unmeasured']);
        self::assertNull($unmeasured->libyears());
        self::assertNull($unmeasured->toArray()['libyears']);
        self::assertSame(Libyears::BRANCH_SNAPSHOT, $unmeasured->libyearsUnmeasured());
        self::assertSame(Libyears::BRANCH_SNAPSHOT, $unmeasured->toArray()['libyears_unmeasured']);
        self::assertSame(Libyears::BRANCH_SNAPSHOT, $unmeasured->withSignals([])->toArray()['libyears_unmeasured'], 'S7 keeps it');
    }

    public function testAZeroIsMeasuredAndCarriesNoReason(): void
    {
        $current = (new FindingBuilder())->withLibyears(LibyearsMeasurement::of(0.0))->build();

        self::assertSame(0.0, $current->toArray()['libyears']);
        self::assertNull($current->toArray()['libyears_unmeasured']);
    }

    /** A finding assembled without a measurement has no dates to compare. */
    public function testAFindingBuiltWithoutAMeasurementHasNoDateToTrust(): void
    {
        $bare = (new FindingBuilder())->build();

        self::assertNull($bare->libyears());
        self::assertSame(Libyears::NO_STABLE_RELEASE_DATE, $bare->libyearsUnmeasured());
        self::assertSame(Libyears::NO_STABLE_RELEASE_DATE, $bare->toArray()['libyears_unmeasured']);
    }

    /** Nothing was asked about a package outside every Composer repository, the first reason it goes unmeasured. */
    public function testAFindingNotFromAComposerRepositoryIsUnmeasuredForThatReasonByDefault(): void
    {
        $bare = (new FindingBuilder())->withPackage('local/pkg')->withVerdict(Verdict::UNKNOWN)->withChain(['local/pkg'])->withNote(Finding::NOTE_NOT_IN_REPOSITORY)->withOrigin(Origins::of(false))->build();

        self::assertNull($bare->libyears());
        self::assertSame(Libyears::NOT_FROM_COMPOSER_REPOSITORY, $bare->toArray()['libyears_unmeasured']);
    }

    /** @return iterable<string, array{LibyearsMeasurement, bool}> a measurement and a flag that contradict each other */
    public static function measurementsAgainstTheFlag(): iterable
    {
        yield 'not asked, on a finding from a repository' => [LibyearsMeasurement::unmeasured(Libyears::NOT_FROM_COMPOSER_REPOSITORY), true];
        yield 'another reason, on a finding from no repository' => [LibyearsMeasurement::unmeasured(Libyears::NO_STABLE_RELEASE_DATE), false];
        yield 'measured, on a finding from no repository' => [LibyearsMeasurement::of(1.0), false];
    }

    /**
     * @dataProvider measurementsAgainstTheFlag
     */
    #[DataProvider('measurementsAgainstTheFlag')]
    public function testAMeasurementCannotContradictWhereTheFindingComesFrom(LibyearsMeasurement $libyears, bool $fromComposerRepository): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('local/pkg');

        (new FindingBuilder())->withPackage('local/pkg')->withVerdict(Verdict::UNKNOWN)->withChain(['local/pkg'])->withLibyears($libyears)->withOrigin(Origins::of($fromComposerRepository))->build();
    }

    public function testDirectDependentsDefaultToNoneAndAreCarriedInTheArray(): void
    {
        $bare = (new FindingBuilder())->withVerdict(Verdict::STALE)->withChain(['vendor/root', 'vendor/pkg'])->build();
        self::assertSame([], $bare->directDependents());
        self::assertSame([], $bare->otherDirectDependents());
        self::assertSame([], $bare->toArray()['direct_dependents']);

        $finding = (new FindingBuilder())->withVerdict(Verdict::STALE)->withChain(['vendor/root', 'vendor/pkg'])->withDirectDependents(['vendor/other', 'vendor/root'])->build();
        self::assertSame(['vendor/other', 'vendor/root'], $finding->directDependents());
        self::assertSame(['vendor/other', 'vendor/root'], $finding->toArray()['direct_dependents']);
        // The chain's own root is what "via" already shows. The others are what is left.
        self::assertSame(['vendor/other'], $finding->otherDirectDependents());
    }

    public function testADirectPackageAlsoRequiredByAnotherRootListsThatRootAsOther(): void
    {
        $finding = (new FindingBuilder())->withVerdict(Verdict::STALE)->withDirectDependents(['vendor/other', 'vendor/pkg'])->build();
        self::assertTrue($finding->isDirect());
        self::assertSame(['vendor/other'], $finding->otherDirectDependents());
    }

    public function testWithSignalsReturnsANewFindingWithTheVerdictAndEverythingElseKept(): void
    {
        $at = new \DateTimeImmutable('2026-09-14T10:00:00+00:00');
        $original = (new FindingBuilder())->withDataDate($at)->withDev(true)->withDirectDependents(['vendor/pkg'])->build();
        $s7 = new Signal(Signal::S7, Signal::LEVEL_INFO, 'pulls in 1 flagged package: vendor/dep (stale)', ['flagged' => 1, 'packages' => []]);

        $annotated = $original->withSignals([$s7]);

        self::assertNotSame($original, $annotated);
        self::assertSame([], $original->signals());
        self::assertSame([$s7], $annotated->signals());
        self::assertSame(Verdict::OK, $annotated->verdict());
        self::assertSame(Priority013::NONE, $annotated->priority());
        self::assertSame('pulls in 1 flagged package: vendor/dep (stale)', $annotated->evidence());
        self::assertTrue($annotated->isDev());
        self::assertSame($at, $annotated->dataDate());
        self::assertSame(['vendor/pkg'], $annotated->directDependents());
    }

    public function testTheNoteSurvivesS7AndOwnEvidenceLeavesS7Out(): void
    {
        $s7 = new Signal(Signal::S7, Signal::LEVEL_INFO, 'pulls in 2 flagged packages: a/b (stale), c/d (stale)', ['flagged' => 2, 'packages' => []]);
        $unchecked = (new FindingBuilder())->withPackage('local/pkg')->withVersion('dev-main')->withVerdict(Verdict::UNKNOWN)->withSignals([$s7])->withChain(['local/pkg'])->withNote('not from a Composer repository, not checked')->withOrigin(Origins::of(false))->build();
        self::assertSame('not from a Composer repository, not checked; pulls in 2 flagged packages: a/b (stale), c/d (stale)', $unchecked->evidence());
        self::assertSame('not from a Composer repository, not checked', $unchecked->ownEvidence());

        $own = new Signal('S2', 'warn', 'last release 2022-05-20 (4.3 years ago)');
        $stale = (new FindingBuilder())->withVerdict(Verdict::STALE)->withSignals([$own, $s7])->withNote('a note nobody reads')->build();
        self::assertSame('last release 2022-05-20 (4.3 years ago); pulls in 2 flagged packages: a/b (stale), c/d (stale)', $stale->evidence());
        self::assertSame('last release 2022-05-20 (4.3 years ago)', $stale->ownEvidence());

        $clean = (new FindingBuilder())->withPackage('vendor/ok')->withChain(['vendor/ok'])->build();
        self::assertSame('', $clean->evidence());
        self::assertSame('', $clean->ownEvidence());
    }

    /**
     * An S9 row as {@see \Lockrot\Signal\Rule\AdvisoryRule} writes it.
     *
     * @return array{id: string, affected_versions: ?string, fixed_by: ?string, fixed_on_branch: bool}
     */
    private static function row(string $id, ?string $fixedBy = null, bool $onBranch = false, ?string $range = '>=1.0.0'): array
    {
        return ['id' => $id, 'affected_versions' => $range, 'fixed_by' => $fixedBy, 'fixed_on_branch' => $onBranch];
    }

    /** @param list<array<string, mixed>> $rows */
    private static function s9(array $rows, bool $releasesRead = true): Signal
    {
        return new Signal(Signal::S9, Signal::LEVEL_WARN, \count($rows).' security advisories affect the installed version', ['advisories' => $rows, 'releases_read' => $releasesRead]);
    }

    /**
     * @return iterable<string, array{Finding, ?list<array{id: string, reason: string}>}>
     */
    public static function noFixShapes(): iterable
    {
        $s8 = new Signal(Signal::S8, Signal::LEVEL_HIGH, 'branch 3.x last released 2020-10-24 (5.9 years ago); 8.x released v8.1.7 (2026-09-14)');
        $s1 = new Signal(Signal::S1, Signal::LEVEL_HIGH, 'marked abandoned by its repository');
        $s2 = new Signal(Signal::S2, Signal::LEVEL_HIGH, 'last release 2015-11-16 (10.8 years ago)');
        $s7 = new Signal(Signal::S7, Signal::LEVEL_INFO, 'pulls in 1 flagged package: a/b (stale)');

        yield 'http-foundation left behind, fixed on its branch and on 8.x' => [
            (new FindingBuilder())->withPackage('symfony/http-foundation')->withVersion('v3.4.18')->withVerdict(Verdict::LEFT_BEHIND)->withSignals([$s8, self::s9([self::row('CVE-a', 'v8.1.7'), self::row('CVE-b', 'v8.1.7'), self::row('CVE-c', 'v3.4.47', true), self::row('CVE-d')])])->withChain(['laravel/framework', 'symfony/http-foundation'])->build(),
            [['id' => 'CVE-a', 'reason' => 'not_on_installed_branch'], ['id' => 'CVE-b', 'reason' => 'not_on_installed_branch'], ['id' => 'CVE-d', 'reason' => 'no_release_fixes']],
        ];
        yield 'abandoned, fixed by v6.3.0' => [
            (new FindingBuilder())->withPackage('swiftmailer/swiftmailer')->withVersion('v6.1.3')->withVerdict(Verdict::ABANDONED)->withSignals([$s1, self::s9([self::row('CVE-2024-28859', '6.3.0', true)])])->withChain(['swiftmailer/swiftmailer'])->build(),
            [],
        ];
        yield 'abandoned, no listed release fixes it' => [
            (new FindingBuilder())->withVerdict(Verdict::ABANDONED)->withSignals([$s1, self::s9([self::row('PKSA-1')])])->build(),
            [['id' => 'PKSA-1', 'reason' => 'no_release_fixes']],
        ];
        yield 'abandoned, the releases were not read' => [
            (new FindingBuilder())->withVerdict(Verdict::ABANDONED)->withSignals([$s1, self::s9([self::row('PKSA-1'), self::row('PKSA-2', null, false, null)], false)])->withChain(['root/app', 'vendor/pkg'])->build(),
            [['id' => 'PKSA-1', 'reason' => 'releases_unknown'], ['id' => 'PKSA-2', 'reason' => 'releases_unknown']],
        ];
        yield 'silent, a partial advisory with no range' => [
            (new FindingBuilder())->withVerdict(Verdict::SILENT)->withSignals([$s2, self::s9([self::row('PKSA-1', null, false, null), self::row('PKSA-2')])])->build(),
            [['id' => 'PKSA-1', 'reason' => 'affected_range_unknown'], ['id' => 'PKSA-2', 'reason' => 'no_release_fixes']],
        ];
        yield 'stale, nothing fixes it' => [
            (new FindingBuilder())->withVerdict(Verdict::STALE)->withSignals([$s2, self::s9([self::row('PKSA-1')])])->build(),
            null,
        ];
        yield 'allowlisted' => [
            (new FindingBuilder())->withVerdict(Verdict::FINISHED)->withSignals([$s8, self::s9([self::row('PKSA-1')])])->withAllowlistReason('frozen')->build(),
            null,
        ];
        yield 'ok' => [
            (new FindingBuilder())->withSignals([self::s9([self::row('PKSA-1')])])->build(),
            null,
        ];
        yield 'abandoned with no advisory' => [
            (new FindingBuilder())->withVerdict(Verdict::ABANDONED)->withSignals([$s1])->build(),
            [],
        ];
        yield 'a direct requirement carrying S7' => [
            (new FindingBuilder())->withVerdict(Verdict::ABANDONED)->withSignals([$s1, self::s9([self::row('PKSA-1')]), $s7])->build(),
            [['id' => 'PKSA-1', 'reason' => 'no_release_fixes']],
        ];
        yield 'reached by nothing' => [
            (new FindingBuilder())->withVerdict(Verdict::SILENT)->withSignals([$s2, self::s9([self::row('PKSA-1')])])->withChain([])->withDev(true)->build(),
            [['id' => 'PKSA-1', 'reason' => 'no_release_fixes']],
        ];
    }

    /**
     * @param ?list<array{id: string, reason: string}> $expected
     *
     * @dataProvider noFixShapes
     */
    #[DataProvider('noFixShapes')]
    public function testTheNoFixListNamesEachAdvisoryNoFixIsExpectedForAndWhy(Finding $finding, ?array $expected): void
    {
        self::assertSame($expected, $finding->noFixExpected());
        self::assertArrayNotHasKey('no_fix_expected', $finding->toArray(), 'report-2 drops it');
        self::assertSame($expected === null, !\in_array($finding->verdict(), Finding::NO_FIX_VERDICTS, true), 'null exactly off the no-fix verdicts');
        $ids = [];
        foreach ($finding->signals() as $signal) {
            if ($signal->id() === Signal::S9) {
                $rows = $signal->data()['advisories'];
                self::assertIsArray($rows);
                $ids = array_column($rows, 'id');
            }
        }
        foreach ($expected ?? [] as $item) {
            self::assertContains($item['id'], $ids, 'every id is one of the finding\'s S9 rows');
        }

        $basis = $finding->priorityBasis()->toArray();
        self::assertSame($basis, $finding->priorityBasis()->toArray());
        $reasons = array_column($basis['steps'], 'reason');
        $raised = \in_array('no_fix_expected', $reasons, true);
        self::assertSame($raised, $expected !== null && $expected !== [], 'the raise is there exactly when the list names an advisory');
        self::assertSame($raised, $finding->hasUnfixableAdvisory());
        self::assertSame($raised, strpos($finding->ownEvidence(), 'no fix expected') !== false, 'and exactly when the evidence says so');
        self::assertSame(\in_array('not_on_installed_branch', array_column($expected ?? [], 'reason'), true), strpos($finding->ownEvidence(), 'no fix expected on ') !== false, 'the clause names the branch exactly when the list says the fix is off it');
        self::assertSame($finding->chain() === [], \in_array('unreached', $reasons, true), 'unreached exactly when nothing reaches the package');
        self::assertSame($finding->priority(), $basis['steps'] === [] ? $basis['base'] : $basis['steps'][\count($basis['steps']) - 1]['to']);
    }

    /** http-foundation v3.4.18 via laravel/framework: `high`, one step down for being transitive, one up for the two fixes 3.x will not get. */
    public function testTheBasisOfATransitiveLeftBehindPackageRaisedBackUp(): void
    {
        $s9 = self::s9([self::row('CVE-a', 'v8.1.7'), self::row('CVE-c', 'v3.4.47', true)]);
        $finding = (new FindingBuilder())->withPackage('symfony/http-foundation')->withVersion('v3.4.18')->withVerdict(Verdict::LEFT_BEHIND)->withSignals([$s9])->withChain(['laravel/framework', 'symfony/http-foundation'])->build();

        self::assertSame(['base' => 'high', 'steps' => [['reason' => 'transitive', 'from' => 'high', 'to' => 'medium'], ['reason' => 'no_fix_expected', 'from' => 'medium', 'to' => 'high']]], $finding->priorityBasis()->toArray());
        self::assertSame(Priority013::HIGH, $finding->priority());
    }

    /** A lock read without its composer.json: nothing reaches the package, and the step says so rather than `transitive`. */
    public function testTheBasisOfAPackageNothingReachesSaysUnreached(): void
    {
        $finding = (new FindingBuilder())->withVerdict(Verdict::STALE)->withChain([])->withDev(true)->build();

        self::assertSame(['base' => 'medium', 'steps' => [['reason' => 'unreached', 'from' => 'medium', 'to' => 'low'], ['reason' => 'dev', 'from' => 'low', 'to' => 'low']]], $finding->priorityBasis()->toArray());
        self::assertSame(['base' => 'none', 'steps' => []], ((new FindingBuilder())->withChain([])->build())->priorityBasis()->toArray());
    }

    /** An S9 without releases_read says nothing about whether a fix was looked for, so none was. */
    public function testAnS9WithoutReleasesReadReadsAsNotLookedFor(): void
    {
        $finding = (new FindingBuilder())->withVerdict(Verdict::ABANDONED)->withSignals([new Signal(Signal::S9, Signal::LEVEL_WARN, 'x', ['advisories' => [self::row('PKSA-1')]])])->build();

        self::assertSame([['id' => 'PKSA-1', 'reason' => 'releases_unknown']], $finding->noFixExpected());
    }

    /** A row without a string id is nothing the list could name, so it is no advisory, as a row that is not an array is none. */
    public function testAnAdvisoryRowWithoutAnIdIsNoAdvisory(): void
    {
        $rows = [['fixed_by' => null, 'fixed_on_branch' => false], ['id' => 7, 'fixed_by' => null], 'not a row', self::row('PKSA-1')];
        $finding = (new FindingBuilder())->withVerdict(Verdict::ABANDONED)->withSignals([new Signal(Signal::S9, Signal::LEVEL_WARN, 'x', ['advisories' => $rows, 'releases_read' => true])])->build();

        self::assertSame([['id' => 'PKSA-1', 'reason' => 'no_release_fixes']], $finding->noFixExpected());

        $idless = (new FindingBuilder())->withVerdict(Verdict::ABANDONED)->withSignals([self::s9([['fixed_by' => null]])])->build();
        self::assertSame([], $idless->noFixExpected());
        self::assertFalse($idless->hasUnfixableAdvisory());
    }

    public function testEvidenceLineAppendsTheAllowlistReasonAfterEverythingElse(): void
    {
        $own = new Signal('S2', 'warn', 'last release 2022-05-20 (4.3 years ago)');
        $s7 = new Signal(Signal::S7, 'info', 'pulls in 1 flagged package: vendor/leaf (stale)');
        $allowlisted = (new FindingBuilder())->withVerdict(Verdict::FINISHED)->withSignals([$own, $s7])->withAllowlistReason('interfaces')->build();
        $allowlistedWithoutEvidence = (new FindingBuilder())->withVerdict(Verdict::FINISHED)->withAllowlistReason('interfaces')->build();
        $notAllowlisted = (new FindingBuilder())->withVerdict(Verdict::STALE)->withSignals([$own])->build();

        self::assertSame(
            'last release 2022-05-20 (4.3 years ago); pulls in 1 flagged package: vendor/leaf (stale); allowlisted: interfaces',
            $allowlisted->evidenceLine()
        );
        self::assertSame('allowlisted: interfaces', $allowlistedWithoutEvidence->evidenceLine());
        self::assertSame('last release 2022-05-20 (4.3 years ago)', $notAllowlisted->evidenceLine());
    }

    /** @return iterable<string, array{\Closure(Finding): mixed}> */
    public static function scoreReaders(): iterable
    {
        yield 'grade' => [static fn (Finding $finding) => $finding->grade()];
        yield 'lead' => [static fn (Finding $finding) => $finding->lead()];
        yield 'isGraded' => [static fn (Finding $finding) => $finding->isGraded()];
        yield 'score' => [static fn (Finding $finding) => $finding->score()];
    }

    /**
     * @param \Closure(Finding): mixed $read
     *
     * @dataProvider scoreReaders
     */
    #[DataProvider('scoreReaders')]
    public function testAFindingBuiltWithoutItsFlagsRefusesToGrade(\Closure $read): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('vendor/pkg');

        $read((new FindingBuilder())->withoutFlags()->build());
    }

    /** @return iterable<string, array{list<string>, bool, string, string, int}> */
    public static function reaches(): iterable
    {
        yield 'direct' => [['vendor/pkg'], false, Score::DIRECT, 'high', 16];
        yield 'transitive' => [['vendor/root', 'vendor/pkg'], false, Score::TRANSITIVE, 'medium', 8];
        yield 'unreached' => [[], false, Score::UNREACHED, 'medium', 8];
        yield 'direct dev' => [['vendor/pkg'], true, Score::DIRECT, 'medium', 8];
        yield 'transitive dev' => [['vendor/root', 'vendor/pkg'], true, Score::TRANSITIVE, 'low', 4];
    }

    /**
     * @param list<string> $chain
     *
     * @dataProvider reaches
     */
    #[DataProvider('reaches')]
    public function testTheScoreTakesItsReachFromTheChainAndItsDevHalvingFromTheLock(array $chain, bool $dev, string $reach, string $grade, int $total): void
    {
        $signals = [new Signal(Signal::S8, Signal::LEVEL_WARN, 'left behind')];
        $finding = (new FindingBuilder())->withVerdict(Verdict::LEFT_BEHIND)->withSignals($signals)->withChain($chain)->withDev($dev)->withFlags(FlagSet::fromSignals($signals, null, []))->build();

        self::assertSame($reach, $finding->score()->reach());
        self::assertSame($dev, $finding->score()->isDev());
        self::assertSame($total, $finding->score()->total());
        self::assertSame($grade, $finding->grade());
        self::assertSame(FlagSet::LEFT_BEHIND, $finding->lead());
        self::assertTrue($finding->isGraded());
    }

    public function testAVulnerableOnlyFindingIsGradedWithNoLeadAndKeepsItsReport1Verdict(): void
    {
        $flags = FlagSet::fromSignals([], null, [Score::advisory('PKSA-1', 'critical', 'update')]);
        $finding = (new FindingBuilder())->withFlags($flags)->build();

        self::assertSame(Verdict::OK, $finding->verdict());
        self::assertSame('critical', $finding->grade());
        self::assertNull($finding->lead());
        self::assertTrue($finding->isGraded());
    }

    /** @return iterable<string, array{?AllowlistEntry, bool, string}> */
    public static function zeroes(): iterable
    {
        yield 'nothing counts' => [null, true, 'ok'];
        yield 'no release metadata' => [null, false, 'unknown'];
        yield 'an entry accepts the whole package' => [new AllowlistEntry('vendor/pkg', null, 'interfaces', null, 'builtin'), true, 'finished'];
        yield 'an entry accepts the whole package, no release metadata' => [new AllowlistEntry('vendor/pkg', null, 'interfaces', null, 'builtin'), false, 'finished'];
    }

    /** @dataProvider zeroes */
    #[DataProvider('zeroes')]
    public function testAScoreOfZeroSaysWhy(?AllowlistEntry $entry, bool $maintenanceJudged, string $verdict): void
    {
        $finding = (new FindingBuilder())->withFlags(FlagSet::fromSignals([], $entry, []), $maintenanceJudged)->build();

        self::assertSame($verdict, $finding->grade());
        self::assertFalse($finding->isGraded());
        self::assertNull($finding->lead());
    }

    public function testWithFlagsReturnsAGradedCopyAndKeepsTheFindingItWasCalledOn(): void
    {
        $finding = (new FindingBuilder())->withoutFlags()->build();
        $graded = $finding->withFlags(FlagSet::fromSignals([], null, []), false);

        self::assertNotSame($finding, $graded);
        self::assertSame('unknown', $graded->grade());
        $this->expectException(\LogicException::class);
        $finding->grade();
    }

    /**
     * Maintenance is judged exactly when the repository metadata was read: the details and the
     * flags of one finding cannot say otherwise.
     *
     * @dataProvider mismatchedJudgements
     */
    #[DataProvider('mismatchedJudgements')]
    public function testAJudgementTheDetailsContradictIsRefused(string $status, bool $maintenanceJudged, bool $detailsFirst): void
    {
        $finding = (new FindingBuilder())->withoutFlags()->build();
        $details = new FindingDetails($status, null, null, [], ['requires' => null, 'target_runs' => null, 'project_allows' => null], null, 'complete', null, [], null);
        $flags = FlagSet::fromSignals([], null, []);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf('Metadata status "%s" contradicts maintenance_judged %s.', $status, $maintenanceJudged ? 'true' : 'false'));
        $detailsFirst ? $finding->withDetails($details)->withFlags($flags, $maintenanceJudged) : $finding->withFlags($flags, $maintenanceJudged)->withDetails($details);
    }

    /** @return iterable<string, array{string, bool, bool}> */
    public static function mismatchedJudgements(): iterable
    {
        yield 'judged, the metadata unavailable' => ['unavailable', true, false];
        yield 'not judged, the metadata read' => ['read', false, false];
        yield 'the details first' => ['read', false, true];
    }

    /** The written S9 rows mark as deciding the advisory that the score's security term counts. */
    public function testTheDecidingS9RowIsTheScoresDecidingAdvisory(): void
    {
        $row = static fn (string $id, string $severity, int $points): array => ['id' => $id, 'cve' => null, 'title' => null, 'link' => null, 'reported_at' => null, 'severity' => $severity, 'severity_published' => $severity, 'affected_versions' => null, 'counted' => true, 'points' => $points, 'fix' => ['kind' => 'update'], 'baseline' => null];
        $signals = [new Signal(Signal::S9, Signal::LEVEL_HIGH, '2 advisories', ['advisories' => [$row('PKSA-a', 'critical', 32), $row('PKSA-b', 'low', 2)], 'releases_read' => true, 'complete' => true])];
        $flags = FlagSet::fromSignals($signals, null, [Score::advisory('PKSA-b', 'low', 'update'), Score::advisory('PKSA-a', 'critical', 'update')]);

        $document = (new FindingBuilder())->withSignals($signals)->withFlags($flags)->build()->toArray();

        $rows = JsonPath::arrayAt($document, ['signals', 0, 'data', 'advisories']);
        self::assertSame(['PKSA-a' => true, 'PKSA-b' => false], array_column($rows, 'deciding', 'id'));
        self::assertSame(['points', 'deciding', 'fix'], \array_slice(array_keys(JsonPath::arrayAt($rows, [0])), 9, 3), 'deciding follows points');
    }

    /** A silent or stale flag that no S2 or S4 reading dates has a headline in years with no value. */
    public function testALivenessFlagWithoutAReadingHasNoYears(): void
    {
        $document = (new FindingBuilder())->withVerdict(Verdict::STALE)->build()->toArray();

        self::assertSame(['unit' => 'years', 'value' => null, 'source' => null], JsonPath::arrayAt($document, ['flags', 0, 'headline']));
    }

    /** A hand-written report-1 S9 keeps its rows with an id as report-1's facts, and drops the rest. */
    public function testReport1S9RowsWithoutAnIdAreDropped(): void
    {
        $row = ['id' => 'PKSA-1', 'cve' => null, 'title' => null, 'link' => null, 'severity' => 'high', 'reported_at' => null, 'affected_versions' => null, 'fixed_by' => null, 'fixed_on_branch' => false];
        $signal = new Signal(Signal::S9, Signal::LEVEL_WARN, '1 advisory', ['advisories' => [['severity' => 'low'], $row], 'releases_read' => true]);

        $facts = (new FindingBuilder())->withSignals([$signal])->build()->advisoryFacts013();

        self::assertSame([$row], $facts->rows());
        self::assertTrue($facts->releasesRead());
    }

    /**
     * Release data that a counted advisory needed and did not get is an S10 entry: added to the
     * finding's S10, or an S10 of its own.
     *
     * @dataProvider s10s
     */
    #[DataProvider('s10s')]
    public function testUnreadReleasesForACountedAdvisoryAreAnS10Entry(bool $withS10): void
    {
        $lock = LockFile::fromArray(['packages' => [['name' => 'vendor/pkg', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/']]]);
        $package = $lock->find('vendor/pkg');
        self::assertNotNull($package);
        $facts = new PackageFacts($package, null, null, [new Advisory('PKSA-1', null, null, null, 'high', null)], null, null, null, true);
        $activity = ['check' => 'repository_activity', 'reason' => 'no_token', 'blocks' => ['S9', 'S3', 'S4']];
        $signals = $withS10 ? [new Signal(Signal::S10, Signal::LEVEL_INFO, 'activity not checked', ['unchecked' => [$activity], 'blocks' => ['S9', 'S3', 'S4']])] : [];
        $finding = (new FindingBuilder())->withVerdict(Verdict::UNKNOWN)->withSignals($signals)->build()
            ->withDetails(FindingDetails::of($facts, null, new PhpFloor('8.4', null), [], $signals, 'HTTP 503'));

        $written = JsonPath::arrayAt($finding->toArray(), ['signals']);
        $s10 = JsonPath::arrayAt($written, [0]);

        $releases = ['check' => 'releases', 'reason' => 'releases_unknown', 'blocks' => ['S9']];
        self::assertSame(Signal::S10, $s10['id']);
        self::assertCount(1, $written, 'one S10');
        self::assertSame($withS10 ? ['unchecked' => [$activity, $releases], 'blocks' => ['S9', 'S3', 'S4']] : ['unchecked' => [$releases], 'blocks' => ['S9']], $s10['data']);
        self::assertSame($withS10 ? 'activity not checked; '.NotCheckedRule::RELEASES_WORDS : NotCheckedRule::RELEASES_WORDS, $s10['summary']);
    }

    /** @return iterable<string, array{bool}> */
    public static function s10s(): iterable
    {
        yield 'beside an S10 entry' => [true];
        yield 'with no S10' => [false];
    }

    /** The vulnerable flag reads S9, counts its advisories and names the deciding one as worst when it is. */
    public function testTheVulnerableFlagReadsS9(): void
    {
        $row = static fn (string $id, string $severity, int $points): array => ['id' => $id, 'cve' => null, 'title' => 't', 'link' => null, 'reported_at' => null, 'severity' => $severity, 'severity_published' => $severity, 'affected_versions' => null, 'counted' => true, 'points' => $points, 'fix' => ['kind' => 'update'], 'baseline' => null];
        $signals = [new Signal(Signal::S9, Signal::LEVEL_HIGH, '2 advisories', ['advisories' => [$row('PKSA-a', 'high', 16), $row('PKSA-b', 'low', 2)], 'releases_read' => true, 'complete' => true])];
        $flags = FlagSet::fromSignals($signals, null, [Score::advisory('PKSA-a', 'high', 'update'), Score::advisory('PKSA-b', 'low', 'update')]);

        $flag = JsonPath::arrayAt((new FindingBuilder())->withSignals($signals)->withFlags($flags)->build()->toArray(), ['flags', 0]);

        self::assertSame(['vulnerable', 'security', ['S9'], ['unit' => 'advisories', 'value' => 2, 'source' => null]], [$flag['id'], $flag['role'], $flag['signal_ids'], $flag['headline']]);
        self::assertStringContainsString('; worst: PKSA-a t', JsonPath::stringAt($flag, ['summary']));
    }

    public function testWithSignalsKeepsTheScore(): void
    {
        $signals = [new Signal(Signal::S8, Signal::LEVEL_WARN, 'left behind')];
        $finding = (new FindingBuilder())->withSignals($signals)->withFlags(FlagSet::fromSignals($signals, null, []))->build();

        self::assertSame($finding->score(), $finding->withSignals([])->score());
        self::assertSame('high', $finding->withSignals([])->grade());
    }
}
