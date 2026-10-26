<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Analyzer\MoveCheckRun;
use Lockrot\Analyzer\PhpWindow;
use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\UpdateNow;
use Lockrot\Clock;
use Lockrot\Data\Advisory\AdvisoryCoverage;
use Lockrot\Data\Advisory\AdvisoryRepository;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Output\ConsoleMarkup;
use Lockrot\Output\FormatContext;
use Lockrot\Output\Formatters;
use Lockrot\Output\RunFooter;
use Lockrot\Solver\MoveCheck;
use Lockrot\Solver\MoveChecker;
use Lockrot\Solver\UncheckedMoves;
use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\RecordedMoveChecker;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\FindingDetails;
use Lockrot\Verdict\MoveChecks;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The run footer lines that the composite does not reach. */
final class RunFooterTest extends TestCase
{
    private const AT = '2026-10-08T00:00:00+00:00';
    private const PACKAGIST = 'composer repo (https://repo.packagist.org)';

    /** @param list<Finding> $findings */
    private static function report(array $findings, ?AdvisoryCoverage $coverage = null): Report
    {
        return new Report($findings, [], new \DateTimeImmutable(self::AT), \count($findings), 0, null, null, false, $coverage);
    }

    /** @param list<string> $packages */
    private static function composite(array $packages): Report
    {
        return self::report(array_values(array_filter(CaseHydrator::composite()->findings(), static fn (Finding $finding): bool => \in_array($finding->package(), $packages, true))));
    }

    public static function asWritten(string $package): string
    {
        return $package;
    }

    /** @param list<string> $chain */
    private static function stale(string $package, array $chain): Finding
    {
        return (new FindingBuilder())->withPackage($package)->withVerdict(Verdict::STALE)->withChain($chain)->build();
    }

    /** A finding that no advisory feed was asked about. */
    private static function notAsked(string $package): Finding
    {
        $finding = self::stale($package, [$package]);
        $details = $finding->details();

        return $finding->withDetails(new FindingDetails($details->metadataStatus(), null, null, $details->skipped(), $details->installedPhp(), null, 'complete', null, [], null, $details->activity(), $details->age(), null, null));
    }

    public function testTheFlagsLineCountsThePackagesWithMoreThanOneFlagOnlyWhenOneHasThem(): void
    {
        self::assertSame('flags: vulnerable 1', RunFooter::flags(self::composite(['twig/twig'])));
        self::assertSame('flags: abandoned 1 · old-promise 1 · vulnerable 1 · 1 package carries more than one', RunFooter::flags(self::composite(['twig/twig', 'symfony/debug'])));
    }

    public function testTheDepthLineOfALockOnlyRunSaysWhyNothingIsMeasured(): void
    {
        $one = (new FindingBuilder())->withPackage('a/one')->withVerdict(Verdict::STALE)->withChain([])->withComposerJsonRead(false)->build();
        $two = (new FindingBuilder())->withPackage('a/two')->withVerdict(Verdict::STALE)->withChain([])->withComposerJsonRead(false)->build();

        self::assertSame('depth: composer.json not read, so depth is not measured for 1 flagged package', RunFooter::depth(self::report([$one]), [self::class, 'asWritten']));
        self::assertSame('depth: composer.json not read, so depth is not measured for 2 flagged packages', RunFooter::depth(self::report([$one, $two]), [self::class, 'asWritten']));
    }

    public function testTheDepthLineSaysEveryPackageIsNamedAndSkipsADepthWithNoPackage(): void
    {
        self::assertSame('depth: every flagged package is named in composer.json · direct 1', RunFooter::depth(self::composite(['twig/twig']), [self::class, 'asWritten']));
        self::assertSame('depth: 1 of 1 flagged package is named by no line of composer.json · 1 hop 1 · deepest: symfony/polyfill-intl-idn, 1 hop (guzzlehttp/guzzle › symfony/polyfill-intl-idn)', RunFooter::depth(self::composite(['symfony/polyfill-intl-idn']), [self::class, 'asWritten']));
    }

