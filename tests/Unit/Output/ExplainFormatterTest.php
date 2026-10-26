<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Allowlist\AcceptedCheck;
use Lockrot\Allowlist\UncheckedAcceptanceEntry;
use Lockrot\Analyzer\LibyearsMeasurement;
use Lockrot\Analyzer\Report;
use Lockrot\Data\Abandoned\AbandonedIgnoreRule;
use Lockrot\Data\Advisory\Advisory;
use Lockrot\Data\Advisory\AdvisoryFeed;
use Lockrot\Data\Advisory\AdvisoryIgnoreMatch;
use Lockrot\Data\Advisory\AdvisoryNameCoverage;
use Lockrot\Data\Advisory\IgnoredAdvisory;
use Lockrot\Data\Forge\ActivityLookup;
use Lockrot\Data\Forge\LocatedRepository;
use Lockrot\Data\Forge\RepoRef;
use Lockrot\Data\Forge\RepositoryActivity;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Explain\Explanation;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Output\ConsoleMarkup;
use Lockrot\Output\ExplainFormatter;
use Lockrot\Output\FindingBlock;
use Lockrot\Signal\AbandonedIgnored;
use Lockrot\Signal\AgeMeasure;
use Lockrot\Signal\AgeReading;
use Lockrot\Signal\Data\Check;
use Lockrot\Signal\PhpFloor;
use Lockrot\Signal\Signal;
use Lockrot\Signal\Thresholds;
use Lockrot\Solver\MoveCheck;
use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\Notes;
use Lockrot\Tests\Support\SignalDataStub;
use Lockrot\Tests\Support\TextGolden;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use Lockrot\Verdict\Age;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\FindingDetails;
use Lockrot\Verdict\Moves;
use Lockrot\Verdict\Verdict;
use Lockrot\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** `--explain` as text and as explain-2 JSON. */
final class ExplainFormatterTest extends TestCase
{
    private static function plain(Explanation $explanation): string
    {
        return ConsoleMarkup::render((new ExplainFormatter())->text($explanation), false);
    }

    /** @param list<string> $notes */
    private static function report(array $notes = []): Report
    {
        return new Report([], Notes::texts($notes), new \DateTimeImmutable(F::NOW), 3, 0);
    }

    private static function explain(Finding $finding): Explanation
    {
        return new Explanation($finding, F::facts(F::package(['version' => $finding->version()]), F::metadata([[$finding->version(), '2020-01-01T00:00:00+00:00']])), new Thresholds(), '8.4', self::report(['run note']));
    }

