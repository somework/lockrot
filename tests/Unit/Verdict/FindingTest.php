<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Lockrot\Allowlist\AllowlistEntry;
use Lockrot\Analyzer\Libyears;
use Lockrot\Analyzer\LibyearsMeasurement;
use Lockrot\Data\Advisory\Advisory;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Score\CountedAdvisory;
use Lockrot\Score\Standing;
use Lockrot\Signal\Data\AdvisoryData;
use Lockrot\Signal\Data\AdvisoryRow;
use Lockrot\Signal\Data\LeftBehindData;
use Lockrot\Signal\Data\PinnedData;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\PhpFloor;
use Lockrot\Signal\Rule\NotCheckedRule;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\Origins;
use Lockrot\Tests\Support\SignalDataStub;
use Lockrot\Tests\Unit\Signal\FactsBuilder;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\FindingDetails;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\Moves;
use Lockrot\Verdict\Score;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FindingTest extends TestCase
{
    public function testAFindingBuiltWithoutItsDetailsHasNoNextStep(): void
    {
        self::assertNull((new FindingBuilder())->withoutFlags()->build()->nextStep());
    }

    public function testTheArrayWritesTheFindingFields(): void
    {
        $signals = [
            new Signal('high', 'last release 2015-11-16 (10.8 years ago)', SignalDataStub::of('S2', ['years' => 10.8])),
            new Signal('high', 'last push 2015-11-16 (10.8 years ago)', SignalDataStub::of('S4', ['years' => 10.8])),
        ];
        $finding = (new FindingBuilder())->withPackage('phpzip/phpzip')->withVersion('2.0.8')->withVerdict(Verdict::SILENT)->withSignals($signals)->withChain(['wallabag/wallabag', 'phpzip/phpzip'])->withDataDate(new \DateTimeImmutable('2026-09-14T10:00:00+00:00'))->build();
        $array = JsonPath::decoded($finding->toArray());
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
        self::assertSame([10.8, 10.8], array_column(array_column($array['signals'], 'data'), 'years'));
        self::assertStringStartsWith('silent: last release ', JsonPath::stringAt($array, ['evidence']), 'the counted flag and its sentence');
        self::assertSame('2026-09-14T10:00:00+00:00', $array['data_date']);
        self::assertSame(['wallabag/wallabag', 'phpzip/phpzip'], $array['chain']);
    }

    public function testTheJsonKeepsTheSignalOrder(): void
    {
        $s5 = new Signal('warn', 'released 2020-09-28, before PHP 8.4 GA (2024-11-21); php constraint ">=7.3" has no upper bound', SignalDataStub::of('S5', []));
        $s6 = new Signal('warn', 'pinned to branch snapshot dev-master', SignalDataStub::of('S6', []));
        $s7 = new Signal('info', 'pulls in 1 flagged package: a/b (stale)', SignalDataStub::of('S7', []));

        $pinned = (new FindingBuilder())->withVersion('dev-master')->withVerdict(Verdict::PINNED)->withSignals([$s5, $s6, $s7])->build();
        $signals = JsonPath::decoded($pinned->toArray())['signals'];
        self::assertIsArray($signals);
        self::assertSame(['S5', 'S6', 'S7'], array_column($signals, 'id'), 'the JSON keeps signal order');
    }

    /** S8's `suggested_constraint` travels into the JSON. */
    public function testTheSuggestedConstraintTravelsIntoTheJson(): void
    {
        $s8 = new Signal('warn', 'branch 6.x last released 2022-06-20 (4.2 years ago); 8.x released 8.2.0 (2026-09-06)', SignalDataStub::of('S8', ['reachable_branch' => '8.x', 'suggested_constraint' => '^8.2']));
        $s5 = new Signal('warn', 'released 2022-06-20, before PHP 8.4 GA (2024-11-21); php constraint ">=5.5" has no upper bound', SignalDataStub::of('S5', []));

        $direct = (new FindingBuilder())->withPackage('guzzlehttp/guzzle')->withVersion('6.5.8')->withVerdict(Verdict::LEFT_BEHIND)->withSignals([$s5, $s8])->withChain(['guzzlehttp/guzzle'])->build();
        $signals = JsonPath::decoded($direct->toArray())['signals'];
        self::assertIsArray($signals);
        self::assertSame('^8.2', JsonPath::stringAt($signals, [1, 'data', 'suggested_constraint']), 'the data travels into --format=json');
    }

    /** S1's replacement is the repository's free text, and an empty one names nothing. */
    public function testTheReplacementIsS1sNonEmptyText(): void
    {
        $abandoned = static fn (Signal $signal): Finding => (new FindingBuilder())->withVerdict(Verdict::ABANDONED)->withSignals([$signal])->build();

        self::assertSame('Symfony', $abandoned(new Signal('high', 'marked abandoned', SignalDataStub::of('S1', ['replacement' => 'Symfony'])))->replacement());
        self::assertNull($abandoned(new Signal('high', 'marked abandoned', SignalDataStub::of('S1', ['replacement' => ''])))->replacement());
        self::assertNull($abandoned(new Signal('high', 'marked abandoned', SignalDataStub::of('S1', ['replacement' => null])))->replacement());
        self::assertNull($abandoned(new Signal('high', 'repository archived', SignalDataStub::of('S3', [])))->replacement());
    }

    /** report-2's `replacement` names the successor only while `abandoned` counts. */
    public function testTheCountedSuccessorNeedsACountedAbandoned(): void
    {
        $s1 = new Signal('high', 'marked abandoned', SignalDataStub::of('S1', ['replacement' => 'symfony/mailer']));
        $counted = (new FindingBuilder())->withVerdict(Verdict::ABANDONED)->withSignals([$s1])->build();
        $accepted = (new FindingBuilder())->withVerdict(Verdict::ABANDONED)->withSignals([$s1])->withAllowlistReason('kept on purpose')->build();

        self::assertSame('symfony/mailer', $counted->countedSuccessor());
        self::assertNull($accepted->countedSuccessor());
        self::assertSame(['abandoned'], $accepted->flags()->accepted());
    }

    public function testThePriorityIsTheGradeOrNone(): void
    {
        self::assertSame('high', (new FindingBuilder())->withVerdict(Verdict::OLD_PROMISE)->build()->gradeOrNone());
        self::assertSame('none', (new FindingBuilder())->build()->gradeOrNone());
    }

    public function testTheLibyearsAreWrittenToTwoDecimals(): void
    {
        self::assertSame(1.24, (new FindingBuilder())->withLibyears(LibyearsMeasurement::of(1.236))->build()->libyearsRounded());
        self::assertNull((new FindingBuilder())->withLibyears(LibyearsMeasurement::unmeasured(Libyears::UNDATED_RELEASES))->build()->libyearsRounded());
    }

    public function testAFindingWithoutFlagsHasNoFlagSet(): void
    {
        $flags = FlagSet::fromSignals([new Signal('warn', 'old promise', SignalDataStub::of('S5', []))], null, []);

        self::assertSame($flags, (new FindingBuilder())->withFlags($flags)->build()->flags());
        $this->expectException(\LogicException::class);
        (new FindingBuilder())->withoutFlags()->build()->flags();
    }

    /** The finding writes its typed score, worded with the checks that did not run. */
    public function testTheWrittenScoreIsWordedWithTheChecksThatDidNotRun(): void
    {
        $s10 = new Signal('info', 'repository activity not checked', SignalDataStub::of('S10', ['unchecked' => [['check' => 'repository_activity', 'reason' => 'no_token', 'blocks' => ['S3', 'S4']]], 'blocks' => ['S3', 'S4']]));
        $finding = (new FindingBuilder())->withSignals([new Signal('warn', 'old promise', SignalDataStub::of('S5', [])), new Signal('warn', 'no release', SignalDataStub::of('S2', [])), $s10])
            ->withFlags(FlagSet::fromSignals([new Signal('warn', 'old promise', SignalDataStub::of('S5', [])), new Signal('warn', 'no release', SignalDataStub::of('S2', []))], new AllowlistEntry('vendor/pkg', null, 'slow on purpose', null, AllowlistEntry::BY_PROJECT, ['stale']), []))
            ->build();

        $written = JsonPath::decoded($finding->toArray());

        self::assertTrue(JsonPath::boolAt($written, ['score', 'accepted', 0, 'if_counted', 'at_least']), 'S10 blocks S4, so counting stale gives at least this total');
        self::assertSame(JsonPath::decoded($finding->scoreBasis()), $written['score']);
    }

    /**
     * Packagist's `replacement` is free text. swiftmailer names `symfony/mailer`,
     * sensio/framework-extra-bundle names `Symfony`, doctrine/inflector `EnglishInflector from the
     * String component`. Only a package name is a successor: something to migrate to, count and
     * link. The free text stays in the evidence. The JSON field holds the name or null.
     */
    public function testTheSuccessorIsTheReplacementWhenItNamesAPackage(): void
    {
        $s1 = static fn (string $replacement): Signal => new Signal('high', 'marked abandoned by its repository, replacement: '.$replacement, SignalDataStub::of('S1', ['replacement' => $replacement]));
        $abandoned = static fn (Signal $signal): Finding => (new FindingBuilder())->withPackage('vendor/old')->withVerdict(Verdict::ABANDONED)->withSignals([$signal])->withChain(['vendor/old'])->build();
        self::assertSame('symfony/mailer', $abandoned($s1('symfony/mailer'))->toArray()['replacement']);
        self::assertNull($abandoned($s1('Symfony'))->toArray()['replacement']);
    }

    public function testAFindingWithoutADataDateWritesNull(): void
    {
        $finding = (new FindingBuilder())->withPackage('private/thing')->withVersion('3.0.0')->withVerdict(Verdict::UNKNOWN)->withChain([])->withNote('not from a Composer repository, not checked')->withOrigin(Origins::of(false))->build();
        self::assertNull($finding->toArray()['data_date']);
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
    }

    public function testDevIsTheLastConstructorParameterAndLowersThePriority(): void
    {
        $finding = (new FindingBuilder())->withVersion('1.2.3')->withVerdict(Verdict::ABANDONED)->withDev(true)->build();
        self::assertTrue($finding->isDev());
    }

    public function testATransitiveDevFindingIsLoweredTwice(): void
    {
        $finding = (new FindingBuilder())->withVersion('1.2.3')->withVerdict(Verdict::ABANDONED)->withChain(['vendor/root', 'vendor/pkg'])->withDev(true)->build();
        self::assertFalse($finding->isDirect());
    }

    public function testAnEmptyChainCountsAsTransitive(): void
    {
        $finding = (new FindingBuilder())->withVersion('1.2.3')->withVerdict(Verdict::ABANDONED)->withChain([])->build();
        self::assertFalse($finding->isDirect());
    }

    /**
     * The link to the replacement is lockrot's, written where the registry that named it keeps a
     * page for the name: packagist.org. The page never builds one.
     */
    public function testAReplacementNamedByPackagistIsLinkedThere(): void
    {
        $s1 = static fn (string $replacement): Signal => new Signal('high', 'marked abandoned by its repository, replacement: '.$replacement, SignalDataStub::of('S1', ['replacement' => $replacement]));
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
            ['package', 'version', 'branch', 'installed_php', 'verdict', 'priority', 'lead', 'flags', 'score', 'next_step', 'security', 'checks_missing', 'checks_skipped', 'unchecked_accepted', 'maintenance_judged', 'metadata', 'activity', 'allowlist',
                'abandoned_ignored', 'from_composer_repository', 'origin', 'replacement', 'replacement_url', 'note', 'libyears_unmeasured', 'libyears_at_least', 'direct', 'dev', 'reach', 'chain', 'depth', 'depth_unmeasured',
                'named_in', 'direct_dependents', 'signals', 'evidence', 'allowlist_reason', 'data_date', 'libyears', 'age'],
            array_keys($array)
        );
        self::assertSame('low', $array['priority'], 'the alias of the grade');
        self::assertFalse($array['direct']);
        self::assertTrue($array['dev']);
    }

    /** @return iterable<string, array{list<string>, bool, ?int, ?string}> the chain, whether the run read composer.json, the depth and its reason */
    public static function depths(): iterable
    {
        yield 'direct' => [['vendor/pkg'], true, 0, null];
        yield 'two hops below a requirement' => [['vendor/root', 'vendor/mid', 'vendor/pkg'], true, 2, null];
        yield 'unreached' => [[], true, null, 'unreached'];
        yield 'a run that read no composer.json' => [[], false, null, 'no_composer_json'];
    }

    /**
     * @param list<string> $chain
     *
     * @dataProvider depths
     */
    #[DataProvider('depths')]
    public function testTheDepthIsTheChainLessOneOrItsReason(array $chain, bool $composerJsonRead, ?int $depth, ?string $reason): void
    {
        $finding = (new FindingBuilder())->withChain($chain)->withNamedIn([])->withComposerJsonRead($composerJsonRead)->build();

        self::assertSame([$depth, $reason], [$finding->depth(), $finding->depthUnmeasured()]);
        self::assertSame([$depth, $reason], [$finding->toArray()['depth'], $finding->toArray()['depth_unmeasured']]);
    }

    /** The depth is context: two transitive findings that differ only in depth score the same. */
    public function testTheDepthChangesNoScore(): void
    {
        $signals = [new Signal(Signal::LEVEL_HIGH, 'branch 1.x last released', SignalDataStub::of(Signal::S8, [])), new Signal(Signal::LEVEL_WARN, 'php promise', SignalDataStub::of(Signal::S5, []))];
        $shallow = (new FindingBuilder())->withSignals($signals)->withChain(['vendor/root', 'vendor/pkg'])->build();
        $deep = (new FindingBuilder())->withSignals($signals)->withChain(['vendor/root', 'vendor/a', 'vendor/b', 'vendor/c', 'vendor/pkg'])->build();

        self::assertSame([1, 4], [$shallow->depth(), $deep->depth()]);
        self::assertSame([$shallow->score()->total(), $shallow->verdict()], [$deep->score()->total(), $deep->verdict()]);
    }

    /** @return iterable<string, array{list<ProjectConfig::REQUIRE|ProjectConfig::REQUIRE_DEV>}> */
    public static function sections(): iterable
    {
        yield 'require only' => [['require']];
        yield 'require-dev only' => [['require-dev']];
        yield 'both' => [['require', 'require-dev']];
        yield 'none' => [[]];
    }

    /**
     * @param list<ProjectConfig::REQUIRE|ProjectConfig::REQUIRE_DEV> $sections
     *
     * @dataProvider sections
     */
    #[DataProvider('sections')]
    public function testNamedInIsTheSectionsTheAnalyzerFound(array $sections): void
    {
        $finding = (new FindingBuilder())->withNamedIn($sections)->build();
        $finding->withComposerJson(false, [ProjectConfig::REQUIRE_DEV]);

        self::assertSame($sections, $finding->namedIn());
        self::assertSame($sections, $finding->toArray()['named_in']);
    }

    public function testAFindingIsFromAComposerRepositoryUnlessItSaysOtherwise(): void
    {
        $asked = (new FindingBuilder())->build();
        $notAsked = (new FindingBuilder())->withPackage('local/pkg')->withVersion('dev-main')->withVerdict(Verdict::UNKNOWN)->withChain(['local/pkg'])->withNote(Finding::NOTE_NOT_IN_REPOSITORY)->withOrigin(Origins::of(false))->build();

        self::assertTrue($asked->isFromComposerRepository());
        self::assertTrue($asked->toArray()['from_composer_repository']);
        self::assertFalse($notAsked->isFromComposerRepository());
        self::assertFalse($notAsked->toArray()['from_composer_repository']);
        self::assertFalse($notAsked->withSignals([new Signal(Signal::LEVEL_INFO, 'pulls in 1 flagged package: a/b (stale)', SignalDataStub::of(Signal::S7, []))])->toArray()['from_composer_repository'], 'S7 keeps it');
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
        self::assertSame(Libyears::UNDATED_RELEASES, $bare->libyearsUnmeasured());
        self::assertSame(Libyears::UNDATED_RELEASES, $bare->toArray()['libyears_unmeasured']);
        self::assertFalse($bare->toArray()['libyears_at_least']);
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
        yield 'another reason, on a finding from no repository' => [LibyearsMeasurement::unmeasured(Libyears::UNDATED_RELEASES), false];
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

    public function testTheOtherDirectDependentsAreAListWhenTheChainRootComesFirst(): void
    {
        $finding = (new FindingBuilder())->withVerdict(Verdict::STALE)->withChain(['vendor/a-root', 'vendor/pkg'])->withDirectDependents(['vendor/a-root', 'vendor/b-other'])->build();

        self::assertSame(['vendor/b-other'], $finding->otherDirectDependents());
    }

    /** Composer reads a package name without case: a successor that names the package itself is none. */
    public function testASuccessorIsAnotherPackageWhateverTheCaseOfItsName(): void
    {
        self::assertNull(Finding::successorOf('acme/old', 'ACME/Old'));
        self::assertSame('acme/new', Finding::successorOf('acme/old', 'acme/new'));
        self::assertSame('aaa/first', Finding::successorOf('acme/old', 'aaa/first'));
    }

    public function testWithSignalsReturnsANewFindingWithTheVerdictAndEverythingElseKept(): void
    {
        $at = new \DateTimeImmutable('2026-09-14T10:00:00+00:00');
        $original = (new FindingBuilder())->withDataDate($at)->withDev(true)->withDirectDependents(['vendor/pkg'])->build();
        $s7 = new Signal(Signal::LEVEL_INFO, 'pulls in 1 flagged package: vendor/dep (stale)', SignalDataStub::of(Signal::S7, ['flagged' => 1, 'packages' => []]));

        $annotated = $original->withSignals([$s7]);

        self::assertNotSame($original, $annotated);
        self::assertSame([], $original->signals());
        self::assertSame([$s7], $annotated->signals());
        self::assertTrue($annotated->isDev());
        self::assertSame($at, $annotated->dataDate());
        self::assertSame(['vendor/pkg'], $annotated->directDependents());
    }

    /** @return iterable<string, array{\Closure(Finding): mixed}> */
    public static function scoreReaders(): iterable
    {
        yield 'grade' => [static fn (Finding $finding) => $finding->verdict()];
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
        $signals = [new Signal(Signal::LEVEL_WARN, 'left behind', SignalDataStub::of(Signal::S8, []))];
        $finding = (new FindingBuilder())->withVerdict(Verdict::LEFT_BEHIND)->withSignals($signals)->withChain($chain)->withDev($dev)->withFlags(FlagSet::fromSignals($signals, null, []))->build();

        self::assertSame($reach, $finding->score()->reach());
        self::assertSame($dev, $finding->score()->isDev());
        self::assertSame($total, $finding->score()->total());
        self::assertSame($grade, $finding->verdict());
        self::assertSame(FlagSet::LEFT_BEHIND, $finding->lead());
        self::assertTrue($finding->isGraded());
    }

    public function testAVulnerableOnlyFindingIsGradedWithNoLeadAndKeepsItsReport1Verdict(): void
    {
        $flags = FlagSet::fromSignals([], null, [new CountedAdvisory('PKSA-1', 'critical', 'update')]);
        $finding = (new FindingBuilder())->withFlags($flags)->build();
        self::assertSame('critical', $finding->verdict());
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

        self::assertSame($verdict, $finding->verdict());
        self::assertFalse($finding->isGraded());
        self::assertNull($finding->lead());
    }

    public function testWithFlagsReturnsAGradedCopyAndKeepsTheFindingItWasCalledOn(): void
    {
        $finding = (new FindingBuilder())->withoutFlags()->build();
        $graded = $finding->withFlags(FlagSet::fromSignals([], null, []), false);

        self::assertNotSame($finding, $graded);
        self::assertSame('unknown', $graded->verdict());
        $this->expectException(\LogicException::class);
        $finding->verdict();
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
        $details = new FindingDetails($status, null, null, [], ['requires' => null, 'target_runs' => null, 'project_allows' => null], null, 'complete', null, [], null, FindingBuilder::unread(), FindingBuilder::unmeasuredAge(), null, null);
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
        $signals = [new Signal(Signal::LEVEL_HIGH, '2 advisories', SignalDataStub::of(Signal::S9, ['advisories' => [$row('PKSA-a', 'critical', 32), $row('PKSA-b', 'low', 2)], 'releases_read' => true, 'complete' => true]))];
        $flags = FlagSet::fromSignals($signals, null, [new CountedAdvisory('PKSA-b', 'low', 'update'), new CountedAdvisory('PKSA-a', 'critical', 'update')]);

        $document = JsonPath::decoded((new FindingBuilder())->withSignals($signals)->withFlags($flags)->build()->toArray());

        $rows = JsonPath::arrayAt($document, ['signals', 0, 'data', 'advisories']);
        self::assertSame(['PKSA-a' => true, 'PKSA-b' => false], array_column($rows, 'deciding', 'id'));
        self::assertSame(['points', 'deciding', 'fix'], \array_slice(array_keys(JsonPath::arrayAt($rows, [0])), 9, 3), 'deciding follows points');
    }

    /**
     * The written signals mark the deciding S9 row. The rules' signals and the typed lookup read
     * the rows as the rule built them, none marked.
     */
    public function testOnlyTheWrittenSignalsMarkTheDecidingRow(): void
    {
        $signals = [new Signal(Signal::LEVEL_HIGH, '2 advisories', SignalDataStub::of(Signal::S9, ['advisories' => [['id' => 'PKSA-a', 'severity' => 'critical', 'fix' => ['kind' => 'update']], ['id' => 'PKSA-b', 'severity' => 'low', 'fix' => ['kind' => 'update']]]]))];
        $flags = FlagSet::fromSignals($signals, null, [new CountedAdvisory('PKSA-b', 'low', 'update'), new CountedAdvisory('PKSA-a', 'critical', 'update')]);
        $finding = (new FindingBuilder())->withSignals($signals)->withFlags($flags)->build();

        $own = $finding->signalData(AdvisoryData::class);
        $written = $finding->writtenSignals()[0]->data();

        self::assertNotNull($own);
        self::assertSame([false, false], array_map(static fn (AdvisoryRow $row): bool => $row->isDeciding(), $own->rows()));
        self::assertInstanceOf(AdvisoryData::class, $written);
        self::assertSame([true, false], array_map(static fn (AdvisoryRow $row): bool => $row->isDeciding(), $written->rows()));
    }

    /** The typed lookup gives the data of the signal of that class, null when no such signal fired. */
    public function testTheTypedLookupFindsASignalByItsDataClass(): void
    {
        $s6 = new Signal(Signal::LEVEL_WARN, 'pinned to branch snapshot dev-main', SignalDataStub::of(Signal::S6, ['version' => 'dev-main']));
        $finding = (new FindingBuilder())->withVerdict(Verdict::PINNED)->withSignals([$s6])->build();

        self::assertSame($s6->data(), $finding->signalData(PinnedData::class));
        self::assertNull($finding->signalData(LeftBehindData::class));
    }

    /** A silent or stale flag that no S2 or S4 reading dates has a headline in years with no value. */
    public function testALivenessFlagWithoutAReadingHasNoYears(): void
    {
        $document = JsonPath::decoded((new FindingBuilder())->withVerdict(Verdict::STALE)->build()->toArray());

        self::assertSame(['unit' => 'years', 'value' => null, 'source' => null], JsonPath::arrayAt($document, ['flags', 0, 'headline']));
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
        $facts = FactsBuilder::looked(new PackageFacts($package, null, null, [new Advisory('PKSA-1', null, null, null, 'high', null)], null, null, null, true));
        $activity = ['check' => 'repository_activity', 'reason' => 'no_token', 'blocks' => ['S9', 'S3', 'S4']];
        $signals = $withS10 ? [new Signal(Signal::LEVEL_INFO, 'activity not checked', SignalDataStub::of(Signal::S10, ['unchecked' => [$activity], 'blocks' => ['S9', 'S3', 'S4']]))] : [];
        $finding = (new FindingBuilder())->withVerdict(Verdict::UNKNOWN)->withSignals($signals)->build()
            ->withDetails(FindingDetails::of($facts, null, new PhpFloor('8.4', null), [], $signals, 'HTTP 503', FindingBuilder::unmeasuredAge()));

        $written = JsonPath::arrayAt(JsonPath::decoded($finding->toArray()), ['signals']);
        $s10 = JsonPath::arrayAt($written, [0]);

        $releases = ['check' => 'releases', 'reason' => 'releases_unknown', 'blocks' => ['S9']];
        self::assertSame(Signal::S10, $s10['id']);
        self::assertCount(1, $written, 'one S10');
        self::assertSame($withS10 ? ['unchecked' => [$activity, $releases], 'blocks' => ['S3', 'S4', 'S9']] : ['unchecked' => [$releases], 'blocks' => ['S9']], $s10['data']);
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
        $signals = [new Signal(Signal::LEVEL_HIGH, '2 advisories', SignalDataStub::of(Signal::S9, ['advisories' => [$row('PKSA-a', 'high', 16), $row('PKSA-b', 'low', 2)], 'releases_read' => true, 'complete' => true]))];
        $flags = FlagSet::fromSignals($signals, null, [new CountedAdvisory('PKSA-a', 'high', 'update'), new CountedAdvisory('PKSA-b', 'low', 'update')]);

        $document = JsonPath::decoded((new FindingBuilder())->withSignals($signals)->withFlags($flags)->build()->toArray());
        $flag = JsonPath::arrayAt($document, ['flags', 0]);

        self::assertSame(['vulnerable', 'security', ['S9'], ['unit' => 'advisories', 'value' => 2, 'source' => null]], [$flag['id'], $flag['role'], $flag['signal_ids'], $flag['headline']]);
        self::assertStringContainsString('; worst: PKSA-a t', JsonPath::stringAt($flag, ['summary']));
        self::assertSame(['critical' => 0, 'high' => 1, 'medium' => 0, 'unrated' => 0, 'low' => 1], JsonPath::arrayAt($document, ['security', 'counts']), 'security counts the S9 rows');
    }

    public function testWithSignalsKeepsTheScore(): void
    {
        $signals = [new Signal(Signal::LEVEL_WARN, 'left behind', SignalDataStub::of(Signal::S8, []))];
        $finding = (new FindingBuilder())->withSignals($signals)->withFlags(FlagSet::fromSignals($signals, null, []))->build();

        self::assertSame($finding->score(), $finding->withSignals([])->score());
        self::assertSame('high', $finding->withSignals([])->verdict());
    }

    public function testWithDetailsKeepsTheFindingItWasCalledOn(): void
    {
        $finding = (new FindingBuilder())->build();
        $before = $finding->details();
        $details = new FindingDetails('read', null, null, [], ['requires' => '>=8.1', 'target_runs' => true, 'project_allows' => null], null, 'complete', null, [], null, FindingBuilder::unread(), FindingBuilder::unmeasuredAge(), null, null);

        $copy = $finding->withDetails($details);

        self::assertSame($before, $finding->details());
        self::assertSame($details, $copy->details());
    }

    /**
     * A check that blocks S2 or S4 leaves liveness incomplete, and one that blocks S3 leaves S3
     * unread. An accepted `stale` counts at least its rerun when either holds.
     *
     * @param list<list<string>> $blocks the blocks of each S10 gap
     *
     * @dataProvider livenessGaps
     */
    #[DataProvider('livenessGaps')]
    public function testTheChecksThatDidNotRunSayWhetherLivenessIsComplete(string $version, bool $judged, array $blocks, bool $livenessComplete, bool $atLeast): void
    {
        $unchecked = [];
        foreach ($blocks as $i => $blocked) {
            $unchecked[] = ['check' => 'gap-'.$i, 'reason' => 'no_token', 'blocks' => $blocked];
        }
        $signals = [new Signal(Signal::LEVEL_WARN, 'old', SignalDataStub::of(Signal::S2, ['last_release' => '2021-05-05T00:00:00+00:00', 'years' => 5.4, 'dated_by' => null]))];
        if ($unchecked !== []) {
            $signals[] = new Signal(Signal::LEVEL_INFO, 'not checked', SignalDataStub::of(Signal::S10, ['unchecked' => $unchecked]));
        }
        $entry = new AllowlistEntry('vendor/pkg', null, 'kept', null, AllowlistEntry::BY_PROJECT, [FlagSet::STALE]);
        $finding = (new FindingBuilder())->withVersion($version)->withSignals($signals)->withFlags(FlagSet::fromSignals($signals, $entry, []), $judged)->build();

        $document = JsonPath::decoded($finding->toArray());

        self::assertSame($unchecked, $document['checks_missing']);
        self::assertSame(['liveness_complete' => $livenessComplete], JsonPath::arrayAt($document, ['flags', 0, 'degree']));
        self::assertSame($atLeast, JsonPath::arrayAt($document, ['score', 'accepted', 0, 'if_counted'])['at_least']);
    }

    /** @return iterable<string, array{string, bool, list<list<string>>, bool, bool}> */
    public static function livenessGaps(): iterable
    {
        yield 'every check ran' => ['1.0.0', true, [], true, false];
        yield 'S3 unread' => ['1.0.0', true, [['S3']], true, true];
        yield 'a gap that blocks neither' => ['1.0.0', true, [['S8']], true, false];
        yield 'S2 blocked' => ['1.0.0', true, [['S2']], false, true];
        yield 'S4 blocked' => ['1.0.0', true, [['S4']], false, true];
        yield 'S3 unread, then S2 blocked' => ['1.0.0', true, [['S3'], ['S2']], false, true];
        yield 'S2 blocked, then S3 unread' => ['1.0.0', true, [['S2'], ['S3']], false, true];
        yield 'a skipped branch check beside S3 unread' => ['dev-main', true, [['S3']], true, true];
        yield 'skipped release metadata beside S3 unread' => ['1.0.0', false, [['S3']], false, true];
    }

    /** S10's gaps keep their check, their reason and their blocks. */
    public function testChecksMissingWritesS10sChecks(): void
    {
        $signals = [new Signal(Signal::LEVEL_INFO, 'not checked', SignalDataStub::of(Signal::S10, ['unchecked' => [
            ['check' => 'repository_activity', 'reason' => 'no_token', 'blocks' => ['S3', 'S4']],
            ['check' => 'advisories', 'reason' => 'offline', 'blocks' => ['S9']],
        ]]))];

        $document = JsonPath::decoded((new FindingBuilder())->withSignals($signals)->build()->toArray());

        self::assertSame([
            ['check' => 'repository_activity', 'reason' => 'no_token', 'blocks' => ['S3', 'S4']],
            ['check' => 'advisories', 'reason' => 'offline', 'blocks' => ['S9']],
        ], $document['checks_missing']);
    }

    /**
     * Without pinned, the score counts at least its rerun when S8 cannot date the tag's branch: a
     * branch snapshot whose metadata was not read.
     *
     * @dataProvider snapshots
     */
    #[DataProvider('snapshots')]
    public function testAPinnedRemovalCountsAtLeastWhenS8IsUnread(string $version, bool $judged, bool $atLeast): void
    {
        $signals = [new Signal(Signal::LEVEL_HIGH, 'marked', SignalDataStub::of(Signal::S1, ['marked_by' => 'lock'])), new Signal(Signal::LEVEL_WARN, 'pinned', SignalDataStub::of(Signal::S6, ['reason' => 'branch_snapshot', 'version' => $version]))];
        $finding = (new FindingBuilder())->withVersion($version)->withSignals($signals)->withFlags(FlagSet::fromSignals($signals, null, []), $judged)->build();
        $without = JsonPath::arrayAt(JsonPath::decoded($finding->toArray()), ['score', 'without']);

        self::assertSame(FlagSet::PINNED, JsonPath::stringAt($without, [1, 'remove', 'id']));
        self::assertSame($atLeast, JsonPath::boolAt($without, [1, 'at_least']));
    }

    /** @return iterable<string, array{string, bool, bool}> */
    public static function snapshots(): iterable
    {
        yield 'a snapshot, metadata not read' => ['dev-main', false, true];
        yield 'a snapshot, metadata read' => ['dev-main', true, false];
        yield 'a tag, metadata not read' => ['1.0.0', false, false];
    }

    /**
     * @param list<Signal>                                                        $signals
     * @param array{unit: string, value: int|float|string|null, source: ?string} $headline
     *
     * @dataProvider headlines
     */
    #[DataProvider('headlines')]
    public function testEachFlagHasItsHeadline(array $signals, string $flag, array $headline): void
    {
        $flags = JsonPath::arrayAt((new FindingBuilder())->withSignals($signals)->build()->toArray(), ['flags']);

        self::assertSame($flag, JsonPath::stringAt($flags, [0, 'id']));
        self::assertSame($headline, JsonPath::arrayAt($flags, [0, 'headline']));
    }

    /** @return iterable<string, array{list<Signal>, string, array{unit: string, value: int|float|string|null, source: ?string}}> */
    public static function headlines(): iterable
    {
        $s2 = static fn (string $level, $years): Signal => new Signal($level, 'old', SignalDataStub::of(Signal::S2, ['last_release' => '2021-05-05T00:00:00+00:00', 'years' => $years]));
        $s4 = static fn (string $level, $years): Signal => new Signal($level, 'quiet', SignalDataStub::of(Signal::S4, ['last_push' => '2022-01-10T00:00:00+00:00', 'years' => $years, 'event' => 'push']));
        yield 'marked abandoned' => [[new Signal(Signal::LEVEL_HIGH, 'marked', SignalDataStub::of(Signal::S1, []))], FlagSet::ABANDONED, ['unit' => 'reason', 'value' => 'marked', 'source' => null]];
        yield 'archived' => [[new Signal(Signal::LEVEL_HIGH, 'archived', SignalDataStub::of(Signal::S3, []))], FlagSet::ABANDONED, ['unit' => 'reason', 'value' => 'archived', 'source' => null]];
        yield 'pinned' => [[new Signal(Signal::LEVEL_WARN, 'pinned', SignalDataStub::of(Signal::S6, ['reason' => 'branch_snapshot']))], FlagSet::PINNED, ['unit' => 'reason', 'value' => 'branch_snapshot', 'source' => null]];
        yield 'an old promise' => [[new Signal(Signal::LEVEL_WARN, 'old promise', SignalDataStub::of(Signal::S5, ['written_for_php' => 7]))], FlagSet::OLD_PROMISE, ['unit' => 'php', 'value' => 7, 'source' => null]];
        yield 'capped' => [[new Signal(Signal::LEVEL_WARN, 'capped', SignalDataStub::of(Signal::S11, ['stops_at' => '8.1']))], FlagSet::CAPPED, ['unit' => 'php_minor', 'value' => '8.1', 'source' => null]];
        yield 'stale by its release' => [[$s2(Signal::LEVEL_WARN, 5.4)], FlagSet::STALE, ['unit' => 'years', 'value' => 5.4, 'source' => 'release']];
        yield 'stale by whole years' => [[$s2(Signal::LEVEL_WARN, 6.0)], FlagSet::STALE, ['unit' => 'years', 'value' => 6.0, 'source' => 'release']];
        yield 'the release is older' => [[$s2(Signal::LEVEL_HIGH, 5.4), $s4(Signal::LEVEL_HIGH, 4.7)], FlagSet::SILENT, ['unit' => 'years', 'value' => 5.4, 'source' => 'release']];
        yield 'the push is older' => [[$s2(Signal::LEVEL_HIGH, 4.7), $s4(Signal::LEVEL_HIGH, 5.4)], FlagSet::SILENT, ['unit' => 'years', 'value' => 5.4, 'source' => 'push']];
        yield 'as old as each other' => [[$s2(Signal::LEVEL_HIGH, 5.0), $s4(Signal::LEVEL_HIGH, 5.0)], FlagSet::SILENT, ['unit' => 'years', 'value' => 5.0, 'source' => 'release']];
        yield 'the push alone' => [[$s2(Signal::LEVEL_HIGH, null), $s4(Signal::LEVEL_HIGH, 3.0)], FlagSet::SILENT, ['unit' => 'years', 'value' => 3.0, 'source' => 'push']];
        yield 'no years' => [[$s2(Signal::LEVEL_WARN, null)], FlagSet::STALE, ['unit' => 'years', 'value' => null, 'source' => null]];
    }

    /** Abandoned reads the signals that fired in its order, and every fired flag is written. */
    public function testAbandonedNamesItsSignalsAndItsDegree(): void
    {
        $signals = [
            new Signal(Signal::LEVEL_HIGH, 'marked', SignalDataStub::of(Signal::S1, ['marked_by' => 'lock'])),
            new Signal(Signal::LEVEL_HIGH, 'old', SignalDataStub::of(Signal::S2, ['last_release' => '2021-05-05T00:00:00+00:00', 'years' => 5.4])),
            new Signal(Signal::LEVEL_WARN, 'pinned', SignalDataStub::of(Signal::S6, ['reason' => 'no_stable_release', 'version' => '1.0.0'])),
        ];

        $flags = JsonPath::arrayAt((new FindingBuilder())->withSignals($signals)->build()->toArray(), ['flags']);

        self::assertSame([FlagSet::ABANDONED, FlagSet::PINNED], array_column($flags, 'id'));
        self::assertSame(['S1', 'S2'], JsonPath::arrayAt($flags, [0, 'signal_ids']));
        self::assertSame(['reasons' => ['marked'], 'liveness_complete' => true], JsonPath::arrayAt($flags, [0, 'degree']));
    }

    /**
     * Unread release data joins the finding's S10 after the other signals, and the S10 blocks are
     * each blocked signal once.
     *
     * @param list<Signal> $signals
     * @param list<string> $blocks
     *
     * @dataProvider releasesBeside
     */
    #[DataProvider('releasesBeside')]
    public function testUnreadReleasesKeepTheOtherSignals(array $signals, array $blocks): void
    {
        $lock = LockFile::fromArray(['packages' => [['name' => 'vendor/pkg', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/']]]);
        $package = $lock->find('vendor/pkg');
        self::assertNotNull($package);
        $facts = FactsBuilder::looked(new PackageFacts($package, null, null, [new Advisory('PKSA-1', null, null, null, 'high', null)], null, null, null, true));
        $finding = (new FindingBuilder())->withVerdict(Verdict::UNKNOWN)->withSignals($signals)->build()
            ->withDetails(FindingDetails::of($facts, null, new PhpFloor('8.4', null), [], $signals, 'HTTP 503', FindingBuilder::unmeasuredAge()));

        $written = JsonPath::arrayAt(JsonPath::decoded($finding->toArray()), ['signals']);

        self::assertSame([Signal::S6, Signal::S10], array_column($written, 'id'));
        self::assertSame($blocks, JsonPath::arrayAt($written, [1, 'data', 'blocks']));
    }

    /** @return iterable<string, array{list<Signal>, list<string>}> */
    public static function releasesBeside(): iterable
    {
        $s6 = new Signal(Signal::LEVEL_WARN, 'pinned', SignalDataStub::of(Signal::S6, ['reason' => 'no_stable_release', 'version' => '1.0.0']));
        $gaps = [['check' => 'a', 'reason' => 'no_token', 'blocks' => ['S9']], ['check' => 'b', 'reason' => 'no_token', 'blocks' => ['S9', 'S4']]];
        yield 'beside an S10 whose gaps share a block' => [[$s6, new Signal(Signal::LEVEL_INFO, 'not checked', SignalDataStub::of(Signal::S10, ['unchecked' => $gaps, 'blocks' => ['S9', 'S4']]))], ['S4', 'S9']];
        yield 'with no S10' => [[$s6], ['S9']];
    }

    /** SPEC §5.6, ruling R1: the S9 row at a release that the joint request moves to names no holder, and another signal stays as it was. */
    public function testMovesWithAJointReleaseClearTheHoldersOfTheS9RowAtThatRelease(): void
    {
        $s6 = new Signal(Signal::LEVEL_INFO, 'pinned', SignalDataStub::of('S6', []));
        $row = SignalDataStub::row(['id' => 'PKSA-1', 'severity' => 'high', 'fix' => ['kind' => 'upgrade', 'version' => '2.0.0', 'held_by' => [['package' => 'h/x', 'version' => '1.0.0', 'constraint' => '^1.0']]]]);
        $signals = [$s6, new Signal(Signal::LEVEL_HIGH, 'advisory', new AdvisoryData([$row], true, true))];
        $finding = (new FindingBuilder())->withPackage('a/vulnerable')->withSignals($signals)->withChain(['a/vulnerable'])->build();
        $holders = static function (Finding $finding): int {
            $data = $finding->signalData(AdvisoryData::class);
            self::assertInstanceOf(AdvisoryData::class, $data);

            return \count($data->rows()[0]->fix()->heldBy());
        };

        $joint = $finding->withMoves(new Moves(null, null, null, null, [], [], ['2.x' => '2.0.0']));

        self::assertSame([0, 1], [$holders($joint), $holders($finding->withMoves(new Moves(null, null, null, null, [], [], [])))]);
        self::assertSame($s6, $joint->signals()[0]);
    }

    /** report-2: each S9 row carries its state against the baseline entry that the standing compared. */
    public function testEachS9RowWritesItsBaselineState(): void
    {
        $signals = [new Signal(Signal::LEVEL_HIGH, 'advisory', new AdvisoryData([SignalDataStub::row(['id' => 'PKSA-1', 'severity' => 'high'])], true, true))];
        $finding = (new FindingBuilder())->withPackage('a/vulnerable')->withSignals($signals)->withChain(['a/vulnerable'])
            ->withFlags(FlagSet::fromSignals($signals, null, [new CountedAdvisory('PKSA-1', 'high', 'update')]))->build();
        $s9 = static function (array $written): array {
            foreach (JsonPath::arrayAt($written, ['signals']) as $signal) {
                if (\is_array($signal) && ($signal['id'] ?? null) === 'S9') {
                    return JsonPath::arrayAt($signal, ['data', 'advisories', '0']);
                }
            }
            self::fail('no S9 written');
        };

        self::assertSame(['state' => 'new', 'since' => null, 'covered_by' => null], $s9(JsonPath::decoded($finding->toArray(Standing::newPackage([], [new CountedAdvisory('PKSA-1', 'high', 'update')]))))['baseline']);
        self::assertNull($s9(JsonPath::decoded($finding->toArray()))['baseline']);
    }
}