    public function testTheDeepestPartNamesTwoOrThreePackagesWithAnd(): void
    {
        $two = self::report([self::stale('x/a', ['root/r', 'x/a']), self::stale('x/b', ['root/r', 'x/b'])]);
        $three = self::report([self::stale('x/a', ['root/r', 'x/a']), self::stale('x/b', ['root/r', 'x/b']), self::stale('x/c', ['root/r', 'x/c'])]);

        self::assertStringContainsString(' · deepest: x/a and x/b, 1 hop (root/r › x/a)', (string) RunFooter::depth($two, [self::class, 'asWritten']));
        self::assertStringContainsString(' · deepest: x/a, x/b and x/c, 1 hop (root/r › x/a)', (string) RunFooter::depth($three, [self::class, 'asWritten']));
    }

    public function testTheSecurityLineOfOneVulnerablePackageListsOnlyItsSeverities(): void
    {
        self::assertSame('security: 1 vulnerable package · 1 advisory: 1 high', RunFooter::security(self::composite(['symfony/monolog-bridge']), false));
    }

    public function testTheSecurityLineWithNoVulnerablePackageStillNamesAnUncheckedLookup(): void
    {
        $report = self::report([FindingBuilder::advisoriesUnchecked('a/offline', 'offline', true)]);

        self::assertSame('security: no vulnerable package · advisories not checked', RunFooter::security($report, false));
    }

    public function testTheAdvisoriesLineSaysWhyPackagesWereNotAskedAndWhatEachRepositoryAnswered(): void
    {
        $findings = [self::notAsked('a/one'), self::notAsked('a/two')];
        $limited = new AdvisoryCoverage(AdvisoryCoverage::SCOPE_COMPOSER_REPOSITORIES, AdvisoryCoverage::SOURCE_CONFIG, [], 0, []);
        $offline = new AdvisoryCoverage(AdvisoryCoverage::SCOPE_ALL, AdvisoryCoverage::SOURCE_DEFAULT, [AdvisoryRepository::of(self::PACKAGIST, AdvisoryRepository::NO_FEED), AdvisoryRepository::of(self::PACKAGIST, AdvisoryRepository::FAILED, AdvisoryCoverage::TRANSPORT, 'timeout')], 0, []);
        $silent = new AdvisoryCoverage(AdvisoryCoverage::SCOPE_ALL, AdvisoryCoverage::SOURCE_DEFAULT, [AdvisoryRepository::of(self::PACKAGIST, AdvisoryRepository::NO_FEED)], 0, []);

        self::assertSame('advisories: 0 packages asked, 2 not asked (packages from a Composer repository only (advisory-lookup))', RunFooter::advisories(self::report($findings, $limited)));
        self::assertSame('advisories: 0 packages asked, 2 not asked (network error) · packagist.org publishes no advisories · packagist.org lookup failed (network error: timeout)', RunFooter::advisories(self::report($findings, $offline)));
        self::assertSame('advisories: 0 packages asked, 2 not asked · packagist.org publishes no advisories', RunFooter::advisories(self::report($findings, $silent)));
        $twoFailures = new AdvisoryCoverage(AdvisoryCoverage::SCOPE_ALL, AdvisoryCoverage::SOURCE_DEFAULT, [AdvisoryRepository::of(self::PACKAGIST, AdvisoryRepository::FAILED, AdvisoryCoverage::TRANSPORT, 'timeout'), AdvisoryRepository::of(self::PACKAGIST, AdvisoryRepository::FAILED, AdvisoryCoverage::INVALID_RESPONSE, 'bad json')], 0, []);
        self::assertStringStartsWith('advisories: 0 packages asked, 2 not asked (network error) · ', RunFooter::advisories(self::report($findings, $twoFailures)), 'the first repository with a reason gives it');
    }

    public function testTheDepthLineCountsTheUnreachedTheDevAndTheRequireDevOnlyPackages(): void
    {
        $unreached = (new FindingBuilder())->withPackage('a/unreached')->withVerdict(Verdict::STALE)->withChain([])->build();
        $dev = (new FindingBuilder())->withPackage('a/dev')->withVerdict(Verdict::STALE)->withDev(true)->build();
        $devOnly = (new FindingBuilder())->withPackage('a/dev-only')->withVerdict(Verdict::STALE)->withChain(['root/r', 'a/dev-only'])->withNamedIn([ProjectConfig::REQUIRE_DEV])->build();
        $report = new Report([$unreached, $dev, $devOnly], [], new \DateTimeImmutable(self::AT), 3, 0, null, null, true);

        self::assertSame('depth: 1 of 3 flagged packages is named by no line of composer.json · direct 1 · 1 hop 1 · unreached 1 · 1 in packages-dev · deepest: a/dev-only, 1 hop (root/r › a/dev-only) · 1 more is named only in require-dev, which this run did not walk (pass --dev)', RunFooter::depth($report, [self::class, 'asWritten']));
    }

