<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use JsonSchema\Validator;
use Lockrot\Analyzer\LibyearsMeasurement;
use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunSettings;
use Lockrot\Baseline\Baseline;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Config\Gate;
use Lockrot\Config\LockrotConfig;
use Lockrot\Data\Advisory\AdvisoryCoverage;
use Lockrot\Data\Advisory\AdvisoryRepository;
use Lockrot\Output\FormatContext;
use Lockrot\Output\Formatters;
use Lockrot\Output\SarifFormatter;
use Lockrot\Signal\Data\AdvisoryData;
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

final class SarifFormatterTest extends TestCase
{
    /**
     * The official SARIF 2.1.0 JSON schema from https://json.schemastore.org/sarif-2.1.0.json,
     * committed unchanged, so this test never depends on the network.
     */
    private const SCHEMA = __DIR__.'/../../fixtures/sarif/sarif-schema-2.1.0.json';
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
        $dir = sys_get_temp_dir().'/lockrot-sarif-'.bin2hex(random_bytes(8));
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
            (new FindingBuilder())->withPackage('acme/also-abandoned')->withVersion('1.1.0')->withVerdict(Verdict::ABANDONED)->withSignals([new Signal('high', 'repository archived', SignalDataStub::of('S3', []))])->withChain(['acme/also-abandoned'])->withDataDate($at)->build(),
            (new FindingBuilder())->withPackage('acme/silent')->withVersion('2.0.8')->withVerdict(Verdict::SILENT)->withSignals([new Signal('high', 'last release 2015-11-16', SignalDataStub::of('S2', [])), new Signal('high', 'last push 2015-11-16', SignalDataStub::of('S4', []))])->withChain(['a/parent', 'acme/silent'])->withDataDate($at)->build(),
            (new FindingBuilder())->withPackage('acme/fine')->withVersion('4.0.0')->withChain(['acme/fine'])->withDataDate($at)->build(),
        ], Notes::texts(['GitHub token not set: repository activity checked only for 2 candidate packages']), $at, 4, 0);
    }

    private function formatter(string $failOn, ?string $lockPath): SarifFormatter
    {
        return new SarifFormatter(FormatContext::create($lockPath, $failOn));
    }

    /** @return array<mixed, mixed> */
    private function singleRun(string $sarif): array
    {
        self::assertStringEndsWith("\n", $sarif);
        $decoded = json_decode($sarif, true);
        self::assertIsArray($decoded);
        self::assertCount(1, JsonPath::arrayAt($decoded, ['runs']));

        return JsonPath::arrayAt($decoded, ['runs', 0]);
    }

    private function assertValidSarif(string $sarif): void
    {
        $schema = json_decode((string) file_get_contents(self::SCHEMA));
        self::assertIsObject($schema);
        $document = json_decode($sarif);
        self::assertIsObject($document);

        $validator = new Validator();
        $validator->validate($document, $schema);

        $messages = [];
        foreach ($validator->getErrors() as $error) {
            if (\is_array($error) && \is_string($error['property'] ?? null) && \is_string($error['message'] ?? null)) {
                $messages[] = $error['property'].': '.$error['message'];
            }
        }
        self::assertTrue($validator->isValid(), implode("\n", $messages));
    }

    /** SPEC-0.14.md §8.5.4: a whole number reads `0`, never `0.0`; only `rank` keeps its zero fraction. */
    public function testOnlyTheRankKeepsAZeroFraction(): void
    {
        $finding = (new FindingBuilder())->withVerdict(Verdict::STALE)->withLibyears(LibyearsMeasurement::of(1.0))->build();
        $sarif = Formatters::for('sarif', FormatContext::create(null, 'none'))->format(new Report([$finding], [], new \DateTimeImmutable('2026-10-14T00:00:00+00:00'), 1, 0));

        self::assertMatchesRegularExpression('{"rank": \d+\.0,}', $sarif);
        self::assertStringContainsString('"years": 0,', $sarif);
        self::assertStringNotContainsString('"years": 0.0', $sarif);
    }

    /** In case either-critical‡ only the other move of symfony/debug clears its critical advisory. */
    public function testAnAdvisoryThatOnlyAnOtherMoveClearsNamesThatMove(): void
    {
        $sarif = JsonPath::decoded(json_decode((new SarifFormatter(FormatContext::create(null, 'high')))->format(CaseHydrator::report(CaseHydrator::case('either-critical‡')), true), true));
        $texts = [];
        foreach (JsonPath::arrayAt($sarif, ['runs', 0, 'results']) as $result) {
            self::assertIsArray($result);
            $texts[JsonPath::stringAt($result, ['ruleId'])] = JsonPath::stringAt($result, ['message', 'text']);
        }

        self::assertStringContainsString('. Do: require symfony/debug ^4.4.0 — clears the advisory;', $texts['lockrot/advisory/PKSA-eith-0000-0001'] ?? '');
    }

    public function testFlaggedReportIsValidSarif(): void
    {
        $this->assertValidSarif($this->formatter(Verdict::SILENT, $this->lockPath())->format($this->report()));
    }

    public function testShowAllReportIsValidSarif(): void
    {
        $this->assertValidSarif($this->formatter(Verdict::SILENT, $this->lockPath())->format($this->report(), true));
    }

    public function testEmptyReportIsValidSarif(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $sarif = $this->formatter(LockrotConfig::FAIL_ON_NONE, null)->format(new Report([], [], $at, 0, 0));
        $this->assertValidSarif($sarif);

        $run = $this->singleRun($sarif);
        self::assertSame([], JsonPath::arrayAt($run, ['results']));
        self::assertSame([], JsonPath::arrayAt($run, ['tool', 'driver', 'rules']));
        self::assertFalse(JsonPath::has($run, ['originalUriBaseIds']));
        self::assertFalse(JsonPath::has($run, ['invocations', 0, 'toolExecutionNotifications']));
    }

    public function testEnvelope(): void
    {
        $sarif = $this->formatter(Verdict::SILENT, $this->lockPath())->format($this->report());
        $decoded = json_decode($sarif, true);
        self::assertIsArray($decoded);

        self::assertSame('2.1.0', JsonPath::stringAt($decoded, ['version']));
        self::assertSame('https://json.schemastore.org/sarif-2.1.0.json', JsonPath::stringAt($decoded, ['$schema']));

        $run = $this->singleRun($sarif);
        self::assertSame('lockrot', JsonPath::stringAt($run, ['tool', 'driver', 'name']));
        self::assertSame(Version::STRING, JsonPath::stringAt($run, ['tool', 'driver', 'version']));
        self::assertSame('https://github.com/somework/lockrot', JsonPath::stringAt($run, ['tool', 'driver', 'informationUri']));
        self::assertSame('utf16CodeUnits', JsonPath::stringAt($run, ['columnKind']));
        self::assertTrue(JsonPath::boolAt($run, ['invocations', 0, 'executionSuccessful']));
        self::assertSame('note', JsonPath::stringAt($run, ['invocations', 0, 'toolExecutionNotifications', 0, 'level']));
        self::assertStringStartsWith(
            'GitHub token not set',
            JsonPath::stringAt($run, ['invocations', 0, 'toolExecutionNotifications', 0, 'message', 'text'])
        );
    }

    public function testRulesAreDeduplicatedAndIndexed(): void
    {
        $run = $this->singleRun($this->formatter(Verdict::SILENT, $this->lockPath())->format($this->report()));

        self::assertSame(['lockrot/abandoned', 'lockrot/silent'], JsonPath::column($run, ['tool', 'driver', 'rules'], 'id'));
        self::assertSame('warning', JsonPath::stringAt($run, ['tool', 'driver', 'rules', 0, 'defaultConfiguration', 'level']));
        self::assertStringContainsString('abandoned', JsonPath::stringAt($run, ['tool', 'driver', 'rules', 0, 'shortDescription', 'text']));
        self::assertStringContainsString('archived', JsonPath::stringAt($run, ['tool', 'driver', 'rules', 0, 'fullDescription', 'text']));
        self::assertSame('https://lockrot.dev/verdicts/', JsonPath::stringAt($run, ['tool', 'driver', 'rules', 0, 'helpUri']));

        self::assertCount(3, JsonPath::arrayAt($run, ['results']));
        self::assertSame([0, 0, 1], JsonPath::column($run, ['results'], 'ruleIndex'));
        self::assertSame(['lockrot/abandoned', 'lockrot/abandoned', 'lockrot/silent'], JsonPath::column($run, ['results'], 'ruleId'));
    }


    public function testLevelMappingAtTheFailOnBoundary(): void
    {
        $lockPath = $this->lockPath();

        $atSilent = $this->singleRun($this->formatter(Verdict::SILENT, $lockPath)->format($this->report(), true));
        self::assertSame(['error', 'error', 'error', 'note'], JsonPath::column($atSilent, ['results'], 'level'));

        $atAbandoned = $this->singleRun($this->formatter(Verdict::ABANDONED, $lockPath)->format($this->report(), true));
        self::assertSame(['error', 'error', 'warning', 'note'], JsonPath::column($atAbandoned, ['results'], 'level'));

        $atNone = $this->singleRun($this->formatter(LockrotConfig::FAIL_ON_NONE, $lockPath)->format($this->report(), true));
        self::assertSame(['warning', 'warning', 'warning', 'note'], JsonPath::column($atNone, ['results'], 'level'));
    }


    /**
     * A report whose five rows land on all five priorities: critical, high (transitive production),
     * medium (transitive and development-only), low (transitive stale) and none (not flagged at
     * all) — so every step of the rank scale has a row behind it.
     */
    private function priorityReport(): Report
    {
        $at = new \DateTimeImmutable(self::AT);

        return new Report([
            (new FindingBuilder())->withPackage('acme/abandoned')->withVerdict(Verdict::ABANDONED)->withSignals([new Signal('high', 'marked abandoned by its repository', SignalDataStub::of('S1', []))])->withChain(['acme/abandoned'])->withDataDate($at)->build(),
            (new FindingBuilder())->withPackage('acme/transitive')->withVersion('5.0.0')->withVerdict(Verdict::ABANDONED)->withSignals([new Signal('high', 'marked abandoned by its repository', SignalDataStub::of('S1', []))])->withChain(['a/parent', 'acme/transitive'])->withDataDate($at)->build(),
            (new FindingBuilder())->withPackage('acme/dev-only')->withVersion('2.0.0')->withVerdict(Verdict::ABANDONED)->withSignals([new Signal('high', 'marked abandoned by its repository', SignalDataStub::of('S1', []))])->withChain(['a/parent', 'acme/dev-only'])->withDataDate($at)->withDev(true)->build(),
            (new FindingBuilder())->withPackage('acme/stale')->withVersion('3.0.0')->withVerdict(Verdict::STALE)->withSignals([new Signal('warn', 'last release 2022-05-20', SignalDataStub::of('S2', []))])->withChain(['a/parent', 'acme/stale'])->withDataDate($at)->build(),
            (new FindingBuilder())->withPackage('acme/fine')->withVersion('4.0.0')->withChain(['acme/fine'])->withDataDate($at)->build(),
        ], [], $at, 5, 0);
    }


    public function testPriorityDirectAndDevAreCarriedAsResultProperties(): void
    {
        $run = $this->singleRun($this->formatter(LockrotConfig::FAIL_ON_NONE, null)->format($this->priorityReport(), true));

        self::assertSame(['critical', 'high', 'medium', 'low', 'none'], self::propertyColumn($run, 'priority'));

        self::assertTrue(JsonPath::boolAt($run, ['results', 0, 'properties', 'direct']));
        self::assertFalse(JsonPath::boolAt($run, ['results', 0, 'properties', 'dev']));
        // the high row: transitive but production, which is what puts it a step above the dev one
        self::assertFalse(JsonPath::boolAt($run, ['results', 1, 'properties', 'direct']));
        self::assertFalse(JsonPath::boolAt($run, ['results', 1, 'properties', 'dev']));
        self::assertFalse(JsonPath::boolAt($run, ['results', 2, 'properties', 'direct']));
        self::assertTrue(JsonPath::boolAt($run, ['results', 2, 'properties', 'dev']));
    }

    /** The priority informs the reader; the rule and the level stay on the verdict alone. */
    public function testRuleIdAndLevelAreUnaffectedByThePriority(): void
    {
        $run = $this->singleRun($this->formatter(Verdict::ABANDONED, null)->format($this->priorityReport(), true));

        self::assertSame(
            ['lockrot/abandoned', 'lockrot/abandoned', 'lockrot/abandoned', 'lockrot/stale', 'lockrot/ok'],
            JsonPath::column($run, ['results'], 'ruleId')
        );
        self::assertSame(['error', 'error', 'error', 'warning', 'note'], JsonPath::column($run, ['results'], 'level'));
    }

    /**
     * A checkout directory can contain characters that a URI cannot carry raw. The bundled
     * validator's `uri-reference` format check is lenient enough to accept a raw space, so this
     * asserts the encoding directly rather than relying on schema validation to catch it.
     */
    public function testDirectoryUriIsPercentEncoded(): void
    {
        $dir = sys_get_temp_dir().'/lockrot sarif #uri-'.bin2hex(random_bytes(8));
        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create temp dir: '.$dir);
        }
        $this->tempDirs[] = $dir;
        file_put_contents($dir.'/composer.lock', '{"packages":[]}');

        $at = new \DateTimeImmutable(self::AT);
        $sarif = $this->formatter(LockrotConfig::FAIL_ON_NONE, $dir.'/composer.lock')
            ->format(new Report([], [], $at, 0, 0));
        $this->assertValidSarif($sarif);

        $uri = JsonPath::stringAt($this->singleRun($sarif), ['originalUriBaseIds', '%SRCROOT%', 'uri']);
        self::assertStringStartsWith('file:///', $uri);
        self::assertStringEndsWith('/', $uri);
        self::assertStringContainsString('/lockrot%20sarif%20%23uri-', $uri);
        self::assertStringNotContainsString(' ', $uri);
        self::assertStringNotContainsString('#', $uri);
        // ":" is a legal path character and a Windows drive letter needs it, so it stays raw.
        self::assertStringNotContainsString('%3A', $uri);
    }

    private function inProject(string $failOn, string $lockPath, string $projectDirectory): SarifFormatter
    {
        return new SarifFormatter(FormatContext::create($lockPath, $failOn, Version::STRING, FormatContext::DEFAULT_WIDTH, $projectDirectory));
    }

    /**
     * Under `COMPOSER=alt.json` the analysed lock is alt.lock: every result's `uri` is the lock's
     * path relative to the project directory, and %SRCROOT% is that directory, so the two resolve
     * to the file the run read. The partial fingerprint stays the package name.
     */
    public function testTheArtifactLocationNamesTheAnalysedLockRelativeToTheProjectDirectory(): void
    {
        $lockPath = $this->lockPath('alt.lock');
        $project = \dirname($lockPath, 2);

        $sarif = $this->inProject(Verdict::SILENT, $lockPath, $project)->format($this->report());
        $this->assertValidSarif($sarif);
        $run = $this->singleRun($sarif);

        self::assertSame(
            array_fill(0, 3, basename(\dirname($lockPath)).'/alt.lock'),
            array_map(
                static fn (int $i): string => JsonPath::stringAt($run, ['results', $i, 'locations', 0, 'physicalLocation', 'artifactLocation', 'uri']),
                [0, 1, 2]
            )
        );
        self::assertSame('%SRCROOT%', JsonPath::stringAt($run, ['results', 0, 'locations', 0, 'physicalLocation', 'artifactLocation', 'uriBaseId']));
        self::assertSame(4, JsonPath::intAt($run, ['results', 0, 'locations', 0, 'physicalLocation', 'region', 'startLine']));
        self::assertSame(
            'file://'.rtrim(str_replace('\\', '/', $project), '/').'/',
            JsonPath::stringAt($run, ['originalUriBaseIds', '%SRCROOT%', 'uri'])
        );
        self::assertSame(['lockrot/package' => 'acme/abandoned'], JsonPath::arrayAt($run, ['results', 0, 'partialFingerprints']));
    }

    public function testTheDefaultLockKeepsTheDocumentByteForByte(): void
    {
        $lockPath = $this->lockPath();

        $out = $this->inProject(Verdict::SILENT, $lockPath, \dirname($lockPath))->format($this->report(), true);

        self::assertSame($this->formatter(Verdict::SILENT, $lockPath)->format($this->report(), true), $out);
        self::assertStringContainsString('"uri": "composer.lock"', $out);
    }

    /**
     * The lock's name is a relative URI reference, so it is percent-encoded per segment — a colon
     * included, which in a first segment otherwise reads as a URI scheme.
     */
    public function testTheArtifactUriIsPercentEncoded(): void
    {
        $lockPath = $this->lockPath('a b#c:d.lock');

        $sarif = $this->inProject(Verdict::SILENT, $lockPath, \dirname($lockPath, 2))->format($this->report());
        $this->assertValidSarif($sarif);

        self::assertSame(
            rawurlencode(basename(\dirname($lockPath))).'/a%20b%23c%3Ad.lock',
            JsonPath::stringAt($this->singleRun($sarif), ['results', 0, 'locations', 0, 'physicalLocation', 'artifactLocation', 'uri'])
        );
    }

    public function testWithoutALockPathThereIsNoUriBaseId(): void
    {
        $run = $this->singleRun($this->formatter(Verdict::SILENT, null)->format($this->report()));

        self::assertFalse(JsonPath::has($run, ['originalUriBaseIds']));
        self::assertFalse(JsonPath::has($run, ['results', 0, 'locations', 0, 'physicalLocation', 'artifactLocation', 'uriBaseId']));
        self::assertFalse(JsonPath::has($run, ['results', 0, 'locations', 0, 'physicalLocation', 'region']));
        self::assertSame('composer.lock', JsonPath::stringAt($run, ['results', 0, 'locations', 0, 'physicalLocation', 'artifactLocation', 'uri']));
    }

    public function testWordingAvoidsBannedTerms(): void
    {
        $out = strtolower($this->formatter(Verdict::SILENT, $this->lockPath())->format($this->allVerdictsReport(), true));
        foreach (['vulnerable', 'broken', 'insecure', 'dead'] as $banned) {
            self::assertStringNotContainsString($banned, $out);
        }
    }


    private function allVerdictsReport(): Report
    {
        $at = new \DateTimeImmutable(self::AT);
        $findings = [];
        foreach ([Verdict::ABANDONED, Verdict::SILENT, Verdict::PINNED, Verdict::LEFT_BEHIND, Verdict::OLD_PROMISE, Verdict::STALE, Verdict::UNKNOWN, Verdict::FINISHED, Verdict::OK] as $index => $verdict) {
            $findings[] = (new FindingBuilder())->withPackage('acme/pkg'.$index)->withVersion('1.0.'.$index)->withVerdict($verdict)->withSignals([new Signal('warn', 'last release 2015-11-16', SignalDataStub::of('S2', []))])->withChain(['acme/pkg'.$index])->withDataDate($at)->build();
        }

        return new Report($findings, [], $at, \count($findings), 0);
    }

    public function testFactory(): void
    {
        self::assertInstanceOf(
            SarifFormatter::class,
            Formatters::for('sarif', FormatContext::create(null, LockrotConfig::FAIL_ON_NONE))
        );
    }

    /**
     * The report compared against a baseline that knows acme/abandoned as it is,
     * acme/silent as only stale (worsened) and nothing about acme/also-abandoned (new).
     */
    private function baselinedReport(): Report
    {
        $report = $this->report();
        $names = [];
        foreach ($report->findings() as $finding) {
            $names[] = $finding->package();
        }
        $baseline = BaselineEntries::baseline([
            BaselineEntries::of('acme/abandoned', '1.0.0', Verdict::ABANDONED, '2026-01-15'),
            BaselineEntries::of('acme/silent', '2.0.8', Verdict::STALE, '2026-01-15'),
        ], self::AT);

        return $report->withBaseline(BaselineComparison::compare($baseline, $report, 'lockrot-baseline.json', $names));
    }


    public function testABaselinedResultIsReportedAtNoteLevel(): void
    {
        $run = $this->singleRun($this->formatter(Verdict::SILENT, $this->lockPath())->format($this->baselinedReport()));
        $levels = [];
        foreach (JsonPath::arrayAt($run, ['results']) as $result) {
            self::assertIsArray($result);
            $levels[JsonPath::stringAt($result, ['properties', 'package'])] = JsonPath::stringAt($result, ['level']);
        }

        self::assertSame([
            'acme/abandoned' => FormatContext::LEVEL_NOTE,
            'acme/also-abandoned' => FormatContext::LEVEL_ERROR,
            'acme/silent' => FormatContext::LEVEL_ERROR,
        ], $levels);
    }

    public function testTheAdvisoryResultThatDecidesTheScoreIsTheOneMarkedDeciding(): void
    {
        $deciding = [];
        foreach (JsonPath::arrayAt($this->singleRun($this->formatter('high', null)->format(CaseHydrator::composite())), ['results']) as $result) {
            self::assertIsArray($result);
            if (JsonPath::arrayAt($result, ['properties'])['deciding'] ?? false) {
                $deciding[] = JsonPath::stringAt($result, ['properties', 'package']).' '.JsonPath::stringAt($result, ['properties', 'advisory']).' '.JsonPath::stringAt($result, ['properties', 'severity']);
            }
        }

        self::assertContains('twig/twig PKSA-h8hf-ytnd-5t9q critical', $deciding);
        self::assertSame(\count($deciding), \count(array_unique(array_map(static fn (string $row): string => explode(' ', $row)[0], $deciding))), 'one deciding advisory per package');
    }

    public function testOnlyAResultWhoseFactTheBaselineKnowsCarriesTheSuppressionThatNamesTheFileAndTheDate(): void
    {
        $run = $this->singleRun($this->formatter(Verdict::SILENT, $this->lockPath())->format($this->baselinedReport()));
        $suppressions = [];
        foreach (JsonPath::arrayAt($run, ['results']) as $result) {
            self::assertIsArray($result);
            $suppressions[JsonPath::stringAt($result, ['properties', 'package'])] = $result['suppressions'] ?? null;
        }

        self::assertSame([
            'acme/abandoned' => [['kind' => 'external', 'status' => 'accepted', 'justification' => 'covered by lockrot-baseline.json (known since 2026-01-15)']],
            'acme/also-abandoned' => null,
            'acme/silent' => null,
        ], $suppressions);
    }

    public function testABaselinedReportIsStillValidSarif(): void
    {
        $this->assertValidSarif($this->formatter(Verdict::SILENT, $this->lockPath())->format($this->baselinedReport()));
    }

    public function testBaselineStaleEntriesBecomeAToolExecutionNotification(): void
    {
        $report = $this->report();
        $baseline = BaselineEntries::baseline([
            BaselineEntries::of('acme/departed', '1.0.0', Verdict::ABANDONED, '2026-01-15'),
        ], self::AT);
        $withBaseline = $report->withBaseline(BaselineComparison::compare($baseline, $report, 'lockrot-baseline.json', []));

        $run = $this->singleRun($this->formatter(Verdict::SILENT, $this->lockPath())->format($withBaseline));
        $texts = [];
        foreach (JsonPath::arrayAt($run, ['invocations', 0, 'toolExecutionNotifications']) as $notification) {
            self::assertIsArray($notification);
            $texts[] = JsonPath::stringAt($notification, ['message', 'text']);
        }

        self::assertContains('baseline lists 1 package no longer in composer.lock: acme/departed', $texts);
    }

    /**
     * @param array<mixed, mixed> $run
     *
     * @return list<string>
     */
    private static function propertyColumn(array $run, string $key): array
    {
        $values = [];
        foreach (JsonPath::arrayAt($run, ['results']) as $result) {
            self::assertIsArray($result);
            $values[] = JsonPath::stringAt($result, ['properties', $key]);
        }

        return $values;
    }

    public function testWithoutABaselineNoBaselinePropertyIsEmitted(): void
    {
        $run = $this->singleRun($this->formatter(Verdict::SILENT, $this->lockPath())->format($this->report()));
        $first = JsonPath::arrayAt($run, ['results', 0, 'properties']);

        self::assertArrayNotHasKey('baseline', $first);
    }

    public function testDirectDependentsAreCarriedAsAProperty(): void
    {
        $at = new \DateTimeImmutable('2026-09-14T06:00:00+00:00');
        $report = new Report([
            (new FindingBuilder())->withPackage('acme/leaf')->withVerdict(Verdict::STALE)->withChain(['a/parent', 'acme/leaf'])->withDataDate($at)->withDirectDependents(['a/parent', 'b/parent'])->build(),
        ], [], $at, 1, 0);
        $sarif = $this->formatter(LockrotConfig::FAIL_ON_NONE, null)->format($report);
        $this->assertValidSarif($sarif);

        self::assertSame(['a/parent', 'b/parent'], JsonPath::arrayAt($this->singleRun($sarif), ['results', 0, 'properties', 'direct_dependents']));
    }




    /**
     * A backslash is a legal character in a POSIX directory name. lockrot reads it as the separator
     * it is on Windows, on every platform, and never encodes a colon. The URI starts with exactly
     * three slashes: the directory's leading one is the authority/path boundary, not a segment.
     */
    public function testDirectoryUriKeepsAColonAndTreatsABackslashAsASeparator(): void
    {
        $suffix = bin2hex(random_bytes(8));
        $dir = sys_get_temp_dir().'/lockrot-sarif-c:\\drive-'.$suffix;
        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create temp dir: '.$dir);
        }
        $this->tempDirs[] = $dir;
        file_put_contents($dir.'/composer.lock', '{"packages":[]}');

        $at = new \DateTimeImmutable(self::AT);
        $sarif = $this->formatter(LockrotConfig::FAIL_ON_NONE, $dir.'/composer.lock')->format(new Report([], [], $at, 0, 0));
        $uri = JsonPath::stringAt($this->singleRun($sarif), ['originalUriBaseIds', '%SRCROOT%', 'uri']);

        self::assertStringStartsWith('file:///', $uri);
        self::assertStringStartsNotWith('file:////', $uri);
        self::assertStringEndsWith('/lockrot-sarif-c:/drive-'.$suffix.'/', $uri);
    }

    /**
     * SPEC §6.2: one run-level `advisories not checked` result. It is `error` only when `unchecked`
     * is active and a finding carries S10 for its advisories.
     */
    public function testTheRunLevelAdvisoriesResultIsAnErrorOnlyWhenUncheckedFailsOnTheGap(): void
    {
        $failing = $this->runLevel($this->formatter('unchecked', null)->format(self::uncheckedReport()));
        $passing = $this->runLevel($this->formatter('high', null)->format(self::uncheckedReport()));
        $skipped = $this->runLevel($this->formatter('unchecked', null)->format(self::skippedReport()));

        self::assertSame(['lockrot/advisories-unchecked', 'error'], [$failing['ruleId'] ?? null, $failing['level'] ?? null]);
        self::assertSame('warning', $passing['level'] ?? null);
        self::assertSame('warning', $skipped['level'] ?? null, 'a skip is a gap that the gate does not fail on');
        self::assertSame([], $this->runLevel($this->formatter('unchecked', null)->format($this->report())), 'every lookup ran');
        $this->assertValidSarif($this->formatter('unchecked', null)->format(self::uncheckedReport()));
    }

    /** SPEC §7.11: a finding with S10 and no counted fact gets its own `lockrot/unchecked` result while `unchecked` is active. */
    public function testAFindingWithS10AndNoCountedFactGetsAnUncheckedResult(): void
    {
        $results = JsonPath::arrayAt($this->singleRun($this->formatter('unchecked', null)->format(self::uncheckedReport())), ['results']);
        $byRule = array_column($results, 'level', 'ruleId');

        self::assertSame('error', $byRule['lockrot/unchecked'] ?? null);
        self::assertSame('acme/offline', JsonPath::stringAt($results, [0, 'properties', 'package']));
        self::assertArrayNotHasKey('lockrot/unchecked', array_column(JsonPath::arrayAt($this->singleRun($this->formatter('high', null)->format(self::uncheckedReport())), ['results']), 'level', 'ruleId'));
    }

    public function testUnderAllAScoreZeroFindingWithS10KeepsItsWordResultBesideItsUncheckedResult(): void
    {
        $records = function (string $failOn): array {
            $out = [];
            foreach (JsonPath::arrayAt($this->singleRun($this->formatter($failOn, null)->format(self::uncheckedReport(), true)), ['results']) as $result) {
                self::assertIsArray($result);
                if ($result['ruleId'] === 'lockrot/advisories-unchecked') {
                    continue;
                }
                $out[] = JsonPath::stringAt($result, ['properties', 'package']).' '.JsonPath::stringAt($result, ['ruleId']).' '.JsonPath::stringAt($result, ['level']);
            }

            return $out;
        };

        self::assertSame(['acme/offline lockrot/ok note', 'acme/offline lockrot/unchecked error', 'acme/vcs lockrot/ok note'], $records('unchecked'));
        self::assertSame(['acme/offline lockrot/ok note', 'acme/offline lockrot/unchecked note', 'acme/vcs lockrot/ok note'], $records('high'));
    }

    public function testUnderAllAScoreZeroPackagesFirstRecordAloneCarriesItsChain(): void
    {
        $keys = [];
        foreach (JsonPath::arrayAt($this->singleRun($this->formatter('unchecked', null)->format(self::uncheckedReport(), true)), ['results']) as $result) {
            self::assertIsArray($result);
            if (JsonPath::stringAt($result, ['ruleId']) !== 'lockrot/advisories-unchecked' && JsonPath::stringAt($result, ['properties', 'package']) === 'acme/offline') {
                $keys[JsonPath::stringAt($result, ['ruleId'])] = [\array_key_exists('chain', JsonPath::arrayAt($result, ['properties'])), JsonPath::arrayAt($result, ['properties', 'error_by'])];
            }
        }

        self::assertSame(['lockrot/ok' => [true, []], 'lockrot/unchecked' => [false, ['unchecked']]], $keys);
    }

    public function testAGenerateRunWritesExitCodeZeroWhateverTheFindings(): void
    {
        $settings = static fn (string $mode): RunSettings => new RunSettings(null, null, '8.4', RunSettings::SOURCE_OPTION, null, FailOn::parse('high'), RunSettings::SOURCE_OPTION, null, null, false, $mode);
        $check = $this->singleRun($this->formatter('high', null)->format(CaseHydrator::composite()->withRun($settings(Gate::MODE_CHECK))));
        $generate = $this->singleRun($this->formatter('high', null)->format(CaseHydrator::composite()->withRun($settings(Gate::MODE_GENERATE_BASELINE))));

        self::assertSame(1, JsonPath::intAt($check, ['invocations', 0, 'exitCode']));
        self::assertSame(0, JsonPath::intAt($generate, ['invocations', 0, 'exitCode']));
    }

    public function testTheRunLevelRuleIsDescribedAndAFailedRepositoryIsANotification(): void
    {
        $coverage = new AdvisoryCoverage(AdvisoryCoverage::SCOPE_ALL, AdvisoryCoverage::SOURCE_DEFAULT, [
            AdvisoryRepository::of('composer repo (https://repo.packagist.org)', AdvisoryRepository::FAILED, AdvisoryCoverage::TRANSPORT, 'timeout'),
            AdvisoryRepository::of('composer repo (https://repo.example.test)', AdvisoryRepository::NOT_ASKED, AdvisoryCoverage::OFFLINE),
        ], 0, []);
        $report = new Report([FindingBuilder::advisoriesUnchecked('acme/offline', 'offline', true)], [], new \DateTimeImmutable(self::AT), 1, 0, null, null, false, $coverage);
        $run = $this->singleRun($this->formatter('unchecked', null)->format($report));

        self::assertContains('lockrot/advisories-unchecked', array_column(JsonPath::arrayAt($run, ['tool', 'driver', 'rules']), 'id'));
        self::assertSame([['level' => 'warning', 'message' => ['text' => 'packagist.org lookup failed (network error: timeout)']]], JsonPath::arrayAt($run, ['invocations', 0, 'toolExecutionNotifications']));
    }

    /** @return array<mixed, mixed> the run-level result, or [] when the run has none */
    private function runLevel(string $sarif): array
    {
        foreach (JsonPath::arrayAt($this->singleRun($sarif), ['results']) as $result) {
            if (\is_array($result) && ($result['ruleId'] ?? null) === 'lockrot/advisories-unchecked') {
                return $result;
            }
        }

        return [];
    }

    /** SPEC §7.11: `unchecked` marks the first record of a graded finding with S10, a flag's or else an advisory's. */
    public function testTheUncheckedValueMarksTheFirstRecordOfAGradedFinding(): void
    {
        $composite = CaseHydrator::composite();
        $graded = array_values(array_filter($composite->graded(), static fn (Finding $finding): bool => \in_array($finding->package(), ['symfony/debug', 'symfony/yaml'], true)));
        $report = new Report(array_map(static fn (Finding $finding): Finding => FindingBuilder::withAdvisoriesUnchecked($finding, 'offline'), $graded), [], $composite->generatedAt(), 2, 0);

        $records = [];
        foreach (JsonPath::arrayAt($this->singleRun($this->formatter('unchecked', null)->format($report)), ['results']) as $result) {
            self::assertIsArray($result);
            $records[] = [JsonPath::stringAt($result, ['properties', 'package']), JsonPath::stringAt($result, ['level']), JsonPath::arrayAt($result, ['properties', 'error_by'])];
        }

        self::assertSame([
            ['symfony/debug', 'error', ['unchecked']],
            ['symfony/debug', 'warning', []],
            ['symfony/yaml', 'error', ['unchecked']],
            ['symfony/yaml', 'warning', []],
            ['symfony/yaml', 'warning', []],
        ], $records);
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
        TextGolden::assertMatches('composite.sarif.sarif', $this->formatter('high', null)->format(CaseHydrator::composite()), __METHOD__);
    }

    public function testEachCountedFlagAndEachCountedAdvisoryGetsItsResult(): void
    {
        $report = CaseHydrator::composite();
        $sarif = $this->formatter('high', null)->format($report);
        $this->assertValidSarif($sarif);
        $actual = [];
        foreach (JsonPath::arrayAt($this->singleRun($sarif), ['results']) as $result) {
            self::assertIsArray($result);
            $actual[JsonPath::stringAt($result, ['properties', 'package'])][] = JsonPath::stringAt($result, ['ruleId']);
        }
        $expected = [];
        foreach ($report->graded() as $finding) {
            foreach ($finding->scoreBasis()->maintenanceTerms() as $term) {
                $expected[$finding->package()][] = 'lockrot/'.$term->flag();
            }
            $s9 = $finding->signalData(AdvisoryData::class);
            foreach ($s9 === null ? [] : $s9->rows() as $row) {
                $expected[$finding->package()][] = 'lockrot/advisory/'.$row->id();
            }
        }

        self::assertNotSame([], $expected);
        self::assertSame($expected, $actual, 'one result per counted maintenance term and per counted S9 row, and no other');
    }

    public function testAMaintenanceResultRanksByItsOwnContributionAndAnAdvisoryBySeverity(): void
    {
        $run = $this->singleRun($this->formatter('high', null)->format(CaseHydrator::composite()));
        $ranks = [];
        foreach (JsonPath::arrayAt($run, ['results']) as $result) {
            self::assertIsArray($result);
            $ranks[JsonPath::stringAt($result, ['properties', 'package']).' '.JsonPath::stringAt($result, ['ruleId'])] = $result['rank'] ?? null;
        }

        self::assertSame(75.0, $ranks['symfony/monolog-bridge lockrot/left-behind']);
        self::assertSame(25.0, $ranks['symfony/monolog-bridge lockrot/old-promise']);
        self::assertSame(80.0, $ranks['symfony/monolog-bridge lockrot/advisory/PKSA-4wjj-gy1p-ft3r']);
        self::assertSame(100.0, $ranks['symfony/debug lockrot/abandoned']);
    }

    public function testOnlyTheFirstResultOfEachKindCarriesTheChainDepthAndAge(): void
    {
        $run = $this->singleRun($this->formatter('high', null)->format(CaseHydrator::composite()));
        $kinds = [];
        foreach (JsonPath::arrayAt($run, ['results']) as $result) {
            self::assertIsArray($result);
            $properties = JsonPath::arrayAt($result, ['properties']);
            $kind = JsonPath::stringAt($properties, ['package']).(strpos(JsonPath::stringAt($result, ['ruleId']), 'lockrot/advisory/') === 0 ? ' advisory' : ' maintenance');
            self::assertSame(!isset($kinds[$kind]), \array_key_exists('age', $properties), $kind);
            self::assertSame(!isset($kinds[$kind]), \array_key_exists('depth', $properties), $kind);
            self::assertArrayNotHasKey('baseline', $properties);
            $kinds[$kind] = true;
        }
    }

    public function testTheInvocationCarriesTheGateExitCodeAndItsLine(): void
    {
        $failing = JsonPath::arrayAt($this->singleRun($this->formatter('high', null)->format(CaseHydrator::composite())), ['invocations', 0]);
        $passing = JsonPath::arrayAt($this->singleRun($this->formatter('none', null)->format(CaseHydrator::composite()->withRun(new RunSettings(null, null, '8.4', RunSettings::SOURCE_OPTION, null, null, RunSettings::SOURCE_DEFAULT, null)))), ['invocations', 0]);

        self::assertTrue($failing['executionSuccessful']);
        self::assertSame(1, $failing['exitCode']);
        self::assertSame('gate: fails · --fail-on=high · 6 findings fail it · target php 8.4 (--target-php)', $failing['exitCodeDescription']);
        self::assertSame('high', JsonPath::stringAt($failing, ['properties', 'fail_on']));
        self::assertTrue(JsonPath::arrayAt($failing, ['properties', 'gate'])['fails']);
        self::assertSame(0, $passing['exitCode']);
        self::assertSame('gate: off · --fail-on=none (default) · target php 8.4 (--target-php)', $passing['exitCodeDescription']);
    }

    public function testTheRunNamesTheSchemaAndTheGrammars(): void
    {
        $properties = JsonPath::arrayAt($this->singleRun($this->formatter('high', null)->format(CaseHydrator::composite())), ['properties']);

        self::assertSame(2, $properties['lockrot_schema']);
        self::assertSame(1, $properties['score_text_grammar']);
        self::assertSame(1, $properties['score_model_id']);
        self::assertSame(9, JsonPath::arrayAt($properties, ['advisory_lookup'])['packages_asked']);
    }

    public function testAnAdvisoryRuleCarriesItsSecuritySeverity(): void
    {
        $rules = JsonPath::arrayAt($this->singleRun($this->formatter('high', null)->format(CaseHydrator::composite())), ['tool', 'driver', 'rules']);
        $byId = [];
        foreach ($rules as $rule) {
            self::assertIsArray($rule);
            $byId[JsonPath::stringAt($rule, ['id'])] = $rule;
        }

        self::assertSame('8.0', JsonPath::stringAt($byId['lockrot/advisory/PKSA-4wjj-gy1p-ft3r'], ['properties', 'security-severity']));
        self::assertSame(['maintainability'], JsonPath::arrayAt($byId['lockrot/left-behind'], ['properties', 'tags']));
    }
}
