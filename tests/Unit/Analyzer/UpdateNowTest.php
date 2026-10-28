<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Analyzer;

use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunNote;
use Lockrot\Analyzer\UpdateNow;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Output\RunFooter;
use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\FindingRelations;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\RecordedMoveChecker;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\MoveChecks;
use PHPUnit\Framework\TestCase;

/**
 * Case `move-check‡` with its move resolved inline, so typo3/html-sanitizer is the one candidate
 * of `update now`, and the combined update answered as each test records it. Case `commonmark` has
 * two candidates: league/commonmark, which composer.json does not require, and laravel/framework,
 * which it requires.
 */
final class UpdateNowTest extends TestCase
{
    private const PACKAGE = 'typo3/html-sanitizer';
    private const COMMONMARK = 'league/commonmark';
    private const LARAVEL = 'laravel/framework';
    private const JWT = 'web-token/jwt-library';
    private const SODIUM = 'paragonie/sodium_compat';
    private const CONFLICT = "Problem 1\n    - laravel/framework v12.69.0 conflicts with league/commonmark <2.10.3.";

    public function testACombinedUpdateThatResolvesNamesItsMembersInTheCommand(): void
    {
        $document = self::document(null);

        self::assertSame([self::PACKAGE], JsonPath::arrayAt($document, ['security', 'update_now']));
        self::assertSame(['composer', 'update', self::PACKAGE.':^2.3.2'], JsonPath::arrayAt($document, ['security', 'update_now_command']));
        self::assertSame([], FindingRelations::violations($document), 'the root recount agrees');
    }