    public function testTheAdvisoriesLineCountsTheRepositoriesThatPublishNoAdvisories(): void
    {
        $coverage = new AdvisoryCoverage(AdvisoryCoverage::SCOPE_ALL, AdvisoryCoverage::SOURCE_DEFAULT, [AdvisoryRepository::of(self::PACKAGIST, AdvisoryRepository::ANSWERED)->withRecords(3, 2)], 2, []);

        self::assertSame('advisories: 0 packages asked, 1 not asked · packagist.org answered (3 on record for 2 packages) · 2 other repositories publish no advisories', RunFooter::advisories(self::report([self::notAsked('a/one')], $coverage)));
    }

    public function testTheMovesLineOfARunWithNoCheckSaysWhyInWords(): void
    {
        $checks = new MoveChecks(new UncheckedMoves(MoveCheck::NO_COMPOSER_JSON), true);
        $report = self::report([])->withMoveChecks(new MoveCheckRun(null, false, [], MoveCheck::notChecked(MoveCheck::NO_COMPOSER_JSON)), UpdateNow::of([], $checks));

        self::assertSame('moves: not checked (the project has no composer.json)', RunFooter::moveCheck($report));
    }

    public function testThePhpLineNamesTheMinorsOnceWhenBothSetsAgree(): void
    {
        $line = self::phpLine(['packages' => [self::package('acme/a', ['php' => '>=8.2'])], 'packages-dev' => []], [], '8.4');

        self::assertSame('php: the lock installs on PHP 8.2 to 8.5', $line);
    }

    public function testThePhpLineNamesBothSetsWhenRequireDevMovesTheWindowAndSaysTheTargetIsExcluded(): void
    {
        $line = self::phpLine(['packages' => [self::package('acme/a', ['php' => '7.1.* || >=8.1'])], 'packages-dev' => []], ['require' => ['php' => '>=8.2'], 'require-dev' => ['php' => '>=7.1']], '7.4');

        self::assertSame('php: without require-dev the lock installs on PHP 8.2 to 8.5, and with require-dev on PHP 7.1 and 8.1 to 8.5, so your target, 7.4, is excluded; the update of the package that excludes it is not checked (the run checked no move)', $line);
    }

    public function testThePhpLineNamesTheOverrideAndAtMostThreeSettersOfTheUpperEdge(): void
    {
        $packages = array_map(static fn (string $name): array => self::package($name, ['php' => '<8.4']), ['acme/a', 'acme/b', 'acme/c', 'acme/d']);
        $line = self::phpLine(['packages' => $packages, 'packages-dev' => [], 'platform-overrides' => ['php' => '8.2.0']], ['require' => ['php' => '<8.4']], '8.3');

        self::assertSame('php: the lock installs on PHP 5.3 to 8.3; Composer checks it at 8.2.0 from config.platform.php; require.php, acme/a, acme/b and 2 more exclude 8.4', $line);
    }

    public function testThePhpLineOfAnEmptyWindowSaysOnNoPhp(): void
    {
        $line = self::phpLine(['packages' => [self::package('acme/a', ['php' => '<8.0']), self::package('acme/b', ['php' => '>=8.1'])]], [], '8.4');

        self::assertSame('php: the lock installs on no PHP, so your target, 8.4, is excluded; the update of the package that excludes it is not checked (the run checked no move)', $line);
    }

    public function testThePhpLineReadsTheUpperEdgeOfTheSetThatTheRunAnalysed(): void
    {
        $lock = ['packages' => [], 'packages-dev' => [self::package('acme/tool', ['php' => '<8.3'])]];
        $window = 'php: without require-dev the lock installs on PHP 5.3 to 8.5, and with require-dev on PHP 5.3 to 8.2';

        self::assertSame($window, self::phpLine($lock, [], '8.2'));
        self::assertSame($window.'; acme/tool excludes 8.3', self::phpLine($lock, [], '8.2', true));
    }

