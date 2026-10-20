<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunSettings;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Config\LockrotConfig;
use Lockrot\Exception\ConfigException;
use Lockrot\Output\FormatContext;
use Lockrot\Output\Formatters;
use Lockrot\Output\GitlabFormatter;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\BaselineEntries;
use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\Notes;
use Lockrot\Tests\Support\SignalDataStub;
use Lockrot\Tests\Support\TextGolden;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use Lockrot\Version;
use PHPUnit\Framework\TestCase;

final class GitlabFormatterTest extends TestCase
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

    /** acme/abandoned is on line 4 of the lock written here, acme/silent on line 8. */
    private function lockPath(string $name = 'composer.lock'): string
    {
        $dir = sys_get_temp_dir().'/lockrot-gitlab-'.bin2hex(random_bytes(8));
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

    private function formatter(string $failOn, ?string $lockPath): GitlabFormatter
    {
        return new GitlabFormatter(FormatContext::create($lockPath, $failOn));
    }

    /** @return list<array<string, mixed>> */
    private function decode(string $json): array
    {
        self::assertStringEndsWith("\n", $json);
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);

        /** @var list<array<string, mixed>> $decoded */
        return $decoded;
    }


    public function testSeverityMappingAtTheFailOnBoundary(): void
    {
        $lockPath = $this->lockPath();

        $atSilent = $this->decode($this->formatter(Verdict::SILENT, $lockPath)->format($this->report(), true));
        self::assertSame(['major', 'major', 'minor', 'info'], array_column($atSilent, 'severity'));

        $atAbandoned = $this->decode($this->formatter(Verdict::ABANDONED, $lockPath)->format($this->report(), true));
        self::assertSame(['major', 'minor', 'minor', 'info'], array_column($atAbandoned, 'severity'));

        $atNone = $this->decode($this->formatter(LockrotConfig::FAIL_ON_NONE, $lockPath)->format($this->report(), true));
        self::assertSame(['minor', 'minor', 'minor', 'info'], array_column($atNone, 'severity'));
    }

    public function testABaselinedFindingIsDemotedToInfo(): void
    {
        $report = $this->report();
        $names = [];
        foreach ($report->findings() as $finding) {
            $names[] = $finding->package();
        }
        $baseline = BaselineEntries::baseline([
            BaselineEntries::of('acme/abandoned', '1.0.0', Verdict::ABANDONED, '2026-01-15'),
        ], self::AT);
        $withBaseline = $report->withBaseline(BaselineComparison::compare($baseline, $report, 'lockrot-baseline.json', $names));

        $issues = $this->decode($this->formatter(Verdict::SILENT, $this->lockPath())->format($withBaseline));

        self::assertSame('info', $issues[0]['severity'], 'known finding is demoted to info');
        self::assertSame('major', $issues[1]['severity'], 'a finding the baseline does not know stays at its normal severity');
    }

    public function testFingerprintIsStableAcrossRunsAndDifferentPerVerdict(): void
    {
        $lockPath = $this->lockPath();
        $first = $this->decode($this->formatter(Verdict::SILENT, $lockPath)->format($this->report()));
        $second = $this->decode($this->formatter(Verdict::SILENT, $lockPath)->format($this->report()));

        self::assertSame($first[0]['fingerprint'], $second[0]['fingerprint'], 'same package/verdict must hash the same across runs');
        self::assertNotSame($first[0]['fingerprint'], $first[1]['fingerprint'], 'different verdicts must not collide');
    }

    /**
     * The same lock with one extra package above acme/abandoned, so the package GitLab is told about
     * sits on line 8 here rather than line 4.
     */
    private function shiftedLockPath(): string
    {
        $dir = sys_get_temp_dir().'/lockrot-gitlab-'.bin2hex(random_bytes(8));
        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create temp dir: '.$dir);
        }
        $this->tempDirs[] = $dir;
        file_put_contents($dir.'/composer.lock', <<<'JSON'
            {
                "packages": [
                    {
                        "name": "acme/newcomer",
                        "version": "1.0.0"
                    },
                    {
                        "name": "acme/abandoned",
                        "version": "9.9.9"
                    }
                ],
                "packages-dev": []
            }
            JSON);

        return $dir.'/composer.lock';
    }

    /**
     * The identity GitLab tracks an issue by must survive a version bump and a reformatted lock:
     * the fingerprint is the package and the verdict, nothing else.
     */
    public function testFingerprintIgnoresTheVersionAndTheLockLine(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $signal = new Signal('high', 'marked abandoned by its repository', SignalDataStub::of('S1', []));
        $before = new Report([(new FindingBuilder())->withPackage('acme/abandoned')->withVerdict(Verdict::ABANDONED)->withSignals([$signal])->withChain(['acme/abandoned'])->withDataDate($at)->build()], [], $at, 1, 0);
        $after = new Report([(new FindingBuilder())->withPackage('acme/abandoned')->withVersion('9.9.9')->withVerdict(Verdict::ABANDONED)->withSignals([$signal])->withChain(['acme/abandoned'])->withDataDate($at)->build()], [], $at, 1, 0);

        $first = $this->decode($this->formatter(Verdict::SILENT, $this->lockPath())->format($before));
        $second = $this->decode($this->formatter(Verdict::SILENT, $this->shiftedLockPath())->format($after));

        self::assertSame(4, JsonPath::intAt($first[0], ['location', 'lines', 'begin']));
        self::assertSame(8, JsonPath::intAt($second[0], ['location', 'lines', 'begin']));
        self::assertNotSame($first[0]['description'], $second[0]['description'], 'the version really did change');
        self::assertSame($first[0]['fingerprint'], $second[0]['fingerprint']);
    }

    private function inProject(string $failOn, string $lockPath, string $projectDirectory): GitlabFormatter
    {
        return new GitlabFormatter(FormatContext::create($lockPath, $failOn, Version::STRING, FormatContext::DEFAULT_WIDTH, $projectDirectory));
    }

    /**
     * Under `COMPOSER=alt.json` the analysed lock is alt.lock: `location.path` is the lock's path
     * relative to the project directory, so GitLab marks the file the merge request changes. The
     * fingerprint does not move with it — a changed identity makes every finding look new.
     */
    public function testTheLocationNamesTheAnalysedLockAndTheFingerprintStays(): void
    {
        $lockPath = $this->lockPath('alt.lock');

        $issues = $this->decode($this->inProject(Verdict::SILENT, $lockPath, \dirname($lockPath, 2))->format($this->report()));
        $default = $this->decode($this->formatter(Verdict::SILENT, $this->lockPath())->format($this->report()));

        self::assertSame(
            array_fill(0, 3, basename(\dirname($lockPath)).'/alt.lock'),
            array_map(static fn (array $issue): string => JsonPath::stringAt($issue, ['location', 'path']), $issues)
        );
        self::assertSame(4, JsonPath::intAt($issues[0], ['location', 'lines', 'begin']));
        self::assertSame(array_column($default, 'fingerprint'), array_column($issues, 'fingerprint'));
        self::assertSame(hash('sha256', 'lockrot|acme/abandoned|abandoned'), $issues[0]['fingerprint']);
    }

    public function testTheDefaultLockKeepsTheReportByteForByte(): void
    {
        $lockPath = $this->lockPath();

        $out = $this->inProject(Verdict::SILENT, $lockPath, \dirname($lockPath))->format($this->report(), true);

        self::assertSame($this->formatter(Verdict::SILENT, $lockPath)->format($this->report(), true), $out);
        self::assertStringContainsString('"path": "composer.lock"', $out);
    }

    public function testTheBaselineStaleNoteIsNotRepresentableAndIsDropped(): void
    {
        $report = $this->report();
        $baseline = BaselineEntries::baseline([BaselineEntries::of('acme/departed', '1.0.0', Verdict::ABANDONED, '2026-01-15')], self::AT);
        $withBaseline = $report->withBaseline(BaselineComparison::compare($baseline, $report, 'lockrot-baseline.json', ['acme/abandoned']));

        $out = $this->formatter(Verdict::SILENT, $this->lockPath())->format($withBaseline);

        self::assertStringNotContainsString('acme/departed', $out, 'GitLab Code Quality has no field for a document-level note');
    }

    public function testLinesBeginFallsBackToOneWithoutALockPath(): void
    {
        $issues = $this->decode($this->formatter(Verdict::SILENT, null)->format($this->report()));

        foreach ($issues as $issue) {
            self::assertSame(1, JsonPath::intAt($issue, ['location', 'lines', 'begin']));
        }
    }

    public function testEmptyReportIsAnEmptyArray(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $out = $this->formatter(Verdict::SILENT, $this->lockPath())->format(new Report([], [], $at, 0, 0));

        self::assertSame("[]\n", $out);
    }

    public function testShowAllIncludesUnflaggedRows(): void
    {
        $issues = $this->decode($this->formatter(Verdict::SILENT, $this->lockPath())->format($this->report(), true));
        self::assertCount(4, $issues);
        self::assertSame('lockrot/ok', $issues[3]['check_name']);
    }


    public function testUnflaggedRowsAreOmittedWithoutShowAll(): void
    {
        $issues = $this->decode($this->formatter(Verdict::SILENT, $this->lockPath())->format($this->report()));
        self::assertCount(3, $issues);
        self::assertNotContains('lockrot/ok', array_column($issues, 'check_name'));
    }


    /** Report notes are not representable in this format and must never leak into an issue's fields. */
    public function testReportNotesAreNotRepresented(): void
    {
        $out = $this->formatter(Verdict::SILENT, $this->lockPath())->format($this->report());
        self::assertStringNotContainsString('GitHub token not set', $out);
    }

    public function testWordingAvoidsBannedTerms(): void
    {
        $out = strtolower($this->formatter(Verdict::SILENT, $this->lockPath())->format($this->report(), true));
        foreach (['vulnerable', 'broken', 'insecure', 'dead'] as $banned) {
            self::assertStringNotContainsString($banned, $out);
        }
    }

    public function testFactory(): void
    {
        self::assertInstanceOf(
            GitlabFormatter::class,
            Formatters::for('gitlab', FormatContext::create(null, LockrotConfig::FAIL_ON_NONE))
        );
    }

    public function testFactoryStillRejectsAnUnknownFormat(): void
    {
        $this->expectException(ConfigException::class);
        Formatters::for('xml', FormatContext::create(null, LockrotConfig::FAIL_ON_NONE));
    }



    /** GitLab reads compact JSON as well. The pretty, slash-unescaped form is for the human diffing the artifact. */
    public function testTheDocumentIsPrettyPrintedWithSlashesLeftAlone(): void
    {
        $json = $this->formatter(LockrotConfig::FAIL_ON_NONE, null)->format($this->report());

        self::assertStringStartsWith("[\n    {\n", $json);
        self::assertStringContainsString('"acme/abandoned 1.0.0', $json);
        self::assertStringNotContainsString('\/', $json);
    }

    /** SPEC §6.2: one run-level issue, `major` only when `unchecked` fails on the advisories gap. */
    public function testTheRunLevelAdvisoriesIssueIsMajorOnlyWhenUncheckedFailsOnTheGap(): void
    {
        $severity = function (string $failOn, Report $report): ?string {
            $severity = array_column($this->decode($this->formatter($failOn, null)->format($report)), 'severity', 'check_name')['lockrot/advisories-unchecked'] ?? null;

            return \is_string($severity) ? $severity : null;
        };

        self::assertSame('major', $severity('unchecked', self::uncheckedReport()));
        self::assertSame('minor', $severity('high', self::uncheckedReport()));
        self::assertSame('minor', $severity('unchecked', self::skippedReport()));
        self::assertNull($severity('unchecked', $this->report()));
    }

    /** The run-level issue is a whole Code Quality issue, which GitLab places on the lock's first line. */
    public function testTheRunLevelAdvisoriesIssueIsACompleteCodeQualityIssue(): void
    {
        $issues = $this->decode($this->formatter('high', null)->format(self::uncheckedReport()));

        self::assertSame([
            'type' => 'issue',
            'check_name' => 'lockrot/advisories-unchecked',
            'description' => 'advisories not checked: the gate could not read the advisories of every package',
            'categories' => ['Bug Risk'],
            'severity' => 'minor',
            'fingerprint' => hash('sha256', 'lockrot|advisories-unchecked'),
            'location' => ['path' => 'composer.lock', 'lines' => ['begin' => 1]],
        ], $issues[\count($issues) - 1]);
    }

    /** SPEC §7.10: a finding with S10 and no counted fact gets one `lockrot/unchecked` issue while `unchecked` is active. */
    public function testAFindingWithS10AndNoCountedFactGetsAnUncheckedIssue(): void
    {
        $issues = $this->decode($this->formatter('unchecked', null)->format(self::uncheckedReport()));

        self::assertSame('lockrot/unchecked', $issues[0]['check_name'] ?? null);
        self::assertSame('major', $issues[0]['severity'] ?? null);
        self::assertSame(hash('sha256', 'lockrot|acme/offline|unchecked'), $issues[0]['fingerprint'] ?? null);
        self::assertNotContains('lockrot/unchecked', array_column($this->decode($this->formatter('high', null)->format(self::uncheckedReport())), 'check_name'));
    }

    public function testUnderAllAScoreZeroFindingWithS10KeepsItsWordIssueBesideItsUncheckedIssue(): void
    {
        $issues = function (string $failOn): array {
            $out = [];
            foreach ($this->decode($this->formatter($failOn, null)->format(self::uncheckedReport(), true)) as $issue) {
                if ($issue['check_name'] !== 'lockrot/advisories-unchecked') {
                    $out[] = JsonPath::stringAt($issue, ['check_name']).' '.JsonPath::stringAt($issue, ['severity']);
                }
            }

            return $out;
        };

        self::assertSame(['lockrot/ok info', 'lockrot/unchecked major', 'lockrot/ok info'], $issues('unchecked'));
        self::assertSame(['lockrot/ok info', 'lockrot/unchecked info', 'lockrot/ok info'], $issues('high'));
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

    public function testTheCompositeRendersAsItsGolden(): void
    {
        TextGolden::assertMatches('composite.gitlab.json', (new GitlabFormatter(FormatContext::create(null, 'high')))->format(CaseHydrator::composite()), __METHOD__);
    }

    /** One issue per counted flag, its fingerprint the hash of the package and the flag. */
    public function testEachCountedFlagGetsAnIssueWithThe013Fingerprint(): void
    {
        $issues = self::issues('high');
        $byKey = [];
        foreach ($issues as $issue) {
            $byKey[JsonPath::stringAt($issue, ['check_name']).' '.explode(' ', JsonPath::stringAt($issue, ['description']))[0]] = $issue;
        }

        self::assertCount(15, $issues);
        self::assertSame(hash('sha256', 'lockrot|symfony/debug|abandoned'), $byKey['lockrot/abandoned symfony/debug']['fingerprint']);
        self::assertStringStartsWith('2df5056e8311a7fe', JsonPath::stringAt($byKey['lockrot/abandoned symfony/debug'], ['fingerprint']));
        self::assertStringStartsWith('24be27cc46819fdc', JsonPath::stringAt($byKey['lockrot/vulnerable symfony/yaml'], ['fingerprint']));
        self::assertSame(['Security'], $byKey['lockrot/vulnerable symfony/yaml']['categories']);
        self::assertSame(['Bug Risk'], $byKey['lockrot/abandoned symfony/debug']['categories']);
    }

    /** The issue whose own fact reaches the active value is major; under left-behind the advisory is minor. */
    public function testTheSeverityFollowsTheFactThatReachedTheValue(): void
    {
        $severity = static function (string $failOn): array {
            $out = [];
            foreach (self::issues($failOn) as $issue) {
                $out[explode(' ', JsonPath::stringAt($issue, ['description']))[0].' '.JsonPath::stringAt($issue, ['check_name'])] = JsonPath::stringAt($issue, ['severity']);
            }

            return $out;
        };
        $high = $severity('high');
        $leftBehind = $severity('left-behind');

        self::assertSame(['major' => 8, 'minor' => 7], array_count_values($high));
        self::assertSame('major', $high['symfony/monolog-bridge lockrot/vulnerable']);
        self::assertSame('minor', $high['symfony/monolog-bridge lockrot/old-promise']);
        self::assertSame('major', $leftBehind['symfony/monolog-bridge lockrot/left-behind']);
        self::assertSame('minor', $leftBehind['symfony/monolog-bridge lockrot/vulnerable']);
    }

    /** SPEC §7.11: `unchecked` raises the first issue of a graded finding with S10 to `major`, and no other. */
    public function testTheUncheckedValueRaisesTheFirstIssueOfAGradedFinding(): void
    {
        $composite = CaseHydrator::composite();
        $graded = array_values(array_filter($composite->graded(), static fn (Finding $finding): bool => \in_array($finding->package(), ['symfony/debug', 'symfony/yaml'], true)));
        $report = new Report(array_map(static fn (Finding $finding): Finding => FindingBuilder::withAdvisoriesUnchecked($finding, 'offline'), $graded), [], $composite->generatedAt(), 2, 0);

        $issues = [];
        foreach ($this->decode($this->formatter('unchecked', null)->format($report)) as $issue) {
            $issues[] = [$issue['check_name'], $issue['severity']];
        }

        self::assertSame([['lockrot/abandoned', 'major'], ['lockrot/old-promise', 'minor'], ['lockrot/vulnerable', 'major']], $issues);
    }

    /** @return list<array<mixed, mixed>> */
    private static function issues(string $failOn): array
    {
        $issues = json_decode((new GitlabFormatter(FormatContext::create(null, $failOn)))->format(CaseHydrator::composite()->withRun(new RunSettings(null, null, '8.4', RunSettings::SOURCE_OPTION, null, FailOn::parse($failOn), RunSettings::SOURCE_OPTION, null))), true);
        self::assertIsArray($issues);

        return array_values(array_filter($issues, 'is_array'));
    }
}