    /** Ruling R5-5: the branch top v2.3.5 needs php >=8.2 and require.php is ^8.1, so the line raises php first, as the member's own move does. */
    public function testTheUpdateNowLineCarriesTheRaiseThatAMembersMoveNeeds(): void
    {
        $case = CaseHydrator::case('move-check‡');
        $inputs = JsonPath::arrayAt($case, ['inputs']);
        $inputs['checks'] = ['*' => ['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'holds' => []]];
        $inputs['releases'] = array_merge(JsonPath::arrayAt($inputs, ['releases']), [['version' => 'v2.3.5', 'time' => '2026-07-01T00:00:00+00:00', 'php' => '>=8.2']]);
        $case['inputs'] = $inputs;
        $report = CaseHydrator::report($case);
        $move = JsonPath::arrayAt(self::finding(JsonPath::decoded($report->toArray()), self::PACKAGE), ['next_step']);

        self::assertSame([['composer', 'require', 'php:>=8.2', '--no-update'], ['composer', 'update', self::PACKAGE.':^2.3.2']], $move['commands'], 'the member\'s own move');
        self::assertSame('raise require.php to >=8.2, then ', RunFooter::platformStep($report));
        self::assertSame(['composer', 'update', self::PACKAGE.':^2.3.2'], $report->updateNowCommand());
    }

    /**
     * @dataProvider raises
     *
     * @param list<string> $raises
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('raises')]
    public function testTheHighestRaiseOfTheMembersWins(array $raises, ?string $highest): void
    {
        self::assertSame($highest, UpdateNow::highestRaise($raises));
    }

    /** @return iterable<string, array{list<string>, ?string}> */
    public static function raises(): iterable
    {
        yield 'the first is higher' => [['>=8.10', '>=8.9'], '>=8.10'];
        yield 'the second is higher' => [['>=8.9', '>=8.10'], '>=8.10'];
        yield 'one member raises' => [['>=8.2'], '>=8.2'];
        yield 'no member raises' => [[], null];
    }

    public function testACombinedUpdateThatIsNotCheckedHasNoMembersAndANoteSaysWhy(): void
    {
        $document = self::document(['result' => 'not_checked', 'form' => null, 'reason' => 'install_time_budget', 'holds' => []]);

        self::assertSame([[], null], [JsonPath::arrayAt($document, ['security', 'update_now']), JsonPath::arrayAt($document, ['security'])['update_now_command']]);
        self::assertSame(['reason' => 'install_time_budget', 'packages' => [self::PACKAGE]], JsonPath::arrayAt(self::note($document, 'update_now_not_checked'), ['data']));
        self::assertSame([], FindingRelations::violations($document), 'the root recount agrees');
    }

    public function testACombinedUpdateThatComposerRefusesHasNoMembers(): void
    {
        $document = self::document(['result' => 'refused', 'form' => null, 'reason' => null, 'holds' => [['source' => 'root', 'package' => null, 'version' => null, 'link' => 'conflict', 'target' => self::PACKAGE, 'constraint' => '>=2.3']]]);

        self::assertSame([[], null], [JsonPath::arrayAt($document, ['security', 'update_now']), JsonPath::arrayAt($document, ['security'])['update_now_command']]);
        self::assertArrayHasKey('problem', JsonPath::arrayAt(self::note($document, 'update_now_refused'), ['data']));
        self::assertSame([], FindingRelations::violations($document), 'the root recount agrees');
    }

    /** An update that resolves only beside its holder runs its own command: it is no member of the combined update. */
    public function testAnUpdateThatResolvesOnlyThroughTheJointRequestMakesNoMember(): void
    {
        $case = CaseHydrator::case('move-check‡');
        $inputs = JsonPath::arrayAt($case, ['inputs']);
        $inputs['checks'] = [
            '*' => [
                'result' => 'refused', 'form' => null, 'reason' => null,
                'holds' => [['source' => 'package', 'package' => 'h/x', 'version' => '1.0.0', 'link' => 'require', 'target' => null, 'constraint' => '^2.0']],
            ],
            'joint *' => ['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'installs' => [self::PACKAGE => 'v2.3.2', 'h/x' => '2.0.0'], 'holds' => []],
        ];
        $case['inputs'] = $inputs;

        $document = JsonPath::decoded(CaseHydrator::report($case)->toArray());

        self::assertSame([['package' => 'h/x', 'installed_version' => '1.0.0', 'version' => '2.0.0']], JsonPath::arrayAt(self::finding($document, self::PACKAGE), ['next_step', 'moves_with']));
        self::assertSame([[], null], [JsonPath::arrayAt($document, ['security', 'update_now']), JsonPath::arrayAt($document, ['security'])['update_now_command']]);
    }

    /** Case `B`: the vulnerable package's move is a `require`, which Composer resolves, so no command updates it. */
    public function testAResolvedMoveThatIsNoUpdateMakesNoMemberAndNoCommand(): void
    {
        $document = self::documentOf('B', []);

        self::assertSame([[], null], [JsonPath::arrayAt($document, ['security', 'update_now']), JsonPath::arrayAt($document, ['security'])['update_now_command']]);
    }

    /** Case `op-update`: matomo/network has an `update` move that Composer resolves, and no counted advisory. */
    public function testAnUpdateOfAPackageWithNoCountedAdvisoryMakesNoMember(): void
    {
        $document = self::documentOf('op-update', []);

        self::assertNotContains('matomo/network', JsonPath::arrayAt($document, ['security', 'update_now']));
    }

    public function testTheCommandLeavesOutTheCandidateThatTheRefusalNamesAndANoteSaysWhy(): void
    {
        $document = self::documentOf('commonmark', [
            'combined '.self::COMMONMARK.' '.self::LARAVEL => self::refusalNaming(self::LARAVEL),
            'combined '.self::COMMONMARK => ['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'holds' => []],
        ]);

        self::assertSame([self::COMMONMARK], JsonPath::arrayAt($document, ['security', 'update_now']));
        self::assertSame(['composer', 'update', self::COMMONMARK.':^2.10.3'], JsonPath::arrayAt($document, ['security', 'update_now_command']));
        self::assertSame([self::LARAVEL], JsonPath::arrayAt(self::note($document, 'update_now_reduced'), ['data', 'left_out']));
        self::assertSame([], FindingRelations::violations($document), 'the root recount agrees');
    }

    public function testARetryThatIsNotCheckedNamesEveryCandidateInItsNote(): void
    {
        $document = self::documentOf('commonmark', [
            'combined '.self::COMMONMARK.' '.self::LARAVEL => self::refusalNaming(self::LARAVEL),
            'combined '.self::COMMONMARK => ['result' => 'not_checked', 'form' => null, 'reason' => 'install_time_budget', 'holds' => []],
        ]);

        self::assertSame([[], null], [JsonPath::arrayAt($document, ['security', 'update_now']), JsonPath::arrayAt($document, ['security'])['update_now_command']]);
        self::assertSame(['reason' => 'install_time_budget', 'packages' => [self::COMMONMARK, self::LARAVEL]], JsonPath::arrayAt(self::note($document, 'update_now_not_checked'), ['data']));
    }

    public function testARefusalThatNamesEveryCandidateIsNotAskedAgain(): void
    {
        $document = self::documentOf('commonmark', [
            'combined '.self::COMMONMARK.' '.self::LARAVEL => self::refusalNaming(self::LARAVEL, self::COMMONMARK),
            'combined '.self::COMMONMARK => ['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'holds' => []],
        ]);

        self::assertSame([], JsonPath::arrayAt($document, ['security', 'update_now']));
        self::assertArrayHasKey('problem', JsonPath::arrayAt(self::note($document, 'update_now_refused'), ['data']));
    }

    /** Composer 2.2 and 2.3 take an inline constraint only on a package that composer.json requires. */
    public function testOnAComposerWithoutInlineConstraintsOnAnyPackageOnlyTheRequiredOneGetsItsConstraint(): void
    {
        $findings = self::reportOf('commonmark', [])->sorted();
        $checker = new RecordedMoveChecker(['combined '.self::COMMONMARK.' '.self::LARAVEL => ['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'holds' => [], 'installs' => [self::COMMONMARK => '2.10.3', self::LARAVEL => 'v12.69.0']]], []);

        $updateNow = UpdateNow::of($findings, new MoveChecks($checker, false));

        self::assertSame(['composer', 'update', self::COMMONMARK, self::LARAVEL.':^12.69.0'], $updateNow->command());
    }

    /** Composer 2.2 and 2.3: the check solves the line as it prints, so the package that the line lists plain has no constraint. */
    public function testOnAComposerWithoutInlineConstraintsOnAnyPackageTheCheckSolvesTheLineAsPrinted(): void
    {
        $findings = self::reportOf('commonmark', [])->sorted();
        $checker = new RecordedMoveChecker(['combined '.self::COMMONMARK.' '.self::LARAVEL => ['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'holds' => [], 'installs' => [self::COMMONMARK => '2.10.3', self::LARAVEL => 'v12.69.0']]], []);

        $updateNow = UpdateNow::of($findings, new MoveChecks($checker, false));

        self::assertSame([[self::COMMONMARK => '', self::LARAVEL => '^12.69.0']], $checker->combinedAsked());
        self::assertSame(['composer', 'update', self::COMMONMARK, self::LARAVEL.':^12.69.0'], $updateNow->command());
    }

    /** Composer 2.2 and 2.3: a plain member that the line keeps below its fix is left out, and the note names the release that it gets. */
    public function testOnAComposerWithoutInlineConstraintsOnAnyPackageAMemberThatTheSolveKeepsBelowItsFixIsLeftOut(): void
    {
        $combined = [
            'combined '.self::COMMONMARK.' '.self::LARAVEL => ['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'holds' => [], 'installs' => [self::LARAVEL => 'v12.69.0']],
            'combined '.self::LARAVEL => ['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'holds' => [], 'installs' => [self::LARAVEL => 'v12.69.0']],
        ];
        $findings = self::reportOf('commonmark', [])->sorted();
        $installed = '';
        foreach ($findings as $finding) {
            $installed = $finding->package() === self::COMMONMARK ? $finding->version() : $installed;
        }

        $updateNow = UpdateNow::of($findings, new MoveChecks(new RecordedMoveChecker($combined, []), false));

        self::assertSame(['composer', 'update', self::LARAVEL.':^12.69.0'], $updateNow->command());
        self::assertSame([self::LARAVEL], $updateNow->members());
        $note = $updateNow->note();
        self::assertNotNull($note);
        self::assertSame([RunNote::UPDATE_NOW_REDUCED, [self::COMMONMARK]], [$note->code(), $note->data()['left_out'] ?? null]);
        self::assertStringContainsString(self::COMMONMARK.' gets '.$installed.', below 2.10.3', $note->text());
    }

    /** Composer 2.2 and 2.3: when the solve keeps every member below its fix, the report writes no command, and the note names each one. */
    public function testOnAComposerWithoutInlineConstraintsOnAnyPackageALineThatReachesNoFixIsNoCommand(): void
    {
        $findings = array_values(array_filter(self::reportOf('commonmark', [])->sorted(), static fn (Finding $finding): bool => $finding->package() !== self::LARAVEL));
        $checker = new RecordedMoveChecker(['combined '.self::COMMONMARK => ['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'holds' => [], 'installs' => []]], []);

        $updateNow = UpdateNow::of($findings, new MoveChecks($checker, false));

        self::assertNull($updateNow->command());
        self::assertSame([], $updateNow->members());
        $note = $updateNow->note();
        self::assertNotNull($note);
        self::assertSame([RunNote::UPDATE_NOW_REDUCED, [self::COMMONMARK]], [$note->code(), $note->data()['left_out'] ?? null]);
        self::assertSame([[self::COMMONMARK => '']], $checker->combinedAsked());
    }

    /** Composer 2.2 and 2.3: the solve keeps two plain members below their fixes, so the line has no command and the note names both. */
    public function testOnAComposerWithoutInlineConstraintsOnAnyPackageTwoMembersBelowTheirFixesLeaveNoCommand(): void
    {
        $findings = self::reportOf('replace-graded', [])->sorted();
        $checker = new RecordedMoveChecker(['combined '.self::JWT.' '.self::SODIUM => ['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'holds' => [], 'installs' => []]], []);

        $updateNow = UpdateNow::of($findings, new MoveChecks($checker, false));

        self::assertSame([[self::JWT => '', self::SODIUM => '']], $checker->combinedAsked());
        self::assertNull($updateNow->command());
        $note = $updateNow->note();
        self::assertNotNull($note);
        self::assertSame([self::JWT, self::SODIUM], $note->data()['left_out'] ?? null);
    }

    /** Composer 2.2 and 2.3: a member that the second solve keeps below its fix joins the one that the first solve left out. */
    public function testOnAComposerWithoutInlineConstraintsOnAnyPackageEachSolveLeavesOutTheMembersBelowTheirFixes(): void
    {
        $findings = self::reportOf('replace-graded', [])->sorted();
        $fix = '';
        foreach ($findings as $finding) {
            $move = $finding->nextStep();
            $fix = $finding->package() === self::JWT && $move !== null ? (string) $move->version() : $fix;
        }
        $checker = new RecordedMoveChecker([
            'combined '.self::JWT.' '.self::SODIUM => ['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'holds' => [], 'installs' => [self::JWT => $fix]],
            'combined '.self::JWT => ['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'holds' => [], 'installs' => []],
        ], []);

        $updateNow = UpdateNow::of($findings, new MoveChecks($checker, false));

        self::assertSame([[self::JWT => '', self::SODIUM => ''], [self::JWT => '']], $checker->combinedAsked());
        self::assertNull($updateNow->command());
        $note = $updateNow->note();
        self::assertNotNull($note);
        self::assertSame([self::JWT, self::SODIUM], $note->data()['left_out'] ?? null);
    }

    /** A release that the solve spells without the `v` of the move's version still reads as below the fix. */
    public function testOnAComposerWithoutInlineConstraintsOnAnyPackageAReleaseBelowTheFixReadsAsBelowInAnySpelling(): void
    {
        $findings = self::reportOf('replace-graded', [])->sorted();
        $checker = new RecordedMoveChecker([
            'combined '.self::JWT.' '.self::SODIUM => ['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'holds' => [], 'installs' => [self::JWT => '3.4.10', self::SODIUM => '1.21.5']],
            'combined '.self::JWT => ['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'holds' => [], 'installs' => [self::JWT => '3.4.10']],
        ], []);

        $updateNow = UpdateNow::of($findings, new MoveChecks($checker, false));

        self::assertSame([self::JWT], $updateNow->members());
        $note = $updateNow->note();
        self::assertNotNull($note);
        self::assertSame([self::SODIUM], $note->data()['left_out'] ?? null);
    }

    /** Composer 2.2 and 2.3: after a refusal leaves one member out, the solve keeps the other below its fix, and the note names both and the refusal. */
    public function testOnAComposerWithoutInlineConstraintsOnAnyPackageARefusalAndAMemberBelowItsFixAreNamedTogether(): void
    {
        $refusal = self::refusalNaming(self::LARAVEL) + ['problem' => self::CONFLICT];
        $findings = self::reportOf('commonmark', [])->sorted();
        $checker = new RecordedMoveChecker([
            'combined '.self::COMMONMARK.' '.self::LARAVEL => $refusal,
            'combined '.self::COMMONMARK => ['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'holds' => [], 'installs' => []],
        ], []);

        $updateNow = UpdateNow::of($findings, new MoveChecks($checker, false));

        self::assertNull($updateNow->command());
        $note = $updateNow->note();
        self::assertNotNull($note);
        self::assertSame(['left_out' => [self::COMMONMARK, self::LARAVEL], 'problem' => self::CONFLICT], $note->data());
        self::assertStringContainsString("Composer's solver refuses ".self::LARAVEL.' together with the others', $note->text());
    }

    /** Composer 2.2 and 2.3: a plain candidate whose own check installs a release below its fix is no member. */
    public function testOnAComposerWithoutInlineConstraintsOnAnyPackageACandidateWhoseCheckInstallsBelowItsFixIsNoMember(): void
    {
        $findings = self::reportOf('commonmark', ['update '.self::COMMONMARK.' ^2.10.3 ' => ['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'holds' => [], 'installs' => '2.9.0']])->sorted();
        $checker = new RecordedMoveChecker([], []);

        $updateNow = UpdateNow::of($findings, new MoveChecks($checker, false));

        self::assertSame([[self::LARAVEL => '^12.69.0']], $checker->combinedAsked());
        self::assertSame([self::LARAVEL], $updateNow->members());
    }

    /** A package that only require-dev lists is a root requirement, also in a run without `--dev`: its constraint stays inline (ruling R13). */
    public function testOnAComposerWithoutInlineConstraintsOnAnyPackageARequireDevPackageKeepsItsConstraint(): void
    {
        $findings = array_map(
            static fn (Finding $finding): Finding => $finding->package() === self::COMMONMARK ? $finding->withComposerJson(true, [ProjectConfig::REQUIRE_DEV]) : $finding,
            self::reportOf('commonmark', [])->sorted()
        );

        $updateNow = UpdateNow::of($findings, new MoveChecks(new RecordedMoveChecker([], []), false));

        self::assertSame(['composer', 'update', self::COMMONMARK.':^2.10.3', self::LARAVEL.':^12.69.0'], $updateNow->command());
    }

    /**
     * @param array<string, mixed>|null $combined
     *
     * @return array<mixed, mixed>
     */
    private static function document(?array $combined): array
    {
        return self::documentOf('move-check‡', $combined === null ? [] : ['combined' => $combined]);
    }

    /**
     * @param array<string, array<string, mixed>> $combined
     *
     * @return array<mixed, mixed>
     */
    private static function documentOf(string $id, array $combined): array
    {
        return JsonPath::decoded(self::reportOf($id, $combined)->toArray());
    }

    /** @param array<string, array<string, mixed>> $combined each move resolves inline, and the combined updates answer as recorded */
    private static function reportOf(string $id, array $combined): Report
    {
        $case = CaseHydrator::case($id);
        $inputs = JsonPath::arrayAt($case, ['inputs']);
        $inputs['checks'] = ['*' => ['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'holds' => []]] + $combined;
        $case['inputs'] = $inputs;

        return CaseHydrator::report($case);
    }

    /** @return array<string, mixed> a refusal of the combined update whose holds name the packages */
    private static function refusalNaming(string ...$packages): array
    {
        $holds = array_map(static fn (string $package): array => ['source' => 'package', 'package' => $package, 'version' => '1.0.0', 'link' => 'conflict', 'target' => null, 'constraint' => '*'], $packages);

        return ['result' => 'refused', 'form' => null, 'reason' => null, 'holds' => $holds];
    }

    /**
     * @param array<mixed, mixed> $document
     *
     * @return array<mixed, mixed>
     */
    private static function finding(array $document, string $package): array
    {
        foreach (JsonPath::arrayAt($document, ['findings']) as $finding) {
            if (\is_array($finding) && $finding['package'] === $package) {
                return $finding;
            }
        }
        self::fail('the report has no finding '.$package);
    }

    /**
     * @param array<mixed, mixed> $document
     *
     * @return array<mixed, mixed>
     */
    private static function note(array $document, string $code): array
    {
        foreach (JsonPath::arrayAt($document, ['note_details']) as $note) {
            if (\is_array($note) && $note['code'] === $code) {
                return $note;
            }
        }
        self::fail('the report has no note '.$code);
    }
}