    public function testThePhpLineNamesEachRootLinkByItsSection(): void
    {
        $line = self::phpLine(['packages' => [], 'packages-dev' => []], ['require' => ['php-64bit' => '<8.4'], 'require-dev' => ['php' => '<8.4'], 'conflict' => ['php' => '>=8.4']], '8.3', true);

        self::assertSame('php: the lock installs on PHP 5.3 to 8.3; conflict.php, require-dev.php and require.php-64bit exclude 8.4', $line);
    }

    /** wallabag 2.6.14 at 8.4 (§7.0): the fix replaces the override and edge clauses. */
    public function testThePhpLineOfARefusedFixNamesTheCountAndEachHold(): void
    {
        $lock = ['packages' => [self::package('acme/a', ['php' => '<8.4']), self::package('acme/b', ['php' => '<8.4'])], 'packages-dev' => [], 'platform-overrides' => ['php' => '8.3.0']];
        $holds = [
            ['source' => 'root', 'link' => 'root-require', 'target' => 'acme/a', 'constraint' => '^1.0'],
            ['source' => 'package', 'package' => 'acme/c', 'version' => '2.0.0', 'link' => 'require', 'target' => 'acme/b', 'constraint' => '1.0.0'],
        ];

        $line = self::phpLine($lock, [], '8.4', false, new RecordedMoveChecker(['target_fix' => ['result' => 'refused', 'holds' => $holds]], []));

        self::assertSame("php: the lock installs on PHP 5.3 to 8.3, so your target, 8.4, is excluded; Composer's solver at PHP 8.4 refuses the update of the 2 packages that exclude it: composer.json requires acme/a ^1.0 and acme/c 2.0.0 requires acme/b 1.0.0", $line);
    }

    /** wallabag 2.6.14 at 8.4 lists friendsoftwig/twigcs from packages-dev among the packages that exclude the target. */
    public function testARootHoldOnAPackagesDevEntryOfTheFixSaysRequireDev(): void
    {
        $lock = ['packages' => [self::package('acme/a', ['php' => '<8.4'])], 'packages-dev' => [self::package('acme/tool', ['php' => '<8.4'])]];
        $holds = [
            ['source' => 'root', 'link' => 'root-require', 'target' => 'acme/a', 'constraint' => '^1.0'],
            ['source' => 'root', 'link' => 'root-require', 'target' => 'acme/tool', 'constraint' => '^2.0'],
        ];

        $line = self::phpLine($lock, [], '8.4', false, new RecordedMoveChecker(['target_fix' => ['result' => 'refused', 'holds' => $holds]], []));

        self::assertStringEndsWith('refuses the update of the 2 packages that exclude it: composer.json requires acme/a ^1.0 and composer.json requires acme/tool ^2.0 in require-dev', (string) $line);
    }

    /** @return iterable<string, array{string, string}> */
    public static function updateNowFormats(): iterable
    {
        yield 'table' => ['table', 'update now: First set config.platform.php to your PHP 8.4 release: the lock records 7.4.29. composer update symfony/yaml symfony/polyfill-intl-idn'];
        yield 'markdown' => ['markdown', '**Update now:** First set config.platform.php to your PHP 8.4 release: the lock records 7.4.29. `composer update symfony/yaml symfony/polyfill-intl-idn`'];
        yield 'github' => ['github', "\nupdate now: First set config.platform.php to your PHP 8.4 release: the lock records 7.4.29. composer update symfony/yaml symfony/polyfill-intl-idn\n"];
    }

    /**
     * Composer runs the `update now:` command under the same pin as a move's commands.
     *
     * @dataProvider updateNowFormats
     */
    #[DataProvider('updateNowFormats')]
    public function testTheUpdateNowLineFirstSetsAPlatformPhpOutsideTheTargetMinor(string $format, string $line): void
    {
        $window = PhpWindow::of(LockFile::fromArray(['packages' => [], 'packages-dev' => [], 'platform-overrides' => ['php' => '7.4.29']]), ProjectConfig::empty(), '8.4', PhpReleaseDates::load()->minors(new Clock(new \DateTimeImmutable(self::AT))));
        $report = CaseHydrator::composite()->withPhpWindow($window);

        $text = Formatters::for($format, FormatContext::create(null, 'high'))->format($report);

        self::assertStringContainsString($line, $format === 'table' ? (string) preg_replace('/\n +/', ' ', ConsoleMarkup::render($text, false)) : $text);
    }