    public function testTheMonologBridgeCaseReadsEveryBlockInOrder(): void
    {
        $text = self::plain(self::caseExplanation('B'));
        $blocks = [];
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^(age|repository metadata|repository|score|do|flags)(\s|$)/', $line, $m) === 1) {
                $blocks[] = $m[1];
            }
        }

        self::assertStringStartsWith("symfony/monolog-bridge v2.6.13 — critical: left-behind · old-promise · vulnerable\n  direct requirement (composer.json require)\n\nage       installed  v2.6.13   2016-01-14   10.7 years ago                dated by symfony/symfony\n", $text);
        self::assertSame(['age', 'repository', 'score', 'do', 'flags', 'repository metadata'], $blocks);
        self::assertStringContainsString("\nscore     36 = left-behind 16 + old-promise 4 [¼ of 16] + vulnerable 16 [high advisory]     critical from 32\n  maintenance  left-behind 16 (lead) · old-promise 4 (¼ of 16)\n  security     vulnerable 16 (high advisory CVE-2026-45077)\n  reach        direct requirement: maintenance not halved for reach\n  bands        low 1 · medium 8 · high 16 · critical 32 (score model 1, verdicts.md#score)\n", $text);
        self::assertStringContainsString("\n          2.x        v2.8.52   2019-11-13   6.9 years ago                 past release-high-years 5; dated by symfony/symfony\n", $text);
        self::assertStringContainsString("\n  feeds        packagist.org: 1 on record, 1 affects v2.6.13\n  accepted     none\n  not checked  nothing\n", $text);
        self::assertStringContainsString("\n     5.x      v5.4.52       2026-05-07   >=7.2.5        1 of 1 · upgrade (lowest fix); held by composer.json; after it: 0, ok, if Composer resolves it\n", $text);
        self::assertStringContainsString("\n   * 2.x      v2.8.52       2019-11-13   >=5.3.9        0 of 1\n", $text);
        self::assertStringContainsString("\n               PKSA-4wjj-gy1p-ft3r · reported 2026-05-20 · 16 points · fix: upgrade to 5.x from v5.4.52\n", $text);
    }

    public function testATransitivePackageReadsItsChainAndItsHops(): void
    {
        $text = self::plain(self::caseExplanation('F'));

        self::assertStringContainsString("\n  via guzzlehttp/guzzle > symfony/polyfill-intl-idn: 1 hop from the nearest composer.json line; no line of composer.json names it\n", $text);
        self::assertStringContainsString("\n          last push            not read (allowlisted)\n          libyears not measured: ", $text);
        self::assertStringContainsString("\n  reach        via guzzlehttp/guzzle: maintenance would be halved (none counts); security is never halved for reach\n", $text);
    }

    /**
     * @param list<string>                         $chain
     * @param list<ProjectConfig::REQUIRE|ProjectConfig::REQUIRE_DEV> $namedIn
     *
     * @dataProvider reaches
     */
    #[DataProvider('reaches')]
    public function testTheReachLineSaysWhereComposerJsonNamesThePackage(array $chain, array $namedIn, bool $read, bool $dev, string $expected): void
    {
        $finding = (new FindingBuilder())->withPackage('a/leaf')->withChain($chain)->withNamedIn($namedIn)->withComposerJsonRead($read)->build();

        self::assertSame($expected, ExplainFormatter::reachLine($finding, $dev));
    }

    /** @return iterable<string, array{list<string>, list<ProjectConfig::REQUIRE|ProjectConfig::REQUIRE_DEV>, bool, bool, string}> */
    public static function reaches(): iterable
    {
        yield 'direct' => [['a/leaf'], [ProjectConfig::REQUIRE], true, false, 'direct requirement (composer.json require)'];
        yield 'direct in require-dev' => [['a/leaf'], [ProjectConfig::REQUIRE_DEV], true, true, 'direct requirement (composer.json require-dev)'];
        yield 'named by no line' => [['a/root', 'a/mid', 'a/leaf'], [], true, false, 'via a/root > a/mid > a/leaf: 2 hops from the nearest composer.json line; no line of composer.json names it'];
        yield 'named in require-dev only' => [['a/root', 'a/leaf'], [ProjectConfig::REQUIRE_DEV], true, false, 'via a/root > a/leaf: 1 hop; composer.json names it in require-dev, which this run did not walk (pass --dev)'];
        yield 'unreached' => [[], [], true, false, 'unreached: lockrot found no path from your require (require-dev counts only with --dev) to it'];
        yield 'unreached with --dev' => [[], [], true, true, 'unreached: lockrot found no path from your require or require-dev to it'];
        yield 'lock-only' => [[], [], false, false, 'depth not measured: the run read no composer.json'];
    }

    /** @dataProvider repositories */
    #[DataProvider('repositories')]
    public function testTheRepositoryLineReadsTheActivityLookup(ActivityLookup $lookup, AgeReading $push, string $expected): void
    {
        self::assertSame($expected, ExplainFormatter::repositoryLine(self::withAge((new FindingBuilder())->build(), $lookup, $push)));
    }

    /** @return iterable<string, array{ActivityLookup, AgeReading}> */
    public static function repositories(): iterable
    {
        $ref = new RepoRef(RepoRef::GITLAB, 'gitlab.com', 'hoaproject/Consistency');
        $pushed = AgeReading::measured(new \DateTimeImmutable('2021-04-28T10:00:00+00:00'), 54, 5.4, null, null, 'high', AgeReading::PUSH);
        $none = AgeReading::unmeasuredBecause(AgeMeasure::UNDATED);
        $read = new RepositoryActivity($ref, null, new \DateTimeImmutable('2021-04-28T10:00:00+00:00'), new \DateTimeImmutable('2026-10-01T10:00:00+00:00'), new \DateTimeImmutable('2026-10-01T09:00:00+00:00'));
        yield 'read, flag not read, from the cache' => [ActivityLookup::read($read), $pushed, 'GitLab gitlab.com/hoaproject/Consistency: last commit 2021-04-28, archived flag not read (no token) · fetched 2026-10-01 (cached 2026-10-01)'];
        $archived = new RepositoryActivity(new RepoRef(RepoRef::GITHUB, 'github.com', 'a/b'), true, null, new \DateTimeImmutable('2026-10-01T10:00:00+00:00'));
        yield 'read, archived, no push' => [ActivityLookup::read($archived), $none, 'GitHub github.com/a/b: no push reported, archived · fetched 2026-10-01'];
        yield 'not found' => [ActivityLookup::notFound(new RepoRef(RepoRef::GITHUB, 'github.com', 'hoaproject/Consistency')), $none, 'GitHub github.com/hoaproject/Consistency: the host answered 404 (private, renamed or removed)'];
        yield 'host not supported' => [ActivityLookup::notRead(ActivityLookup::HOST_NOT_SUPPORTED, LocatedRepository::unsupported('codeberg.org', null)), $none, 'lockrot cannot ask codeberg.org about repository activity'];
        yield 'not asked, with a repository' => [ActivityLookup::notRead(ActivityLookup::ALLOWLISTED, LocatedRepository::located(new RepoRef(RepoRef::GITHUB, 'github.com', 'symfony/polyfill-intl-idn'))), $none, 'github.com/symfony/polyfill-intl-idn: not asked (allowlisted)'];
        yield 'not asked, no repository' => [ActivityLookup::notRead(ActivityLookup::NO_REPOSITORY, LocatedRepository::none(null)), $none, 'repository activity not asked (no repository found)'];
    }

    public function testTheAgeBlockNamesEachThresholdAndTheLibyearsLine(): void
    {
        $finding = (new FindingBuilder())->withVersion('v1.2.0')->withLibyears(LibyearsMeasurement::of(2.74))->build();
        $warn = AgeReading::measured(new \DateTimeImmutable('2022-01-01T00:00:00+00:00'), 37, 3.7, 'v1.9.0', 'a/mono', 'warn', AgeReading::RELEASE);
        $finding = self::withAge($finding, ActivityLookup::read(new RepositoryActivity(new RepoRef(RepoRef::GITHUB, 'github.com', 'a/b'), false, null, new \DateTimeImmutable(F::NOW))), AgeReading::unmeasuredBecause('no_token'), AgeReading::unmeasuredBecause(AgeMeasure::SHARED_COMMIT), $warn, AgeReading::unmeasuredBecause(AgeMeasure::NO_STABLE_RELEASE));
        $text = self::plain(self::explain($finding));

        self::assertStringContainsString("\nage       installed  v1.2.0    not read (dated only by a commit its tags share)\n          1.x        v1.9.0    2022-01-01   3.7 years ago                 past release-warn-years 3; dated by a/mono\n          newest               not read (no stable release)\n          last push            not read (no token)\n          libyears 2.7 behind the newest stable release\n", $text);
    }

    public function testTheAgeBlockNamesTheHostsEventABelowAndAHighThresholdAndAnUnmeasuredLibyears(): void
    {
        $finding = (new FindingBuilder())->withVersion('v1.2.0')->withLibyears(null)->build();
        $high = AgeReading::measured(new \DateTimeImmutable('2020-01-01T00:00:00+00:00'), 67, 6.7, 'v1.9.0', null, 'high', AgeReading::RELEASE);
        $fresh = AgeReading::measured(new \DateTimeImmutable('2026-09-01T00:00:00+00:00'), 1, 0.1, null, null, null, AgeReading::PUSH);
        $finding = self::withAge($finding, ActivityLookup::read(new RepositoryActivity(new RepoRef(RepoRef::GITLAB, 'gitlab.com', 'a/b'), false, null, new \DateTimeImmutable(F::NOW))), $fresh, null, $high);
        $text = self::plain(self::explain($finding));

        self::assertStringContainsString("\n          1.x        v1.9.0    2020-01-01   6.7 years ago                 past release-high-years 5\n", $text);
        self::assertStringContainsString("\n          last commit          2026-09-01   0.1 years ago                 below push-warn-years 3\n", $text);
        self::assertMatchesRegularExpression('/\n          libyears not measured: [a-z]/', $text);
    }

    public function testAMissingCheckReadsItsReasonWords(): void
    {
        $finding = (new FindingBuilder())->withSignals([new Signal(Signal::LEVEL_INFO, 'not checked', SignalDataStub::of(Signal::S10, ['unchecked' => [['check' => 'repository_activity', 'reason' => 'no_token', 'blocks' => ['S3', 'S4']]]]))])->build();

        self::assertStringContainsString("\n  not checked  S3, S4 (no token)\n", self::plain(self::explain($finding)));
    }

    /** An accepted check reads its S10 words, then the entry that accepted it. */
    public function testAnAcceptedCheckNamesTheEntryThatAcceptedIt(): void
    {
        $check = new Check('repository_activity', 'host_not_supported', ['S3', 'S4']);
        $entry = UncheckedAcceptanceEntry::fromConfig(0, ['check' => 'repository_activity', 'host' => 'codeberg.org', 'reason' => 'we watch it by hand', 'expires' => '2027-03-31']);
        $finding = (new FindingBuilder())->build();
        $details = FindingDetails::of(F::looked(F::facts(F::package(), F::metadata([['1.0.0', '2020-01-01T00:00:00+00:00']]))), null, new PhpFloor('8.4'), [], [], null, $finding->details()->age(), [new AcceptedCheck($check, $entry)]);
        $finding = $finding->withDetails($details);

        self::assertStringContainsString('  not checked  S3, S4 (host not supported; accepted by your accept-unchecked list (host codeberg.org, until 2027-03-31): we watch it by hand)', self::plain(self::explain($finding)));
        self::assertSame('checks accepted: S3, S4 (host not supported; accepted by your accept-unchecked list: we watch it by hand)', FindingBlock::checksAccepted($finding));
    }

    public function testAScoreZeroPackageHasNoMoveAndNoBandFloor(): void
    {
        $text = self::plain(self::explain((new FindingBuilder())->build()));

        self::assertStringStartsWith("vendor/pkg 1.0.0 — ok\n", $text);
        self::assertStringContainsString("\nscore     0\n", $text);
        self::assertStringContainsString("\ndo        nothing to do: nothing counts\n", $text);
        self::assertStringEndsWith("\nnote: run note\n", $text);
    }

    /**
     * Text from the lock prints as written, and the only styles on the page are lockrot's own.
     *
     * @dataProvider textsThatLookLikeMarkup
     */
    #[DataProvider('textsThatLookLikeMarkup')]
    public function testTextThatLooksLikeMarkupPrintsAsWrittenAndLeavesNoStyleBehind(string $text): void
    {
        $finding = (new FindingBuilder())->withPackage('vendor/'.$text)->withVersion($text)->withVerdict(Verdict::ABANDONED)->withChain(['vendor/'.$text])->build();
        $explanation = new Explanation($finding, F::facts(F::package(['version' => '1.0.0'])), new Thresholds(), '8.4', self::report(['run: '.$text]));

        $markup = (new ExplainFormatter())->text($explanation);
        $plain = ConsoleMarkup::render($markup, false);
        $decorated = ConsoleMarkup::render($markup, true);

        self::assertStringStartsWith('vendor/'.$text.' '.$text." — critical: abandoned\n", $plain);
        self::assertStringStartsWith("\033[1mvendor/".$text.' '.$text."\033[22m — ", $decorated, 'the package and its version print bold');
        self::assertStringEndsWith("\nnote: run: ".$text."\n", $plain);
        self::assertSame($plain, str_replace(["\033[1m", "\033[22m", "\033[31m", "\033[39m"], '', $decorated), 'nothing but lockrot\'s styles');
    }

    /** A terminal obeys a control character from the lock, so each prints as an escape. */
    public function testControlCharactersFromOutsidePrintAsEscapes(): void
    {
        $evil = "evil\x1b]52;c;cHduZWQ=\x07\x1b[2J\u{9B}31m\ntail";
        $finding = (new FindingBuilder())->withPackage('vendor/'.$evil)->withVersion($evil)->withVerdict(Verdict::ABANDONED)->withChain(['vendor/'.$evil])->build();
        $explanation = new Explanation($finding, F::facts(F::package(['version' => '1.0.0'])), new Thresholds(), '8.4', self::report(['run: '.$evil]));

        $plain = ConsoleMarkup::render((new ExplainFormatter())->text($explanation), false);

        $shown = 'evil\x1B]52;c;cHduZWQ=\x07\x1B[2J\u{009B}31m\ntail';
        self::assertSame(0, preg_match('/[\x00-\x09\x0B-\x1F\x7F]|\xC2[\x80-\x9F]/', $plain), 'no control character but the line feed');
        self::assertStringStartsWith('vendor/'.$shown.' '.$shown.' — ', $plain);
        self::assertStringEndsWith("\nnote: run: ".$shown."\n", $plain);
    }

    /**
     * An advisory's CVE and title come from the feed: they print as written in the score and flags
     * blocks.
     *
     * @dataProvider textsThatLookLikeMarkup
     */
    #[DataProvider('textsThatLookLikeMarkup')]
    public function testAnAdvisoryTextThatLooksLikeMarkupPrintsAsWritten(string $text): void
    {
        $case = CaseHydrator::case('B');
        self::assertIsArray($case['inputs']);
        self::assertIsArray($case['inputs']['advisories']);
        self::assertIsArray($case['inputs']['advisories'][0]);
        $case['inputs']['advisories'][0]['cve'] = 'CVE-'.$text;
        $case['inputs']['advisories'][0]['title'] = 'title '.$text;
        $analysis = CaseHydrator::analysisOf($case);
        $finding = $analysis->finding('symfony/monolog-bridge');
        $facts = $analysis->facts('symfony/monolog-bridge');
        self::assertNotNull($finding);
        self::assertNotNull($facts);

        $markup = (new ExplainFormatter())->text(new Explanation($finding, $facts, new Thresholds(), '8.4', $analysis->report()));
        $plain = ConsoleMarkup::render($markup, false);

        self::assertMatchesRegularExpression('/\n  security     vulnerable \d+ \(high advisory '.preg_quote('CVE-'.$text, '/').'\)\n/', $plain);
        self::assertStringContainsString('CVE-'.$text.'  title '.$text."\n", $plain);
        self::assertSame($plain, str_replace(["\033[1m", "\033[22m", "\033[31m", "\033[39m", "\033[33m", "\033[32m", "\033[36m"], '', ConsoleMarkup::render($markup, true)), 'nothing but lockrot\'s styles');
    }

    /** @return iterable<string, array{string}> */
    public static function textsThatLookLikeMarkup(): iterable
    {
        yield 'a style tag' => ['<<fg=red>>'];
        yield 'a link tag' => ['<<href=x>>'];
        yield 'an escaped tag' => ['a\\<b'];
        yield 'a trailing backslash' => ['a\\'];
    }

    public function testTheBranchTableIsCapped(): void
    {
        $releases = [];
        for ($i = 20; $i >= 1; --$i) {
            $releases[] = ['0.0.'.$i, '2020-01-01T00:00:00+00:00'];
        }
        $finding = (new FindingBuilder())->withVersion('0.0.3')->build();
        $text = self::plain(new Explanation($finding, F::facts(F::package(['version' => '0.0.3']), F::metadata($releases)), new Thresholds(), '8.4', self::report()));

        self::assertSame(Explanation::BRANCH_ROWS, preg_match_all('/^   [ *] 0\.0\.\d+ /m', $text));
        self::assertStringContainsString("\n     … and 5 more\n", $text);
    }

    public function testABranchTableAtTheCapHasNoMoreLine(): void
    {
        $releases = [];
        for ($i = Explanation::BRANCH_ROWS; $i >= 1; --$i) {
            $releases[] = ['0.0.'.$i, '2020-01-01T00:00:00+00:00'];
        }
        $finding = (new FindingBuilder())->withVersion('0.0.3')->build();
        $text = self::plain(new Explanation($finding, F::facts(F::package(['version' => '0.0.3']), F::metadata($releases)), new Thresholds(), '8.4', self::report()));

        self::assertSame(Explanation::BRANCH_ROWS, preg_match_all('/^   [ *] 0\.0\.\d+ /m', $text));
        self::assertStringNotContainsString(' more', $text);
    }

    public function testTheIgnoredMarkingNamesEachConstraintOfEveryRuleOnce(): void
    {
        $finding = (new FindingBuilder())->withPackage('doctrine/cache')->withVersion('1.0.0')->build();
        $rules = [new AbandonedIgnoreRule('doctrine/*', null, ['^3.0', '^1.0']), new AbandonedIgnoreRule('doctrine/cache', null, ['^1.0'])];
        $ignored = new AbandonedIgnored('audit.ignore-abandoned', $rules, AbandonedIgnored::MARKED_BY_LOCK, null, null);

        $text = self::plain(self::explain($finding->withDetails(FindingBuilder::detailsOf($finding, true, null, null, $ignored))));

        self::assertStringContainsString(' The constraint ^3.0, ^1.0 on that entry is not applied, as composer audit does not apply it.', $text);
    }

    /** D10: a row whose move Composer did not check keeps the caveat and names no answer of the solver. */
    public function testARowThatComposerDidNotCheckKeepsTheCaveat(): void
    {
        $case = CaseHydrator::case('E');
        $package = JsonPath::stringAt($case, ['finding', 'package']);
        $analysis = CaseHydrator::analysisOf($case);
        $finding = $analysis->finding($package);
        $facts = $analysis->facts($package);
        self::assertNotNull($finding);
        self::assertNotNull($facts);
        $moves = $finding->details()->moves();
        $after = $moves->rowIfApplied('5.x');
        self::assertNotNull($after);
        $unchecked = new Moves($moves->nextStep(), $moves->moveIn(), $moves->fixKind(), $moves->partial(), ['5.x' => $after], ['5.x' => MoveCheck::notChecked(MoveCheck::OFFLINE)]);

        $text = self::plain(new Explanation($finding->withMoves($unchecked), $facts, new Thresholds(), '8.4', $analysis->report()));

        self::assertStringContainsString(" 3 of 3 · composer update (lowest fix); after it: 0, ok, if Composer resolves it\n", $text);
    }

    public function testJsonCarriesTheEnvelopeAndTheExplanation(): void
    {
        $finding = (new FindingBuilder())->withDataDate(new \DateTimeImmutable(F::NOW))->build();
        $explanation = new Explanation($finding, F::facts(F::package(), F::metadata([['1.0.0', '2026-01-01T00:00:00+00:00']])), new Thresholds(), '8.4', self::report());

        $encoded = (new ExplainFormatter())->json($explanation);
        $json = json_decode($encoded, true);

        self::assertStringStartsWith("{\n    \"\$schema\": \"https://lockrot.dev/schema/explain-2.json\",\n    \"lockrot\": {\n", $encoded, 'pretty-printed');
        self::assertStringEndsWith("}\n", $encoded);
        self::assertIsArray($json);
        self::assertSame(['version' => Version::STRING, 'schema' => 2], $json['lockrot']);
        self::assertSame('vendor/pkg', $json['package']);
        self::assertIsArray($json['finding']);
        self::assertSame('ok', $json['finding']['verdict']);
    }

    public function testAPackageWithNoRepositoryMetadataSaysWhy(): void
    {
        $finding = (new FindingBuilder())->withVersion('1.0.0')->build();
        $noted = (new FindingBuilder())->withVersion('1.0.0')->withNote('metadata unavailable (HTTP 503)')->build();
        $explain = static fn (Finding $finding): string => self::plain(new Explanation($finding, F::facts(F::package(['version' => '1.0.0'])), new Thresholds(), '8.4', self::report()));

        self::assertStringContainsString("\nrepository metadata\n  none — not available\n", $explain($finding));
        self::assertStringContainsString("\nrepository metadata\n  none — metadata unavailable (HTTP 503)\n", $explain($noted));
    }

    public function testATagThatSharesItsCommitIsDatedByTheCommit(): void
    {
        $at = new \DateTimeImmutable('2024-03-01T00:00:00+00:00');
        $metadata = new PackageMetadata('vendor/pkg', false, null, true, $at, 'v1.4.0', 1, null, 'library', new \DateTimeImmutable(F::NOW), ['1' => ['version' => 'v1.4.0', 'at' => $at, 'highest' => ['normalized' => '1.4.0.0', 'pretty' => 'v1.4.0', 'at' => null, 'php' => null], 'php' => null]]);
        $finding = (new FindingBuilder())->withVersion('v1.4.0')->build();

        self::assertStringContainsString("\n   * 1.x      v1.4.0        commit 2024-03-01 —\n", self::plain(new Explanation($finding, F::facts(F::package(['version' => 'v1.4.0']), $metadata), new Thresholds(), '8.4', self::report())));
    }

    /** SPEC §7: a row reads branch, highest tag, released, php, so the php is the highest tag's, not the newest dated release's. */
    public function testABranchRowPrintsThePhpOfItsHighestTag(): void
    {
        $at = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $branch = ['version' => '2.2.30', 'at' => new \DateTimeImmutable('2026-02-01T00:00:00+00:00'), 'highest' => ['normalized' => '2.10.3.0', 'pretty' => '2.10.3', 'at' => $at, 'php' => '^7.2.5 || ^8.0'], 'php' => '^5.3.2 || ^7.0 || ^8.0'];
        $metadata = new PackageMetadata('vendor/pkg', false, null, true, $at, '2.10.3', 2, null, 'library', new \DateTimeImmutable(F::NOW), ['2' => $branch]);
        $finding = (new FindingBuilder())->withVersion('2.2.1')->build();

        self::assertStringContainsString("\n   * 2.x      2.10.3        2026-01-01   ^7.2.5 || ^8.0\n", self::plain(new Explanation($finding, F::facts(F::package(['version' => '2.2.1']), $metadata), new Thresholds(), '8.4', self::report())));
    }

    /** Case B's monolog-bridge v2.6.13 holds one counted advisory. */
    /** @param list<IgnoredAdvisory> $ignored */
    private static function feedsOf(AdvisoryNameCoverage $lookup, array $ignored = []): string
    {
        $explanation = self::caseExplanation('B');
        $finding = $explanation->finding();
        $finding = $finding->withDetails(FindingBuilder::detailsOf($finding, true, null, $lookup, null, $ignored));
        $text = self::plain(new Explanation($finding, $explanation->facts(), new Thresholds(), '8.4', $explanation->report()));

        return preg_match('/^  feeds\s+(.*)$/m', $text, $m) === 1 ? $m[1] : '';
    }

    public function testTheFeedWithTheMostRecordsCountsTheAffectingAdvisoriesWhenItHoldsEveryRecord(): void
    {
        $feeds = [AdvisoryFeed::answered('a', 3), AdvisoryFeed::answered('b', 5), AdvisoryFeed::notAsked('c', 'offline'), AdvisoryFeed::failed('d', 'HTTP 500')];

        self::assertSame('a: 3 on record · b: 5 on record, 1 affects v2.6.13 · c: not asked (offline) · d: lookup failed', self::feedsOf(new AdvisoryNameCoverage($feeds, 5, null)));
        self::assertSame('a: 3 on record · b: 5 on record · c: not asked (offline) · d: lookup failed', self::feedsOf(new AdvisoryNameCoverage($feeds, 7, null)), 'two feeds answered and neither holds every record');
    }

    public function testOfTwoFeedsWithEqualRecordsTheFirstCountsTheAffectingAdvisories(): void
    {
        self::assertSame('a: 5 on record, 1 affects v2.6.13 · b: 5 on record', self::feedsOf(new AdvisoryNameCoverage([AdvisoryFeed::answered('a', 5), AdvisoryFeed::answered('b', 5)], 5, null)));
    }

    public function testTheOnlyFeedThatAnsweredCountsTheAffectingAdvisoriesWhateverTheTotal(): void
    {
        self::assertSame('a: 5 on record, 1 affects v2.6.13 · b: lookup failed', self::feedsOf(new AdvisoryNameCoverage([AdvisoryFeed::answered('a', 5), AdvisoryFeed::failed('b', null)], 7, null)));
        self::assertSame('a: none on record · b: lookup failed', self::feedsOf(new AdvisoryNameCoverage([AdvisoryFeed::answered('a', 0), AdvisoryFeed::failed('b', null)], 0, null)));
    }

    public function testTheAffectingCountAddsTheAdvisoriesThatTheAuditIgnores(): void
    {
        $ignored = static fn (string $id): IgnoredAdvisory => new IgnoredAdvisory(new Advisory($id, null, null, null, 'low', null), new AdvisoryIgnoreMatch(AdvisoryIgnoreMatch::ID, $id, null, AdvisoryIgnoreMatch::BY_AUDIT));
        $lookup = new AdvisoryNameCoverage([AdvisoryFeed::answered('a', 3)], 3, null);
        $finding = (new FindingBuilder())->withVersion('1.0.0')->build();
        $finding = $finding->withDetails(FindingBuilder::detailsOf($finding, true, null, $lookup, null, [$ignored('PKSA-i')]));

        self::assertSame('a: 3 on record, 3 affect v2.6.13', self::feedsOf($lookup, [$ignored('PKSA-i'), $ignored('PKSA-j')]), 'one counted and two ignored');
        self::assertStringContainsString("\n  feeds        a: 3 on record, 1 affects 1.0.0\n", self::plain(self::explain($finding)), 'no S9 and one ignored');
    }

    public function testABranchRowWordsItsFixKindAndNamesEachHolderOnce(): void
    {
        self::assertStringContainsString(' 3 of 3 · composer update (lowest fix); after it: 0, ok, if Composer resolves it', self::plain(self::caseExplanation('E')));
        self::assertStringContainsString(' 15 of 15 · php floor raise (lowest fix); held by composer.json and symfony/twig-bridge; after it: 0, ok', self::plain(self::caseExplanation('A')));

        $case = CaseHydrator::case('A');
        $inputs = JsonPath::arrayAt($case, ['inputs']);
        $inputs['root'] = JsonPath::arrayAt($inputs, ['root']) + ['conflict' => ['twig/twig' => '>=3.0']];
        $case['inputs'] = $inputs;
        $analysis = CaseHydrator::analysisOf($case);
        $finding = $analysis->finding('twig/twig');
        $facts = $analysis->facts('twig/twig');
        self::assertNotNull($finding);
        self::assertNotNull($facts);
        self::assertStringContainsString('; held by composer.json and symfony/twig-bridge; after it', self::plain(new Explanation($finding, $facts, new Thresholds(), '8.4', $analysis->report())), 'the require and the conflict of composer.json name it once');
    }

    /**
     * symfony/mailer v6.4.40 holds the move to itself: its own requirement conflicts with the lock. The
     * root requirement on acme/ml-widget drops beside acme/ml-widget's own hold.
     */
    public function testABranchRowNamesAHoldOfThePackagesOwnReleaseAsItsOwnRelease(): void
    {
        self::assertStringContainsString('; held by symfony/mailer v6.4.40, its own release and acme/ml-widget; after it', self::plain(self::caseExplanation('mc-package-hold‡')));
    }

    /** SPEC §5.6, ruling R1: the joint request with acme/gz-client resolves the move to the 8.x row, so nothing holds it. */
    public function testABranchRowThatTheJointRequestResolvesNamesNoHolder(): void
    {
        self::assertStringContainsString(" 1 of 1 · upgrade (lowest fix); after it: 0, ok; Composer's solver resolves it\n", self::plain(self::caseExplanation('mc-joint‡')));
    }

    /** Each cell of a branch row starts under its header, counted in characters: a `—` or a long branch name shifts nothing. */
    public function testEveryBranchRowLinesUpWithItsHeader(): void
    {
        $misplaced = [];
        $rows = 0;
        foreach (array_keys(CaseHydrator::findings()) as $id) {
            $lines = explode("\n", self::plain(self::caseExplanation((string) $id)));
            foreach ($lines as $i => $header) {
                if (preg_match('/^ {5}(branch +)(highest tag +)(released +)(php +)fixes$/', $header, $m) !== 1) {
                    continue;
                }
                $starts = [5 + \strlen($m[1]), 5 + \strlen($m[1].$m[2]), 5 + \strlen($m[1].$m[2].$m[3]), 5 + \strlen($m[1].$m[2].$m[3].$m[4])];
                for ($j = $i + 1; isset($lines[$j]) && preg_match('/^   [ *] (?!… and )\S/u', $lines[$j]) === 1; ++$j) {
                    ++$rows;
                    $characters = preg_split('//u', $lines[$j], -1, \PREG_SPLIT_NO_EMPTY) ?: [];
                    foreach ($starts as $start) {
                        if (isset($characters[$start]) && ($characters[$start - 1] !== ' ' || $characters[$start] === ' ')) {
                            $misplaced[] = $id.': '.$lines[$j];
                            break;
                        }
                    }
                }
            }
        }

        self::assertGreaterThan(0, $rows);
        self::assertSame([], $misplaced);
    }

    public function testEveryCaseExplainsAsItsGolden(): void
    {
        $text = '';
        foreach (array_keys(CaseHydrator::findings()) as $id) {
            $text .= '== case '.$id."\n".self::plain(self::caseExplanation((string) $id))."\n";
        }

        self::assertNotSame('', $text);
        TextGolden::assertMatches('cases.explain.txt', $text, __FUNCTION__);
    }

    private static function caseExplanation(string $id): Explanation
    {
        $case = CaseHydrator::case($id);
        $package = JsonPath::stringAt($case, ['finding', 'package']);
        $analysis = CaseHydrator::analysisOf($case);
        $finding = $analysis->finding($package);
        $facts = $analysis->facts($package);
        self::assertNotNull($finding);
        self::assertNotNull($facts);

        return new Explanation($finding, $facts, new Thresholds(), '8.4', $analysis->report());
    }

    private static function withAge(Finding $finding, ActivityLookup $lookup, AgeReading $push, ?AgeReading $installed = null, ?AgeReading $branch = null, ?AgeReading $release = null): Finding
    {
        $details = $finding->details();
        $age = new Age($installed ?? AgeReading::unmeasuredBecause(AgeMeasure::UNDATED), $release ?? AgeReading::unmeasuredBecause(AgeMeasure::UNDATED_RELEASES), $branch ?? AgeReading::unmeasuredBecause(AgeMeasure::NO_BRANCH_ROW), $push);

        return $finding->withDetails(new FindingDetails($details->metadataStatus(), null, null, $details->skipped(), $details->installedPhp(), $details->entry(), 'complete', null, [], null, $lookup, $age, null, null));
    }
}
