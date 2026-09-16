<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use JsonSchema\Validator;
use Lockrot\Analyzer\Report;
use Lockrot\Baseline\Baseline;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Baseline\BaselineEntry;
use Lockrot\Config\LockrotConfig;
use Lockrot\Output\FormatContext;
use Lockrot\Output\Formatters;
use Lockrot\Output\SarifFormatter;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use Lockrot\Version;
use PHPUnit\Framework\TestCase;

final class SarifFormatterTest extends TestCase
{
    /**
     * The official SARIF 2.1.0 JSON schema, downloaded on 2026-09-15 from
     * https://json.schemastore.org/sarif-2.1.0.json and committed unchanged, so this test never
     * depends on the network.
     */
    private const SCHEMA = __DIR__.'/../../fixtures/sarif/sarif-schema-2.1.0.json';
    private const AT = '2026-09-14T06:00:00+00:00';

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            if (is_file($dir.'/composer.lock')) {
                unlink($dir.'/composer.lock');
            }
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
        $this->tempDirs = [];
    }

    /** acme/abandoned is on line 4 of the lock written here, acme/silent on line 8. */
    private function lockPath(): string
    {
        $dir = sys_get_temp_dir().'/lockrot-sarif-'.uniqid('', true);
        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create temp dir: '.$dir);
        }
        $this->tempDirs[] = $dir;
        file_put_contents($dir.'/composer.lock', <<<'JSON'
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

        return $dir.'/composer.lock';
    }

    private function report(): Report
    {
        $at = new \DateTimeImmutable(self::AT);

        return new Report([
            new Finding('acme/abandoned', '1.0.0', Verdict::ABANDONED, [new Signal('S1', 'high', 'marked abandoned by its repository')], ['acme/abandoned'], null, $at),
            new Finding('acme/also-abandoned', '1.1.0', Verdict::ABANDONED, [new Signal('S3', 'high', 'repository archived')], ['acme/also-abandoned'], null, $at),
            new Finding('acme/silent', '2.0.8', Verdict::SILENT, [new Signal('S2', 'high', 'last release 2015-11-16'), new Signal('S4', 'high', 'last push 2015-11-16')], ['a/parent', 'acme/silent'], null, $at),
            new Finding('acme/fine', '4.0.0', Verdict::OK, [], ['acme/fine'], null, $at),
        ], ['GitHub token not set: repository activity checked only for 2 candidate packages'], $at, 4, 0, false);
    }

    private function formatter(string $failOn, ?string $lockPath): SarifFormatter
    {
        return new SarifFormatter(FormatContext::create($lockPath, $failOn));
    }

    /**
     * The single run of a formatted report, as a decoded array.
     *
     * @return array<mixed, mixed>
     */
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
        $sarif = $this->formatter(LockrotConfig::FAIL_ON_NONE, null)->format(new Report([], [], $at, 0, 0, false));
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

    public function testResultLocationsRegionsAndProperties(): void
    {
        $lockPath = $this->lockPath();
        $run = $this->singleRun($this->formatter(Verdict::SILENT, $lockPath)->format($this->report()));

        self::assertSame('acme/abandoned 1.0.0: marked abandoned by its repository', JsonPath::stringAt($run, ['results', 0, 'message', 'text']));
        self::assertSame('composer.lock', JsonPath::stringAt($run, ['results', 0, 'locations', 0, 'physicalLocation', 'artifactLocation', 'uri']));
        self::assertSame('%SRCROOT%', JsonPath::stringAt($run, ['results', 0, 'locations', 0, 'physicalLocation', 'artifactLocation', 'uriBaseId']));
        self::assertSame(4, JsonPath::intAt($run, ['results', 0, 'locations', 0, 'physicalLocation', 'region', 'startLine']));
        self::assertSame(8, JsonPath::intAt($run, ['results', 2, 'locations', 0, 'physicalLocation', 'region', 'startLine']));
        self::assertSame(['lockrot/package' => 'acme/abandoned'], JsonPath::arrayAt($run, ['results', 0, 'partialFingerprints']));
        self::assertSame([
            'package' => 'acme/abandoned',
            'version' => '1.0.0',
            'verdict' => 'abandoned',
            'priority' => 'critical',
            'direct' => true,
            'dev' => false,
            'signals' => ['S1'],
            'chain' => ['acme/abandoned'],
            'direct_dependents' => [],
            'data_date' => '2026-09-14T06:00:00+00:00',
        ], JsonPath::arrayAt($run, ['results', 0, 'properties']));
        self::assertSame(['S2', 'S4'], JsonPath::arrayAt($run, ['results', 2, 'properties', 'signals']));
        self::assertSame(['a/parent', 'acme/silent'], JsonPath::arrayAt($run, ['results', 2, 'properties', 'chain']));

        // acme/also-abandoned is not in the lock, so its location carries no region at all.
        self::assertFalse(JsonPath::has($run, ['results', 1, 'locations', 0, 'physicalLocation', 'region']));

        self::assertSame(
            'file://'.rtrim(str_replace('\\', '/', \dirname($lockPath)), '/').'/',
            JsonPath::stringAt($run, ['originalUriBaseIds', '%SRCROOT%', 'uri'])
        );
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
            new Finding('acme/abandoned', '1.0.0', Verdict::ABANDONED, [new Signal('S1', 'high', 'marked abandoned by its repository')], ['acme/abandoned'], null, $at),
            new Finding('acme/transitive', '5.0.0', Verdict::ABANDONED, [new Signal('S1', 'high', 'marked abandoned by its repository')], ['a/parent', 'acme/transitive'], null, $at),
            new Finding('acme/dev-only', '2.0.0', Verdict::ABANDONED, [new Signal('S1', 'high', 'marked abandoned by its repository')], ['a/parent', 'acme/dev-only'], null, $at, null, true),
            new Finding('acme/stale', '3.0.0', Verdict::STALE, [new Signal('S2', 'warn', 'last release 2022-05-20')], ['a/parent', 'acme/stale'], null, $at),
            new Finding('acme/fine', '4.0.0', Verdict::OK, [], ['acme/fine'], null, $at),
        ], [], $at, 5, 0, false);
    }

    /**
     * SARIF 2.1.0 §3.27.20: `rank` is a number from 0.0 to 100.0. The five priority levels map onto
     * it in even steps of 25, so a consumer that sorts by rank sees the same order the report does.
     */
    public function testResultRankFollowsThePriority(): void
    {
        $sarif = $this->formatter(LockrotConfig::FAIL_ON_NONE, null)->format($this->priorityReport(), true);
        $this->assertValidSarif($sarif);
        $run = $this->singleRun($sarif);

        self::assertSame(['acme/abandoned', 'acme/transitive', 'acme/dev-only', 'acme/stale', 'acme/fine'], self::propertyColumn($run, 'package'));
        self::assertSame([100.0, 75.0, 50.0, 25.0, 0.0], JsonPath::column($run, ['results'], 'rank'));

        // A whole number is still a JSON float here: SARIF types rank as a number, and an integer
        // 100 would read as a different type to a strict consumer.
        self::assertStringContainsString('"rank": 100.0', $sarif);
        self::assertStringContainsString('"rank": 75.0', $sarif);
        self::assertStringContainsString('"rank": 0.0', $sarif);
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
     * A checkout directory may legally contain characters a URI cannot carry raw. The bundled
     * validator's `uri-reference` format check is lenient enough to accept a raw space, so this
     * asserts the encoding directly rather than relying on schema validation to catch it.
     */
    public function testDirectoryUriIsPercentEncoded(): void
    {
        $dir = sys_get_temp_dir().'/lockrot sarif #uri-'.uniqid('', true);
        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create temp dir: '.$dir);
        }
        $this->tempDirs[] = $dir;
        file_put_contents($dir.'/composer.lock', '{"packages":[]}');

        $at = new \DateTimeImmutable(self::AT);
        $sarif = $this->formatter(LockrotConfig::FAIL_ON_NONE, $dir.'/composer.lock')
            ->format(new Report([], [], $at, 0, 0, false));
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

    public function testEveryVerdictProducesAValidDocumentWithItsOwnRule(): void
    {
        $sarif = $this->formatter(Verdict::STALE, $this->lockPath())->format($this->allVerdictsReport(), true);
        $this->assertValidSarif($sarif);

        $expected = [];
        foreach (Verdict::all() as $verdict) {
            $expected[] = 'lockrot/'.$verdict;
        }
        self::assertSame($expected, JsonPath::column($this->singleRun($sarif), ['tool', 'driver', 'rules'], 'id'));
    }

    private function allVerdictsReport(): Report
    {
        $at = new \DateTimeImmutable(self::AT);
        $findings = [];
        foreach (Verdict::all() as $index => $verdict) {
            $findings[] = new Finding('acme/pkg'.$index, '1.0.'.$index, $verdict, [new Signal('S2', 'warn', 'last release 2015-11-16')], ['acme/pkg'.$index], null, $at);
        }

        return new Report($findings, [], $at, \count($findings), 0, false);
    }

    public function testFactory(): void
    {
        self::assertInstanceOf(
            SarifFormatter::class,
            Formatters::for('sarif', FormatContext::create(null, LockrotConfig::FAIL_ON_NONE))
        );
    }

    /**
     * The four-finding report compared against a baseline that knows acme/abandoned as it is,
     * acme/silent as only stale (worsened now) and nothing about acme/also-abandoned (new).
     */
    private function baselinedReport(): Report
    {
        $report = $this->report();
        $names = [];
        foreach ($report->findings() as $finding) {
            $names[] = $finding->package();
        }
        $baseline = Baseline::of([
            new BaselineEntry('acme/abandoned', '1.0.0', Verdict::ABANDONED, '2026-01-15'),
            new BaselineEntry('acme/silent', '2.0.8', Verdict::STALE, '2026-01-15'),
        ], self::AT);

        return $report->withBaseline(BaselineComparison::compare($baseline, $report, 'lockrot-baseline.json', $names));
    }

    public function testBaselineStatusIsCarriedAsAResultProperty(): void
    {
        $run = $this->singleRun($this->formatter(Verdict::SILENT, $this->lockPath())->format($this->baselinedReport()));
        $statuses = [];
        foreach (JsonPath::arrayAt($run, ['results']) as $result) {
            self::assertIsArray($result);
            $statuses[JsonPath::stringAt($result, ['properties', 'package'])] = JsonPath::stringAt($result, ['properties', 'baseline']);
        }

        self::assertSame([
            'acme/abandoned' => 'known',
            'acme/also-abandoned' => 'new',
            'acme/silent' => 'worsened',
        ], $statuses);
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

    public function testABaselinedReportIsStillValidSarif(): void
    {
        $this->assertValidSarif($this->formatter(Verdict::SILENT, $this->lockPath())->format($this->baselinedReport()));
    }

    public function testBaselineStaleEntriesBecomeAToolExecutionNotification(): void
    {
        $report = $this->report();
        $baseline = Baseline::of([
            new BaselineEntry('acme/departed', '1.0.0', Verdict::ABANDONED, '2026-01-15'),
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
     * One string member of every result's property bag, in the order the results are in.
     *
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
            new Finding('acme/leaf', '1.0.0', Verdict::STALE, [], ['a/parent', 'acme/leaf'], null, $at, null, false, ['a/parent', 'b/parent']),
        ], [], $at, 1, 0, false);
        $sarif = $this->formatter(LockrotConfig::FAIL_ON_NONE, null)->format($report);
        $this->assertValidSarif($sarif);

        self::assertSame(['a/parent', 'b/parent'], JsonPath::arrayAt($this->singleRun($sarif), ['results', 0, 'properties', 'direct_dependents']));
    }

    public function testS7RidesInTheMessageAndTheSignalListLikeAnyOtherSignal(): void
    {
        $at = new \DateTimeImmutable('2026-09-14T06:00:00+00:00');
        $report = new Report([
            new Finding('acme/root', '1.0.0', Verdict::STALE, [new Signal('S2', 'warn', 'old'), new Signal(Signal::S7, Signal::LEVEL_INFO, 'pulls in 1 flagged package: acme/leaf (stale)', ['flagged' => 1, 'packages' => []])], ['acme/root'], null, $at, null, false, ['acme/root']),
        ], [], $at, 1, 0, false);
        $sarif = $this->formatter(LockrotConfig::FAIL_ON_NONE, null)->format($report);
        $this->assertValidSarif($sarif);
        $run = $this->singleRun($sarif);

        self::assertSame('acme/root 1.0.0: old; pulls in 1 flagged package: acme/leaf (stale)', JsonPath::stringAt($run, ['results', 0, 'message', 'text']));
        self::assertSame(['S2', 'S7'], JsonPath::arrayAt($run, ['results', 0, 'properties', 'signals']));
        self::assertSame('lockrot/stale', JsonPath::stringAt($run, ['results', 0, 'ruleId']));
    }

    public function testARuleDescribesItsVerdictInOneLine(): void
    {
        $run = $this->singleRun($this->formatter(Verdict::SILENT, null)->format($this->report()));

        self::assertSame(
            'abandoned: Package is marked abandoned by its repository, or its repository is archived',
            JsonPath::stringAt($run, ['tool', 'driver', 'rules', 0, 'shortDescription', 'text'])
        );
    }

    /** Every result needs a rule for the document to validate, whatever verdict a finding carries. */
    public function testAVerdictWithoutADescriptionStillGetsAValidRule(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([new Finding('acme/odd', '1.0.0', 'unheard-of', [], ['acme/odd'], null, $at)], [], $at, 1, 0, false);

        $sarif = $this->formatter(LockrotConfig::FAIL_ON_NONE, null)->format($report, true);

        $this->assertValidSarif($sarif);
        $rule = JsonPath::arrayAt($this->singleRun($sarif), ['tool', 'driver', 'rules', 0]);
        self::assertSame('lockrot/unheard-of', $rule['id']);
        self::assertSame(['text' => 'unheard-of: Dependency rot'], $rule['shortDescription']);
        self::assertSame(['text' => 'A lockrot verdict.'], $rule['fullDescription']);
    }

    /**
     * A backslash is a legal character in a POSIX directory name; lockrot reads it as the separator
     * it is on Windows, on every platform, and never encodes a colon. The URI starts with exactly
     * three slashes: the directory's leading one is the authority/path boundary, not a segment.
     */
    public function testDirectoryUriKeepsAColonAndTreatsABackslashAsASeparator(): void
    {
        $suffix = uniqid('', true);
        $dir = sys_get_temp_dir().'/lockrot-sarif-c:\\drive-'.$suffix;
        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create temp dir: '.$dir);
        }
        $this->tempDirs[] = $dir;
        file_put_contents($dir.'/composer.lock', '{"packages":[]}');

        $at = new \DateTimeImmutable(self::AT);
        $sarif = $this->formatter(LockrotConfig::FAIL_ON_NONE, $dir.'/composer.lock')->format(new Report([], [], $at, 0, 0, false));
        $uri = JsonPath::stringAt($this->singleRun($sarif), ['originalUriBaseIds', '%SRCROOT%', 'uri']);

        self::assertStringStartsWith('file:///', $uri);
        self::assertStringStartsNotWith('file:////', $uri);
        self::assertStringEndsWith('/lockrot-sarif-c:/drive-'.$suffix.'/', $uri);
    }
}