    public function testThePhpLineOfARefusalWithNoHoldEndsAfterThePackageThatExcludesTheTarget(): void
    {
        $line = self::phpLine(['packages' => [self::package('acme/a', ['php' => '<8.4'])], 'packages-dev' => []], [], '8.4', false, new RecordedMoveChecker(['target_fix' => ['result' => 'refused']], []));

        self::assertSame("php: the lock installs on PHP 5.3 to 8.3, so your target, 8.4, is excluded; Composer's solver at PHP 8.4 refuses the update of the package that excludes it", $line);
    }

    /** monica at 8.5: the command as the move commands render it. */
    public function testThePhpLineOfAResolvingFixPrintsTheCommand(): void
    {
        $lock = ['packages' => [self::package('acme/a', ['php' => '<8.5'])], 'packages-dev' => [self::package('acme/tool', ['php' => '<8.5'])]];

        $line = self::phpLine($lock, [], '8.5', false, new RecordedMoveChecker(['target_fix' => ['result' => 'resolves', 'form' => 'with-all-dependencies']], []));

        self::assertSame("php: the lock installs on PHP 5.3 to 8.4, so your target, 8.5, is excluded; Composer's solver at PHP 8.5 resolves: composer update acme/a acme/tool --with-all-dependencies", $line);
    }

    /** M5: config.platform.php outside the target minor needs its own step first. */
    public function testThePhpLineOfAResolvingFixNamesThePlatformStep(): void
    {
        $lock = ['packages' => [self::package('acme/a', ['php' => '<8.5'])], 'packages-dev' => []];

        $line = self::phpLine($lock, ['config' => ['platform' => ['php' => '8.4.12']]], '8.5', false, new RecordedMoveChecker(['target_fix' => ['result' => 'resolves', 'form' => 'inline']], []));

        self::assertSame('php: the lock installs on PHP 5.3 to 8.4, so your target, 8.5, is excluded; set config.platform.php to your PHP 8.5 release, then Composer\'s solver resolves: composer update acme/a', $line);
    }

    public function testThePlatformStepAddsNoWordsToARefusedFix(): void
    {
        $lock = ['packages' => [self::package('acme/a', ['php' => '<8.5'])], 'packages-dev' => []];

        $line = self::phpLine($lock, ['config' => ['platform' => ['php' => '7.4.29']]], '8.5', false, new RecordedMoveChecker(['target_fix' => ['result' => 'refused']], []));

        self::assertStringEndsWith("; Composer's solver at PHP 8.5 refuses the update of the package that excludes it", (string) $line);
    }

    public function testThePhpLineOfAFixThatWasNotCheckedGivesTheReason(): void
    {
        $lock = ['packages' => [self::package('acme/a', ['php' => '<8.4']), self::package('acme/b', ['php' => '<8.4'])], 'packages-dev' => []];

        $offline = self::phpLine($lock, [], '8.4', false, new RecordedMoveChecker(['target_fix' => ['result' => 'not_checked', 'reason' => 'offline']], []));
        $unchecked = self::phpLine($lock, [], '8.4', false, null);

        self::assertStringEndsWith("; the update of the 2 packages that exclude it is not checked (a package it needs is not in Composer's cache)", (string) $offline);
        self::assertStringEndsWith('; the update of the 2 packages that exclude it is not checked (the run checked no move)', (string) $unchecked);
    }

    /** The laminas skeleton at 8.4: the root ceiling names each root link that excludes the target. */
    public function testThePhpLineOfTheRootCeilingNamesEachRootLink(): void
    {
        $require = self::phpLine(['packages' => [], 'packages-dev' => []], ['require' => ['php' => '~8.1.0 || ~8.2.0 || ~8.3.0']], '8.4', false, null);
        $both = self::phpLine(['packages' => [], 'packages-dev' => []], ['require-dev' => ['php' => '<8.4'], 'conflict' => ['php' => '>=8.4.50']], '8.4', false, null);

        self::assertSame('php: the lock installs on PHP 8.1 to 8.3; your target, 8.4, is excluded: require.php allows only ~8.1.0 || ~8.2.0 || ~8.3.0', $require);
        self::assertSame('php: the lock installs on PHP 5.3 to 8.3; your target, 8.4, is excluded: require-dev.php allows only <8.4 and conflict.php excludes >=8.4.50', $both);
    }

