<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Config\LockrotConfig;
use Lockrot\Data\Abandoned\AbandonedIgnoreRule;
use Lockrot\Data\Advisory\AdvisoryCoverage;
use Lockrot\Data\Advisory\AdvisoryRepository;
use Lockrot\Exception\ConfigException;
use Lockrot\Output\FormatContext;
use Lockrot\Output\Formatters;
use Lockrot\Output\GithubFormatter;
use Lockrot\Signal\AbandonedIgnored;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\BaselineEntries;
use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\Notes;
use Lockrot\Tests\Support\SignalDataStub;
use Lockrot\Tests\Support\TextGolden;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use Lockrot\Version;
use PHPUnit\Framework\TestCase;

final class GithubFormatterTest extends TestCase
{
    private const AT = '2026-09-14T06:00:00+00:00';

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            // scandir(), not glob(): a directory name here can hold a backslash, which glob() reads as an escape.
            foreach (is_dir($dir) ? array_diff((array) scandir($dir), ['.', '..']) : [] as $file) {
                unlink($dir.'/'.$file);
            }
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
        $this->tempDirs = [];
    }

    /**
     * A two-package lock whose line numbers are fixed by its literal: acme/abandoned is on
     * line 4, acme/silent on line 8.
     */
    private function lockPath(string $name = 'composer.lock'): string
    {
        $dir = sys_get_temp_dir().'/lockrot-github-'.bin2hex(random_bytes(8));
        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create temp dir: '.$dir);
        }
        $this->tempDirs[] = $dir;
        file_put_contents($dir.'/'.$name, <<<'JSON'
            {
                "packages": [
                    {
                        "name": "acme/abandoned",
                        "version": "1.0.0"
                    },
                    {
                        "name": "acme/silent",
                        "version": "2.0.8"
                    }
                ],
                "packages-dev": []
            }
            JSON);

        return $dir.'/'.$name;
    }

    private function report(): Report
    {
        $at = new \DateTimeImmutable(self::AT);

        return new Report([
            (new FindingBuilder())->withPackage('acme/abandoned')->withVerdict(Verdict::ABANDONED)->withSignals([new Signal('high', 'marked abandoned by its repository', SignalDataStub::of('S1', []))])->withChain(['acme/abandoned'])->withDataDate($at)->build(),
            (new FindingBuilder())->withPackage('acme/silent')->withVersion('2.0.8')->withVerdict(Verdict::SILENT)->withSignals([new Signal('high', 'last release 2015-11-16 (10.8 years ago)', SignalDataStub::of('S2', [])), new Signal('high', 'no push for 9 years', SignalDataStub::of('S4', []))])->withChain(['a/parent', 'acme/silent'])->withDataDate($at)->build(),
            (new FindingBuilder())->withPackage('acme/absent')->withVersion('3.0.0')->withVerdict(Verdict::STALE)->withSignals([new Signal('warn', 'last release 2022-05-20 (4.3 years ago)', SignalDataStub::of('S2', []))])->withChain(['acme/absent'])->withDataDate($at)->build(),
            (new FindingBuilder())->withPackage('acme/fine')->withVersion('4.0.0')->withChain(['acme/fine'])->withDataDate($at)->build(),
        ], Notes::texts(['GitHub token not set: repository activity checked only for 2 candidate packages']), $at, 4, 0);
    }

    public function testTheViaClauseNamesAtMostThreeDirectDependents(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([(new FindingBuilder())->withPackage('a/leaf')->withVerdict(Verdict::STALE)->withChain(['a/r1', 'a/leaf'])->withDirectDependents(['a/r1', 'a/r2', 'a/r3', 'a/r4', 'a/r5'])->withDataDate($at)->build()], [], $at, 1, 0);

        $out = $this->formatter('none', null)->format($report);
        self::assertStringContainsString('(via a/r1, a/r2, a/r3 and 2 more)', $out);
        self::assertStringNotContainsString('a/r4', $out);
    }

    private function formatter(string $failOn, ?string $lockPath): GithubFormatter
    {
        return new GithubFormatter(FormatContext::create($lockPath, $failOn));
    }

    /** doctrine/cache, graded `stale`, whose abandoned marking the project's policy ignores. */
    private static function markedReport(): Report
    {
        $finding = (new FindingBuilder())->withPackage('doctrine/cache')->withVerdict(Verdict::STALE)->build();
        $ignored = new AbandonedIgnored('policy.abandoned', [new AbandonedIgnoreRule('doctrine/*', 'kept until 9.0', [])], AbandonedIgnored::MARKED_BY_REPOSITORY, null, null);

        return new Report([$finding->withDetails(FindingBuilder::detailsOf($finding, true, null, null, $ignored))], [], new \DateTimeImmutable('2026-10-01T00:00:00+00:00'), 1, 0);
    }

    public function testTheAnnotationNamesTheIgnoredAbandonedMarking(): void
    {
        $out = (new GithubFormatter(FormatContext::create(null, 'none')))->format(self::markedReport(), true);

        self::assertStringContainsString(' abandoned marking ignored (policy.abandoned: doctrine/*): kept until 9.0.', $out);
    }

    public function testTheCompositeRendersAsItsGolden(): void
    {
        TextGolden::assertMatches('composite.github.txt', $this->formatter('high', null)->format(CaseHydrator::composite()), __METHOD__);
    }

    public function testErrorsComeFirstThenWarningsEachInTheReportsOrder(): void
    {
        $lines = explode("\n", trim($this->formatter('critical', $this->lockPath())->format($this->report())));

        self::assertSame('::error file=composer.lock,line=4,title=lockrot%3A critical — abandoned::acme/abandoned 1.0.0: 32 = abandoned 32. abandoned: marked abandoned by its registry.', $lines[0]);
        self::assertStringStartsWith('::warning file=composer.lock,line=8,title=lockrot%3A high — silent::acme/silent 2.0.8: 16 = silent 32 ÷ 2 transitive. silent: ', $lines[1]);
        self::assertStringEndsWith('::symfony/polyfill-intl-idn v1.37.0: 2 = vulnerable 2 [low advisory]. Do: composer update symfony/polyfill-intl-idn (gets v1.43.0) — clears the advisory. vulnerable: 1 advisory: 1 low — all fixed on 1.x; advisory: CVE-2026-46644 symfony/polyfill-intl-idn accepts xn-- labels whose Punycode payload decodes to ASCII-only: insecure equivalence. maintenance accepted (lockrot\'s list): polyfills for PHP extensions; released with Symfony and complete for the API of the extension they replace. (via guzzlehttp/guzzle)', explode("\n", $this->formatter('high', null)->format(CaseHydrator::composite()))[6]);
        self::assertStringStartsWith('::warning file=composer.lock,title=lockrot%3A medium — stale::acme/absent 3.0.0: 8 = stale 8.', $lines[2]);
    }

    public function testTheRunLinesFollowTheAnnotationsWithTheGateLineLast(): void
    {
        $lines = explode("\n", trim($this->formatter('high', null)->format(CaseHydrator::composite())));

        self::assertSame('gate: fails · --fail-on=high · 5 findings fail it · target php 8.4 (--target-php)', end($lines));
        self::assertSame('update now: composer update symfony/yaml symfony/polyfill-intl-idn', $lines[\count($lines) - 2]);
        self::assertStringStartsWith('security: 4 vulnerable packages · 20 advisories: ', $lines[\count($lines) - 3]);
        self::assertStringStartsWith('depth: ', $lines[\count($lines) - 4]);
        self::assertStringStartsWith('flags: ', $lines[\count($lines) - 5]);
    }

    public function testTheTitleNamesTheDecidingSeverityAfterVulnerable(): void
    {
        $out = $this->formatter('high', null)->format(CaseHydrator::composite());

        self::assertStringContainsString('title=lockrot%3A critical — left-behind · old-promise · vulnerable (high advisory)::symfony/monolog-bridge v2.6.13: ', $out);
        self::assertStringContainsString('::warning file=composer.lock,title=lockrot%3A low — vulnerable (low advisory)::symfony/yaml v5.4.23: 2 = vulnerable 2 [low advisory]. Do: composer update symfony/yaml (gets v5.4.53) — clears 3 advisories. vulnerable: ', $out);
    }

    public function testScoreZeroFindingsGetAnAnnotationOnlyUnderAll(): void
    {
        self::assertStringNotContainsString('acme/fine', $this->formatter('high', null)->format($this->report()));
        self::assertStringContainsString('::notice file=composer.lock,title=lockrot%3A ok::acme/fine 4.0.0: 0.', $this->formatter('high', null)->format($this->report(), true));
    }

    public function testFailOnNoneNeverProducesAnError(): void
    {
        self::assertStringNotContainsString('::error', $this->formatter(LockrotConfig::FAIL_ON_NONE, null)->format($this->report(), true));
    }

    public function testEscaping(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([
            (new FindingBuilder())->withPackage("acme/x%y\nz")->withVerdict(Verdict::ABANDONED)->withDataDate($at)->build(),
        ], Notes::texts(["note with 100% and a comma, and a colon: here\nand a second line"]), $at, 1, 0);

        $lines = explode("\n", trim($this->formatter(LockrotConfig::FAIL_ON_NONE, null)->format($report)));

        self::assertStringStartsWith('::warning file=composer.lock,title=lockrot%3A critical — abandoned::acme/x%25y%0Az 1.0.0: 32 = abandoned 32.', $lines[0]);
        self::assertSame('::notice title=lockrot::note with 100%25 and a comma, and a colon: here%0Aand a second line', $lines[1]);
    }

    public function testAnAllowlistEntryThatAcceptsEveryFlagIsCarriedInTheMessage(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([
            (new FindingBuilder())->withPackage('acme/kept')->withSignals([new Signal(Signal::LEVEL_WARN, 'x', SignalDataStub::of(Signal::S2, []))])->withAllowlistReason('interfaces')->withDataDate($at)->build(),
        ], [], $at, 1, 0);

        self::assertStringContainsString('::acme/kept 1.0.0: 0 (stale accepted). maintenance accepted (your ignore list): interfaces.', $this->formatter('high', null)->format($report, true));
    }

    private function inProject(string $failOn, string $lockPath, string $projectDirectory): GithubFormatter
    {
        return new GithubFormatter(FormatContext::create($lockPath, $failOn, Version::STRING, FormatContext::DEFAULT_WIDTH, $projectDirectory));
    }

    /**
     * Under `COMPOSER=alt.json` the analysed lock is alt.lock, not composer.lock. `file=` is the
     * lock's path relative to the project directory, the subdirectory `COMPOSER=app/alt.json` puts
     * it in included.
     */
    public function testTheAnnotationNamesTheAnalysedLockRelativeToTheProjectDirectory(): void
    {
        $lockPath = $this->lockPath('alt.lock');
        $name = basename(\dirname($lockPath)).'/alt.lock';

        $lines = explode("\n", trim($this->inProject('critical', $lockPath, \dirname($lockPath, 2))->format($this->report())));

        self::assertStringStartsWith('::error file='.$name.',line=4,title=lockrot%3A critical — abandoned::', $lines[0]);
    }

    /** A lock name is a property value: a `,` or `:` in it ends the property or the command. */
    public function testTheLockNameIsEscapedAsAProperty(): void
    {
        $lockPath = $this->lockPath('a,b%c.lock');

        self::assertStringStartsWith('::error file=a%2Cb%25c.lock,line=4,title=', $this->inProject('critical', $lockPath, \dirname($lockPath))->format($this->report()));
    }

    /** A finding the baseline knows is a notice, after the warnings, so the colour matches the exit code. */
    public function testABaselinedFindingIsAnnotatedAsANotice(): void
    {
        $report = $this->report();
        $names = array_map(static fn (Finding $finding): string => $finding->package(), $report->findings());
        $baseline = BaselineEntries::baseline([BaselineEntries::of('acme/abandoned', '1.0.0', Verdict::ABANDONED, '2026-01-15'), BaselineEntries::of('acme/departed', '1.0.0', Verdict::SILENT, '2026-01-15')], self::AT);
        $out = $this->formatter('critical', null)->format($report->withBaseline(BaselineComparison::compare($baseline, $report, 'lockrot-baseline.json', $names)));
        $lines = explode("\n", trim($out));

        self::assertStringStartsWith('::notice file=composer.lock,title=lockrot%3A critical — abandoned::acme/abandoned 1.0.0: 32 = abandoned 32 (baseline: known).', $lines[2]);
        self::assertStringContainsString('::notice title=lockrot::baseline lists 1 package no longer in composer.lock: acme/departed', $out);
    }

    public function testFactory(): void
    {
        self::assertInstanceOf(GithubFormatter::class, Formatters::for('github', FormatContext::create(null, LockrotConfig::FAIL_ON_NONE)));
    }

    public function testFactoryStillRejectsAnUnknownFormat(): void
    {
        $this->expectException(ConfigException::class);
        Formatters::for('xml', FormatContext::create(null, LockrotConfig::FAIL_ON_NONE));
    }

    /** A plain log line folds every line break: a `::` after one would run as a workflow command. */
    public function testALineBreakInAPackageNameCannotStartAWorkflowCommandFromARunLine(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([
            (new FindingBuilder())->withPackage('a/deep')->withVerdict(Verdict::ABANDONED)->withChain(["evil/root\r\n::error::injected", 'a/deep'])->withDirectDependents(["evil/root\r\n::error::injected"])->withDataDate($at)->build(),
        ], [], $at, 1, 0);

        foreach (explode("\n", trim($this->formatter(LockrotConfig::FAIL_ON_NONE, null)->format($report))) as $line) {
            self::assertStringStartsNotWith('::error::', $line);
        }
    }

    /** SPEC §6.2: one run-level annotation, `error` only when `unchecked` fails on the advisories gap. */
    public function testTheRunLevelAdvisoriesAnnotationIsAnErrorOnlyWhenUncheckedFailsOnTheGap(): void
    {
        $line = '::%s title=lockrot::advisories not checked: the gate could not read the advisories of every package';

        self::assertStringContainsString(\sprintf($line, 'error'), $this->formatter('unchecked', null)->format(self::uncheckedReport()));
        self::assertStringContainsString(\sprintf($line, 'warning'), $this->formatter('high', null)->format(self::uncheckedReport()));
        self::assertStringContainsString(\sprintf($line, 'warning'), $this->formatter('unchecked', null)->format(self::skippedReport()));
        self::assertStringNotContainsString('advisories not checked:', $this->formatter('unchecked', null)->format($this->report()));
    }

    /** SPEC §7.3: a finding with S10 and no counted fact gets its own annotation while `unchecked` is active. */
    public function testAFindingWithS10AndNoCountedFactIsAnnotatedWhileUncheckedIsActive(): void
    {
        $out = $this->formatter('unchecked', null)->format(self::uncheckedReport());

        self::assertStringStartsWith('::error file=composer.lock,title=lockrot%3A ok — unchecked::acme/offline 1.0.0: security advisories not checked. checks missing: S9 (offline).', $out);
        self::assertStringNotContainsString('::acme/offline', $this->formatter('high', null)->format(self::uncheckedReport()));
    }

    public function testThePlainLinesFoldEveryKindOfLineBreakInAPackageName(): void
    {
        $name = "x/a\rb\nc\r\nd";
        $finding = (new FindingBuilder())->withPackage($name)->withVerdict(Verdict::STALE)->withChain(['root/r', $name])->build();
        $out = $this->formatter('high', null)->format(new Report([$finding], [], new \DateTimeImmutable(self::AT), 1, 0));

        self::assertStringContainsString('deepest: x/a b c d, 1 hop (root/r › x/a b c d)', $out);
    }

    public function testTheRunLevelRecordNamesEachRepositoryThatFailedOrWasNotAsked(): void
    {
        $coverage = new AdvisoryCoverage(AdvisoryCoverage::SCOPE_ALL, AdvisoryCoverage::SOURCE_DEFAULT, [
            AdvisoryRepository::of('composer repo (https://repo.packagist.org)', AdvisoryRepository::FAILED, AdvisoryCoverage::TRANSPORT, 'timeout'),
            AdvisoryRepository::of('composer repo (https://repo.example.test)', AdvisoryRepository::NOT_ASKED, AdvisoryCoverage::OFFLINE),
            AdvisoryRepository::of('composer repo (https://other.example.test)', AdvisoryRepository::NO_FEED),
        ], 0, []);
        $report = new Report([FindingBuilder::advisoriesUnchecked('acme/offline', 'offline', true)], [], new \DateTimeImmutable(self::AT), 1, 0, null, null, false, $coverage);

        self::assertStringContainsString("\n::error title=lockrot::advisories not checked: packagist.org lookup failed (network error: timeout); composer repo (https://repo.example.test) not asked (offline)\n", $this->formatter('unchecked', null)->format($report));
    }

    /** An offline finding with S10 for its advisories, and a package outside every Composer repository, which is skipped. */
    private static function uncheckedReport(): Report
    {
        $at = new \DateTimeImmutable(self::AT);

        return new Report([
            FindingBuilder::advisoriesUnchecked('acme/offline', 'offline', true),
            FindingBuilder::advisoriesUnchecked('acme/vcs', 'not_from_composer_repository', false),
        ], [], $at, 2, 0);
    }

    private static function skippedReport(): Report
    {
        return new Report([FindingBuilder::advisoriesUnchecked('acme/vcs', 'not_from_composer_repository', false)], [], new \DateTimeImmutable(self::AT), 1, 0);
    }
}
