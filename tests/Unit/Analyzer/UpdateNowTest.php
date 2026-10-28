<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Analyzer;

use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\UpdateNow;
use Lockrot\Output\RunFooter;
use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\FindingRelations;
use Lockrot\Tests\Support\JsonPath;
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

    /** R6-3: when require-dev lists php too, the raise of the line says what it does to require-dev, as the member's own move does. */
    public function testTheUpdateNowRaiseSaysItRemovesPhpFromRequireDevWhenRequireDevListsIt(): void
    {
        $case = CaseHydrator::case('move-check‡');
        $inputs = JsonPath::arrayAt($case, ['inputs']);
        $inputs['checks'] = ['*' => ['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'holds' => []]];
        $inputs['releases'] = array_merge(JsonPath::arrayAt($inputs, ['releases']), [['version' => 'v2.3.5', 'time' => '2026-07-01T00:00:00+00:00', 'php' => '>=8.2']]);
        $inputs['root'] = JsonPath::arrayAt($inputs, ['root']) + ['require-dev' => ['php' => '^8.1']];
        $case['inputs'] = $inputs;
        $report = CaseHydrator::report($case);
        $move = JsonPath::arrayAt(self::finding(JsonPath::decoded($report->toArray()), self::PACKAGE), ['next_step']);

        self::assertSame(['composer', 'require', 'php:>=8.2', '--no-interaction', '--no-update'], JsonPath::arrayAt($move, ['commands', 0]), 'the member\'s own move');
        self::assertSame('raise require.php to >=8.2, which removes it from require-dev, then ', RunFooter::platformStep($report));
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

    /** With no command there is no raise, so the line removes nothing from require-dev. */
    public function testAnUpdateNowWithoutACommandRemovesNothingFromRequireDev(): void
    {
        $report = self::reportOf('move-check‡', ['combined' => ['result' => 'not_checked', 'form' => null, 'reason' => 'install_time_budget', 'holds' => []]]);

        self::assertSame([null, null, false], [$report->updateNowCommand(), $report->updateNowRaise(), $report->updateNowRaiseRemovesRequireDev()]);
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