    /** drupal-site at 7.2: below the lowest edge, the line names that edge's setters. */
    public function testThePhpLineOfATargetBelowTheLowestEdgeNamesItsSetters(): void
    {
        $one = self::phpLine(['packages' => [self::package('acme/a', ['php' => '>=7.3'])], 'packages-dev' => []], [], '7.2', false, null);
        $two = self::phpLine(['packages' => [self::package('acme/a', ['php' => '>=7.3']), self::package('acme/b', ['php' => '>=7.3.5'])], 'packages-dev' => []], [], '7.2', false, null);

        self::assertSame('php: the lock installs on PHP 7.3 to 8.5, so your target, 7.2, is excluded; acme/a sets the lowest PHP, 7.3.0', $one);
        self::assertSame('php: the lock installs on PHP 7.3 to 8.5, so your target, 7.2, is excluded; acme/b sets the lowest PHP, 7.3.5', $two);
    }

    public function testThePhpLineCountsAPackageWithTwoPhpLinksOnce(): void
    {
        $line = self::phpLine(['packages' => [self::package('acme/a', ['php' => '<8.4', 'php-64bit' => '<8.4'])], 'packages-dev' => []], [], '8.4', false, null);

        self::assertStringEndsWith('; the update of the package that excludes it is not checked (the run checked no move)', (string) $line);
    }

    /** The lowest edge that excludes the target is the one of the set with require-dev. */
    public function testThePhpLineNamesTheSettersOfTheLowestEdgeWithRequireDev(): void
    {
        $line = self::phpLine(['packages' => [], 'packages-dev' => [self::package('acme/tool', ['php' => '>=7.3'])]], [], '7.2', false, null);

        self::assertSame('php: without require-dev the lock installs on PHP 5.3 to 8.5, and with require-dev on PHP 7.3 to 8.5, so your target, 7.2, is excluded; acme/tool sets the lowest PHP, 7.3.0', $line);
    }

    /** markdown marks each package name and the command as code: the line hands them to the format's marker. */
    public function testThePhpLineHandsThePackageNamesAndTheCommandToTheMarker(): void
    {
        $holds = [['source' => 'package', 'package' => 'acme/c', 'version' => '2.0.0', 'link' => 'require', 'target' => 'acme/a', 'constraint' => '1.0.0']];
        $at = new \DateTimeImmutable(self::AT);
        $lock = ['packages' => [self::package('acme/a', ['php' => '<8.4'])], 'packages-dev' => []];
        $window = PhpWindow::of(LockFile::fromArray($lock), ProjectConfig::fromArray([]), '8.4', PhpReleaseDates::load()->minors(new Clock($at)));
        self::assertNotNull($window);
        $mark = static fn (string $code): string => '['.$code.']';

        $refused = RunFooter::phpWindow((new Report([], [], $at, 0, 0))->withPhpWindow($window->withTargetFix(new RecordedMoveChecker(['target_fix' => ['result' => 'refused', 'holds' => $holds]], []))), $mark);
        $resolves = RunFooter::phpWindow((new Report([], [], $at, 0, 0))->withPhpWindow($window->withTargetFix(new RecordedMoveChecker(['target_fix' => ['result' => 'resolves', 'form' => 'inline']], []))), $mark);

        self::assertStringEndsWith(': [acme/c] 2.0.0 requires [acme/a] 1.0.0', (string) $refused);
        self::assertStringEndsWith(': [composer update acme/a]', (string) $resolves);
    }

    public function testThereIsNoPhpLineWithoutAWindow(): void
    {
        self::assertNull(RunFooter::phpWindow(self::report([]), [self::class, 'asWritten']));
    }

    /**
     * @param array<string, mixed> $lock
     * @param array<string, mixed> $composer
     */
    private static function phpLine(array $lock, array $composer, string $target, bool $includeDev = false, ?MoveChecker $checker = null): ?string
    {
        $at = new \DateTimeImmutable(self::AT);
        $window = PhpWindow::of(LockFile::fromArray($lock), ProjectConfig::fromArray($composer), $target, PhpReleaseDates::load()->minors(new Clock($at)));
        $report = (new Report([], [], $at, 0, 0, null, null, $includeDev))->withPhpWindow($window === null ? null : $window->withTargetFix($checker));

        return RunFooter::phpWindow($report, [self::class, 'asWritten']);
    }

    /**
     * @param array<string, string> $require
     *
     * @return array<string, mixed>
     */
    private static function package(string $name, array $require): array
    {
        return ['name' => $name, 'version' => '1.0.0', 'require' => $require];
    }
}
