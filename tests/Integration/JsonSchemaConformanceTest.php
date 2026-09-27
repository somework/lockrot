<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Composer\Repository\AdvisoryProviderInterface;
use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;
use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analysis;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Analyzer\Libyears;
use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunSettings;
use Lockrot\Analyzer\TransitiveExposure;
use Lockrot\Baseline\Baseline;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Baseline\BaselineFile;
use Lockrot\Clock;
use Lockrot\Data\Advisory\RepositoryAdvisoryLoader;
use Lockrot\Data\Forge\ActivityClient;
use Lockrot\Data\Forge\ActivityFetchPlanner;
use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\RepoLocator;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Data\Http\RecordedHttpClient;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\RepositoryMetadataLoader;
use Lockrot\Explain\Explanation;
use Lockrot\Graph\DependencyGraph;
use Lockrot\Html\PageData;
use Lockrot\Json\Schemas;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Output\ExplainFormatter;
use Lockrot\Output\HtmlFormatter;
use Lockrot\Output\JsonFormatter;
use Lockrot\Signal\PhpFloor;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\ValidatesJsonSchemas;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use Lockrot\Verdict\VerdictEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The published schemas under resources/ describe what the formatters really write. Every document
 * lockrot emits for a machine — the `--format=json` report, the `--explain` document, the baseline
 * file — is produced here from recorded fixtures and validated against its schema twice: as
 * published (objects open, so a consumer's older copy keeps validating), and against a strict
 * twin with `additionalProperties: false` on every declared object, so a field a formatter gains
 * without the schema learning it fails this test rather than reaching a user undocumented. The
 * JSON samples in docs/ are validated the same way, so the docs cannot drift either.
 */
final class JsonSchemaConformanceTest extends TestCase
{
    use ValidatesJsonSchemas;

    private const FIXTURES = __DIR__.'/../fixtures/';
    private const DOCS = __DIR__.'/../../docs/';
    private const NOW = '2026-09-14T00:00:00+00:00';
    /** A value for {@see withRulerzS6()} that removes the key. */
    private const ABSENT = "\0absent";
    /** wallabag carries S1–S8 (left-behind rows included), matomo S3/S4 on live forges, laravel a clean lock. */
    private const DIRS = ['apps/wallabag_wallabag', 'apps/matomo-org_matomo', 'skeletons/laravel'];

    private static ?FixtureRepositoryServer $server = null;
    /** @var array<string, Analysis> by fixture dir */
    private static array $analyses = [];

    public static function setUpBeforeClass(): void
    {
        $lockFiles = array_map(static fn (string $dir): string => self::FIXTURES.$dir.'/composer.lock', self::DIRS);
        self::$server = FixtureRepositoryServer::fromLockFiles($lockFiles);
        // S9 needs an advisory on an installed version: doctrine/cache 2.2.0 is in wallabag's lock.
        self::$server->withSecurityAdvisories([
            'doctrine/cache' => [[
                'advisoryId' => 'PKSA-cache-1',
                'packageName' => 'doctrine/cache',
                'remoteId' => 'PKSA-cache-1',
                'cve' => 'CVE-2024-0001',
                'title' => 'Cache poisoning',
                'link' => 'https://example.test/PKSA-cache-1',
                'affectedVersions' => '>=2.0,<2.3',
                'sources' => [['name' => 'FriendsOfPHP/security-advisories', 'remoteId' => 'PKSA-cache-1']],
                'reportedAt' => '2024-03-01 12:00:00',
                'severity' => 'high',
            ]],
        ]);
        self::$server->start();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            self::$server->stop();
            self::$server = null;
        }
        self::$analyses = [];
    }

    private static function analysis(string $dir): Analysis
    {
        if (isset(self::$analyses[$dir])) {
            return self::$analyses[$dir];
        }
        $lock = LockFile::fromFile(self::FIXTURES.$dir.'/composer.lock');
        $project = ProjectConfig::fromFile(self::FIXTURES.$dir.'/composer.json');

        return self::$analyses[$dir] = self::analyzer()->analyzeWithFacts($lock->packages(false), $lock, $project, false);
    }

    private static function analyzer(): Analyzer
    {
        $server = self::$server;
        self::assertNotNull($server);
        $clock = Clock::fixed(self::NOW);
        $auth = ForgeAuth::withTokens(new Tokens('recorded', null));

        return new Analyzer(
            new RepositoryMetadataLoader($server->repositories(), $clock),
            new ActivityClient(new RecordedHttpClient(self::FIXTURES.'http/github'), $auth),
            new ActivityFetchPlanner($auth),
            new RepoLocator(),
            BuiltinAllowlist::load(),
            SignalSet::default($clock, new Thresholds(), '8.4', PhpReleaseDates::load()),
            new VerdictEngine(),
            $clock,
            false,
            new RepositoryAdvisoryLoader($server->repositories())
        );
    }

    /** @return iterable<string, array{string}> */
    public static function fixtureDirs(): iterable
    {
        foreach (self::DIRS as $dir) {
            yield $dir => [$dir];
        }
    }

    /**
     * @dataProvider fixtureDirs
     */
    #[DataProvider('fixtureDirs')]
    public function testTheReportValidatesAgainstItsSchemaAndItsStrictTwin(string $dir): void
    {
        $json = (new JsonFormatter())->format(self::analysis($dir)->report());

        self::assertStringStartsWith("{\n    \"\$schema\": \"https://lockrot.dev/schema/report-1.json\",\n", $json);
        $this->assertValid(Schemas::REPORT, $json, $dir);
        $this->assertValid(Schemas::REPORT, $json, $dir, true);
    }

    /**
     * `--format=html` embeds the same document the JSON format writes, so a consumer who pulls the
     * payload out of the page gets something the published schema describes. The page also has to
     * survive being a page: nothing fetched, and no string able to close the script it sits in.
     *
     * @dataProvider fixtureDirs
     */
    #[DataProvider('fixtureDirs')]
    public function testTheHtmlPageEmbedsADocumentThatValidates(string $dir): void
    {
        $analysis = self::analysis($dir);
        $page = (new HtmlFormatter(new PageData($analysis, new Thresholds(), '8.4')))->format($analysis->report());

        $matched = preg_match('{<script id="lockrot-data" type="application/json">(.*?)</script>}s', $page, $m);
        self::assertSame(1, $matched, $dir.' carries exactly one payload');
        $payload = json_decode($m[1]);
        self::assertInstanceOf(\stdClass::class, $payload, $dir.': '.json_last_error_msg());

        $report = json_encode($payload->report, \JSON_THROW_ON_ERROR);
        $this->assertValid(Schemas::REPORT, $report, $dir.' (html payload)');
        $this->assertValid(Schemas::REPORT, $report, $dir.' (html payload)', true);

        self::assertNotSame([], (array) $payload->details, $dir.' explains the packages it flagged');
        self::assertDoesNotMatchRegularExpression('{<script[^>]+src=}i', $page, $dir.' fetches no script');
        self::assertDoesNotMatchRegularExpression('{<link[^>]+rel="stylesheet"}i', $page, $dir.' fetches no stylesheet');
    }

    /**
     * The two blocks a report only carries once something has filled them in.
     *
     * The conformance run above builds its reports straight from the analyzer, so `run` is null and
     * no finding has a baseline standing — valid, and proving nothing about the shape of either.
     * This fills both and validates against the strict twin, where an undeclared key fails.
     */
    public function testAReportThatKnowsItsRunAndItsBaselineValidatesToo(): void
    {
        $report = self::analysis('apps/wallabag_wallabag')->report();
        $previous = Baseline::fromReport($report);
        $report = $report
            ->withRun(new RunSettings('Acme internal API', 'acme/internal-api', '8.4', '/home/someone/clients/acme/composer.lock', 'silent', new Thresholds(2, 4, 2, 4), '>=8.2'))
            ->withBaseline(BaselineComparison::compare($previous, $report, 'lockrot-baseline.json', []));

        $json = (new JsonFormatter())->format($report);

        $this->assertValid(Schemas::REPORT, $json, 'a report that knows its run');
        $this->assertValid(Schemas::REPORT, $json, 'a report that knows its run', true);

        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);
        $run = JsonPath::arrayAt($decoded, ['run']);
        self::assertSame('8.4', $run['target_php']);
        // A display name, not a vendor/name: `extra.lockrot.project` exists precisely so a project
        // can be called something that is not its package name, and typing the field as one made
        // the published schema reject the value the documentation recommends.
        self::assertSame('Acme internal API', $run['project']);
        // What Composer calls the project, beside what the report calls it: the manifest's own
        // name, whatever extra.lockrot.project says.
        self::assertSame('acme/internal-api', $run['root_package']);
        // The project's own require.php, the second floor S8 holds a branch against.
        self::assertSame('>=8.2', $run['project_php']);
        self::assertSame('composer.lock', $run['lock_file'], 'the lock is named, never located');
        self::assertStringNotContainsString('/home/someone', $json, 'and no path reaches the document');
        self::assertSame(2, JsonPath::arrayAt($decoded, ['run', 'thresholds'])['release-warn-years']);
        self::assertNotContains('unknown', JsonPath::arrayAt($decoded, ['run', 'flagged_verdicts']));

        $standings = [];
        foreach (array_keys(JsonPath::arrayAt($decoded, ['findings'])) as $at) {
            $finding = JsonPath::arrayAt($decoded, ['findings', $at]);
            if (\is_array($finding['baseline'] ?? null)) {
                $standings[JsonPath::stringAt($decoded, ['findings', $at, 'baseline', 'status'])] = true;
            }
        }
        self::assertNotSame([], $standings, 'the findings carry their standing, not just the totals');
        self::assertSame(['known'], array_keys($standings), 'a baseline written from this very report knows all of them');
    }

    /**
     * `unattributed` from a real dependency graph, through the formatter, against the strict twin.
     *
     * No recorded fixture reaches a flagged package from more than eight direct requirements
     * (wallabag's widest is eight), so their documents all carry `unattributed: []` and the items
     * schema is never applied. Here nine roots require one stale package, the first of them also a
     * stale leaf of its own: the shared package is listed with its fan-in, the leaf is exposure.
     */
    public function testAReportWithUnattributedPackagesValidates(): void
    {
        $roots = [];
        $packages = [['name' => 'vendor/shared', 'version' => '1.0.0'], ['name' => 'vendor/leaf', 'version' => '1.0.0']];
        for ($i = 1; $i <= 9; ++$i) {
            $root = \sprintf('root/r%02d', $i);
            $roots[$root] = '^1';
            $packages[] = ['name' => $root, 'version' => '1.0.0', 'require' => $i === 1 ? ['vendor/shared' => '^1', 'vendor/leaf' => '^1'] : ['vendor/shared' => '^1']];
        }
        $graph = DependencyGraph::fromLock(LockFile::fromArray(['packages' => $packages]), ProjectConfig::fromArray(['require' => $roots]), false);
        $at = new \DateTimeImmutable(self::NOW);
        $findings = [];
        foreach (array_merge(['vendor/shared', 'vendor/leaf'], array_keys($roots)) as $package) {
            $verdict = strpos($package, 'vendor/') === 0 ? Verdict::STALE : Verdict::OK;
            $findings[] = new Finding($package, '1.0.0', $verdict, [], $graph->shortestChain($package), null, $at, null, false, array_keys($graph->chainsTo($package)));
        }
        $report = new Report(TransitiveExposure::attach($findings, $graph), [], $at, \count($findings), 0, false);

        $json = (new JsonFormatter())->format($report);
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);
        self::assertSame([['package' => 'vendor/shared', 'verdict' => 'stale', 'fan_in' => 9]], $decoded['unattributed']);
        self::assertSame(['max_fan_in' => 8], $decoded['exposure_rule']);
        self::assertSame([['package' => 'root/r01', 'flagged' => 1]], $decoded['exposure']);
        $this->assertValid(Schemas::REPORT, $json, 'a report with an unattributed package');
        $this->assertValid(Schemas::REPORT, $json, 'a report with an unattributed package', true);

        // And the strict twin does close the items: a renamed member fails.
        $renamed = json_decode($json);
        self::assertInstanceOf(\stdClass::class, $renamed);
        self::assertIsArray($renamed->unattributed);
        $item = $renamed->unattributed[0];
        self::assertInstanceOf(\stdClass::class, $item);
        $item->fanin = $item->fan_in;
        unset($item->fan_in);
        self::assertNotSame([], $this->errors(Schemas::REPORT, (string) json_encode($renamed), true));
    }

    /**
     * The per-signal `data` branches are only tested if every signal really occurs in the fixtures.
     * S9 needs Composer's advisory API, which the 2.2 LTS does not have; there the run carries no
     * advisory and the S9 branch of the schema goes untested, as it does for a user on that LTS.
     */
    public function testTheFixturesExerciseEverySignal(): void
    {
        $seen = [];
        foreach (self::DIRS as $dir) {
            foreach (self::analysis($dir)->report()->findings() as $finding) {
                foreach ($finding->signals() as $signal) {
                    $seen[$signal->id()] = true;
                }
            }
        }
        uksort($seen, 'strnatcmp');

        $expected = [Signal::S1, Signal::S2, Signal::S3, Signal::S4, Signal::S5, Signal::S6, Signal::S7, Signal::S8];
        if (interface_exists(AdvisoryProviderInterface::class)) {
            $expected[] = Signal::S9;
        }
        // S10 rides on the fixtures that carry an undated newest release — symfony/polyfill-* in
        // wallabag's lock, whose tags are cut by a monorepo and share their commit.
        $expected[] = Signal::S10;

        self::assertSame($expected, array_keys($seen));
    }

    /** A signal's `data` must match its own branch, not just any branch: a wrong id/data pair fails. */
    public function testASignalWhoseDataBelongsToAnotherSignalIsRejected(): void
    {
        $json = (new JsonFormatter())->format(self::analysis('apps/wallabag_wallabag')->report());
        $document = json_decode($json);
        self::assertInstanceOf(\stdClass::class, $document);
        self::assertIsArray($document->findings);
        $relabelled = false;
        foreach ($document->findings as $finding) {
            self::assertInstanceOf(\stdClass::class, $finding);
            self::assertIsArray($finding->signals);
            foreach ($finding->signals as $signal) {
                self::assertInstanceOf(\stdClass::class, $signal);
                if ($signal->id === Signal::S2) {
                    $signal->id = Signal::S3;
                    $relabelled = true;
                    break 2;
                }
            }
        }
        self::assertTrue($relabelled, 'an S2 signal to relabel');

        self::assertNotSame([], $this->errors(Schemas::REPORT, (string) json_encode($document), false));
        self::assertNotSame([], $this->errors(Schemas::REPORT, (string) json_encode($document), true));
    }

    /**
     * The sets that grow in minor releases are open strings in the published schema: a signal id, an
     * S10 check or reason and an S8 floor source a later release adds validate against a copy taken
     * now, and so do the `<vendor>:<name>` names reserved for signals that do not come from lockrot.
     * A signal whose id the schema does not list carries any object as its data.
     */
    public function testValuesALaterReleaseOrAnExtensionAddsValidateAgainstThePublishedSchema(): void
    {
        $json = (string) json_encode(self::withNewValues(self::wallabagReport()));

        self::assertSame([], $this->errors(Schemas::REPORT, $json, false));
        self::assertNotSame([], $this->errors(Schemas::REPORT, $json, true), 'the strict twin holds them to the values it knows');
    }

    /** @return iterable<string, array{string, string}> a typo in a value the schema knows, and the property that names it */
    public static function typosInKnownValues(): iterable
    {
        yield 'an S10 reason' => ['reason', 'reason'];
        yield 'an S10 check' => ['check', 'check'];
        yield 'an S10 block' => ['block', 'blocks'];
        yield 'an S8 floor source' => ['floor_source', 'floor_source'];
        yield 'a signal id' => ['id', 'id'];
    }

    /**
     * A mistyped value that still fits the open pattern passes the published schema, which cannot
     * tell it from a value a later release adds; the strict twin reads `x-known-values` as the enum
     * and rejects it, which is how lockrot's own output is held to the values it documents.
     *
     * @dataProvider typosInKnownValues
     */
    #[DataProvider('typosInKnownValues')]
    public function testTheStrictTwinRejectsATypoInAKnownValue(string $typo, string $property): void
    {
        $document = self::wallabagReport();
        $s10 = self::firstSignal($document, Signal::S10);
        $entry = self::firstUnchecked($s10);
        switch ($typo) {
            case 'reason':
                $entry->reason = 'no_tokn';

                break;
            case 'check':
                $entry->check = 'release_date';

                break;
            case 'block':
                $entry->blocks = array_merge(self::items($entry->blocks), ['S11']);

                break;
            case 'floor_source':
                self::dataOf(self::firstLeftBehind($document))->floor_source = 'projct';

                break;
            default:
                $s10->id = 'S99';
                $s10->data = new \stdClass();
        }
        $json = (string) json_encode($document);

        self::assertSame([], $this->errors(Schemas::REPORT, $json, false), 'the published schema cannot tell a typo from a new value');
        $errors = $this->errors(Schemas::REPORT, $json, true);
        self::assertNotSame([], $errors);
        self::assertNotSame([], array_filter($errors, static fn (string $error): bool => strpos($error, $property) !== false), implode("\n", $errors));
    }

    /** @return iterable<string, array{string}> a name outside the rule docs/compatibility.md states for a signal id */
    public static function namesThatAreNotSignalIds(): iterable
    {
        yield 'an upper-case vendor' => ['Acme:licence'];
        yield 'an upper-case name' => ['acme:Licence'];
        yield 'a vendor that starts with a dash' => ['-acme:licence'];
        yield 'a name that starts with a dot' => ['acme:.licence'];
        yield 'a character outside the set' => ['acme:lic ence'];
        yield 'two colons' => ['acme:lint:licence'];
        yield 'no vendor' => [':licence'];
        yield 'no name' => ['acme:'];
        yield 'a lower-case s' => ['s99'];
        yield 'signal zero' => ['S0'];
        yield 'a leading zero' => ['S01'];
    }

    /**
     * The published schema is open to new ids but not to any string: a signal or a block named
     * outside `S<n>` and the `<vendor>:<name>` rule docs/compatibility.md states — lower-case letters,
     * digits, `_`, `.` and `-`, starting with a letter or a digit — is rejected, so the documented rule
     * and the pattern cannot drift apart.
     *
     * @dataProvider namesThatAreNotSignalIds
     */
    #[DataProvider('namesThatAreNotSignalIds')]
    public function testThePublishedSchemaRejectsASignalIdOutsideTheDocumentedRule(string $id): void
    {
        $asSignal = self::wallabagReport();
        $finding = self::object(self::items($asSignal->findings)[0]);
        $finding->signals = array_merge(self::items($finding->signals), [(object) ['id' => $id, 'level' => 'info', 'summary' => 'x', 'data' => new \stdClass()]]);
        $asBlock = self::wallabagReport();
        $entry = self::firstUnchecked(self::firstSignal($asBlock, Signal::S10));
        $entry->blocks = array_merge(self::items($entry->blocks), [$id]);

        self::assertNotSame([], $this->errors(Schemas::REPORT, (string) json_encode($asSignal), false), 'as a signal id');
        self::assertNotSame([], $this->errors(Schemas::REPORT, (string) json_encode($asBlock), false), 'as a block');
    }

    /**
     * The generic branch is for ids the schema does not list: a listed id cannot shed its typed data
     * by taking it, whether its data is empty or another signal's.
     */
    public function testAKnownIdCannotBorrowTheBranchForUnknownIds(): void
    {
        $document = self::wallabagReport();
        self::firstSignal($document, Signal::S2)->data = new \stdClass();
        $json = (string) json_encode($document);

        self::assertNotSame([], $this->errors(Schemas::REPORT, $json, false));
        self::assertNotSame([], $this->errors(Schemas::REPORT, $json, true));
    }

    public function testTheExplanationAcceptsASignalIdALaterReleaseOrAnExtensionAdds(): void
    {
        $analysis = self::analysis('apps/wallabag_wallabag');
        $finding = $analysis->finding('doctrine/cache');
        $facts = $analysis->facts('doctrine/cache');
        self::assertNotNull($finding);
        self::assertNotNull($facts);
        $document = self::object(json_decode((new ExplainFormatter())->json(new Explanation($finding, $facts, new Thresholds(), '8.4', $analysis->report()))));
        $explained = self::object($document->finding);
        $explained->signals = array_merge(self::items($explained->signals), self::signalsNoLockrotWrote());
        $json = (string) json_encode($document);

        self::assertSame([], $this->errors(Schemas::EXPLAIN, $json, false));
        self::assertNotSame([], $this->errors(Schemas::EXPLAIN, $json, true));
    }

    /**
     * A branch row's `php_blocked_by` and `misses_*_php` are open sets: a value a later release adds
     * validates against the explain schema this release publishes, and the strict twin, which reads
     * `x-known-values` as the enum, rejects it. The values lockrot writes, and null, pass both.
     *
     * @param list<?string> $known
     *
     * @dataProvider openRowFields
     */
    #[DataProvider('openRowFields')]
    public function testAnOpenBranchRowFieldIsOpenToALaterValue(string $field, array $known): void
    {
        $analysis = self::analysis('apps/wallabag_wallabag');
        $finding = $analysis->finding('doctrine/cache');
        $facts = $analysis->facts('doctrine/cache');
        self::assertNotNull($finding);
        self::assertNotNull($facts);
        $json = (new ExplainFormatter())->json(new Explanation($finding, $facts, new Thresholds(), '8.4', $analysis->report()));
        $withValue = static function ($value) use ($json, $field): string {
            $document = self::object(json_decode($json));
            $rows = self::items(self::object($document->metadata)->branches);
            self::assertNotSame([], $rows, 'doctrine/cache lists its branches');
            self::object($rows[0])->{$field} = $value;

            return (string) json_encode($document);
        };

        foreach (array_merge($known, [null]) as $value) {
            $this->assertValid(Schemas::EXPLAIN, $withValue($value), $field.' '.(string) $value);
            $this->assertValid(Schemas::EXPLAIN, $withValue($value), $field.' '.(string) $value, true);
        }
        $unknown = $withValue('extension');
        $this->assertValid(Schemas::EXPLAIN, $unknown, 'a value this release does not know');
        $errors = $this->errors(Schemas::EXPLAIN, $unknown, true);
        self::assertNotSame([], array_filter($errors, static fn (string $error): bool => strpos($error, $field) !== false), 'the strict twin holds '.$field.' to the values it knows: '.implode("\n", $errors));
        foreach (['', 'Project', 'needs-newer', 1] as $wrong) {
            self::assertNotSame([], $this->errors(Schemas::EXPLAIN, $withValue($wrong), false), 'not a '.$field.': '.json_encode($wrong));
        }
    }

    /** @return iterable<string, array{string, list<?string>}> */
    public static function openRowFields(): iterable
    {
        $sides = [PhpFloor::NEEDS_NEWER, PhpFloor::STOPS_BEFORE, PhpFloor::SKIPS, PhpFloor::UNSATISFIABLE];
        yield 'php_blocked_by' => ['php_blocked_by', [PhpFloor::PROJECT, PhpFloor::TARGET]];
        yield 'misses_target_php' => ['misses_target_php', $sides];
        yield 'misses_project_php' => ['misses_project_php', $sides];
    }

    /**
     * The published configuration schema accepts a format a later release adds, and a vendor's, so an
     * editor holding an older copy does not flag it; the strict reading, which is what lockrot
     * validates `extra.lockrot` with, does not.
     */
    public function testThePublishedConfigSchemaAcceptsAFormatALaterReleaseOrAnExtensionAdds(): void
    {
        foreach (['csv', 'acme:csv'] as $format) {
            $json = (string) json_encode(['format' => $format]);

            self::assertSame([], $this->errors(Schemas::CONFIG, $json, false), $format);
            self::assertNotSame([], $this->errors(Schemas::CONFIG, $json, true), $format);
        }
        foreach (['', 'CSV', 'a:b:c', ':csv', 'Acme:csv', 'acme:CSV', 'acme:', '-csv'] as $format) {
            self::assertNotSame([], $this->errors(Schemas::CONFIG, (string) json_encode(['format' => $format]), false), 'not a format name: '.$format);
        }
    }

    /** wallabag's report, decoded to objects. */
    private static function wallabagReport(): \stdClass
    {
        return self::object(json_decode((new JsonFormatter())->format(self::analysis('apps/wallabag_wallabag')->report())));
    }

    /**
     * The document with a value in every open set that no lockrot has written: a signal S99 and a
     * signal acme:licence with data of their own, an S10 check and reason, both blocking those two,
     * and an S8 floor source.
     */
    private static function withNewValues(\stdClass $document): \stdClass
    {
        $s10 = self::firstSignal($document, Signal::S10);
        $entry = self::firstUnchecked($s10);
        $entry->check = 'license_scan';
        $entry->reason = 'quota_exhausted';
        $entry->blocks = array_merge(self::items($entry->blocks), ['S99', 'acme:licence']);
        $data = self::dataOf($s10);
        $data->blocks = array_merge(self::items($data->blocks), ['S99', 'acme:licence']);
        self::dataOf(self::firstLeftBehind($document))->floor_source = 'extension';
        $finding = self::object(self::items($document->findings)[0]);
        $finding->signals = array_merge(self::items($finding->signals), self::signalsNoLockrotWrote());

        return $document;
    }

    /** @return list<\stdClass> a signal with an id no lockrot wrote, and one named as a vendor's */
    private static function signalsNoLockrotWrote(): array
    {
        $signals = [];
        foreach (['S99', 'acme:licence'] as $id) {
            $signals[] = (object) ['id' => $id, 'level' => 'info', 'summary' => 'x', 'data' => (object) ['anything' => [1]]];
        }

        return $signals;
    }

    private static function firstUnchecked(\stdClass $s10): \stdClass
    {
        return self::object(self::items(self::dataOf($s10)->unchecked)[0]);
    }

    private static function dataOf(\stdClass $signal): \stdClass
    {
        return self::object($signal->data);
    }

    /** @param mixed $value */
    private static function object($value): \stdClass
    {
        self::assertInstanceOf(\stdClass::class, $value);

        return $value;
    }

    /**
     * @param mixed $value
     *
     * @return list<mixed>
     */
    private static function items($value): array
    {
        self::assertIsArray($value);

        return array_values($value);
    }

    private static function firstSignal(\stdClass $document, string $id): \stdClass
    {
        foreach (self::signals($document) as $signal) {
            if ($signal->id === $id) {
                return $signal;
            }
        }
        self::fail('no '.$id.' in the document');
    }

    /** An S8 that carries floor_source, null here: every newest branch is within reach of PHP 8.4. */
    private static function firstLeftBehind(\stdClass $document): \stdClass
    {
        foreach (self::signals($document) as $signal) {
            if ($signal->id === Signal::S8 && property_exists(self::dataOf($signal), 'floor_source')) {
                return $signal;
            }
        }
        self::fail('no S8 with a floor source in the document');
    }

    /** @return list<\stdClass> */
    private static function signals(\stdClass $document): array
    {
        $signals = [];
        foreach (self::items($document->findings) as $finding) {
            foreach (self::items(self::object($finding)->signals) as $signal) {
                $signals[] = self::object($signal);
            }
        }

        return $signals;
    }

    /**
     * S6's data is typed, and its `reason` is an open set: a value a later release adds validates
     * against the schema this one publishes, as a consumer holds it. The strict twin is this
     * release's own contract and reads `x-known-values` as the enum, so it takes the reasons this
     * release lists and rejects any other. A wrong type on any of the new fields fails either way.
     */
    public function testS6DataIsTypedButItsReasonIsOpen(): void
    {
        $json = (new JsonFormatter())->format(self::analysis('apps/wallabag_wallabag')->report());
        $unknown = self::withRulerzS6($json, ['reason' => 'something_new']);
        $this->assertValid(Schemas::REPORT, $unknown, 'a reason this release does not know');
        $errors = $this->errors(Schemas::REPORT, $unknown, true);
        self::assertNotSame([], array_filter($errors, static fn (string $error): bool => strpos($error, 'reason') !== false), 'the strict twin holds reason to the values it knows: '.implode("\n", $errors));
        $valid = [
            'a snapshot' => ['reason' => 'branch_snapshot'],
            'a release with no tag in its repository' => ['reason' => 'no_stable_release'],
            'no metadata, so nothing known' => ['has_stable_release' => null],
            'a tagged snapshot' => ['has_stable_release' => true, 'last_stable_release' => '2019-01-23T15:23:04+00:00', 'last_stable_version' => '1.6.2', 'last_stable_dated_by' => 'vendor/monorepo'],
            'a document written before 0.13.0' => array_fill_keys(['reason', 'has_stable_release', 'last_stable_release', 'last_stable_version', 'last_stable_dated_by', 'snapshot_time'], self::ABSENT),
        ];
        foreach ($valid as $what => $change) {
            $document = self::withRulerzS6($json, $change);
            $this->assertValid(Schemas::REPORT, $document, $what);
            $this->assertValid(Schemas::REPORT, $document, $what, true);
        }
        $invalid = [
            'has_stable_release as a word' => ['has_stable_release' => 'no'],
            'snapshot_time that is no date' => ['snapshot_time' => 'yesterday'],
            'last_stable_release that is no date' => ['last_stable_release' => 'long ago'],
            'last_stable_version as a number' => ['last_stable_version' => 1],
            'last_stable_dated_by that is no package' => ['last_stable_dated_by' => 'polyfill'],
            'reason as a number' => ['reason' => 6],
            'reason in capitals' => ['reason' => 'Branch snapshot'],
            'reason empty' => ['reason' => ''],
        ];
        foreach ($invalid as $what => $change) {
            self::assertNotSame([], $this->errors(Schemas::REPORT, self::withRulerzS6($json, $change), false), $what);
        }
    }

    /**
     * `from_composer_repository` is a boolean on every finding lockrot writes from 0.13.0 on, and
     * optional in both schemas, so a 0.12 finding without it still validates. Null and a word do not.
     */
    public function testAFindingSaysWhetherARepositoryWasAskedAboutItAsABoolean(): void
    {
        $analysis = self::analysis('apps/wallabag_wallabag');
        $finding = $analysis->finding('doctrine/cache');
        $facts = $analysis->facts('doctrine/cache');
        self::assertNotNull($finding);
        self::assertNotNull($facts);
        $documents = [
            Schemas::REPORT => [(new JsonFormatter())->format($analysis->report()), ['findings', 0]],
            Schemas::EXPLAIN => [(new ExplainFormatter())->json(new Explanation($finding, $facts, new Thresholds(), '8.4', $analysis->report())), ['finding']],
        ];
        foreach ($documents as $schema => [$json, $path]) {
            $decoded = json_decode($json, true);
            self::assertIsArray($decoded);
            self::assertTrue(JsonPath::arrayAt($decoded, $path)['from_composer_repository'], $schema.': wallabag\'s packages all come from Packagist');
            foreach ([true, false, self::ABSENT] as $value) {
                $what = $schema.' '.var_export($value, true);
                $changed = self::withFindingKey($decoded, $path, 'from_composer_repository', $value);
                $this->assertValid($schema, $changed, $what);
                $this->assertValid($schema, $changed, $what, true);
            }
            foreach ([null, 'yes', 1] as $value) {
                self::assertNotSame([], $this->errors($schema, self::withFindingKey($decoded, $path, 'from_composer_repository', $value), false), $schema.' '.var_export($value, true));
            }
        }
    }

    /**
     * `libyears_unmeasured` is an open set in both schemas: a reason a later release adds, and null,
     * validate against the published schema, and the strict twin holds the value to the reasons this
     * release writes. A 0.12 finding without the key validates against both.
     */
    public function testAFindingSaysWhyItsLibyearsAreNullAsAnOpenCode(): void
    {
        $analysis = self::analysis('apps/wallabag_wallabag');
        $finding = $analysis->finding('wallabag/rulerz');
        $facts = $analysis->facts('wallabag/rulerz');
        self::assertNotNull($finding);
        self::assertNotNull($facts);
        $report = json_decode((new JsonFormatter())->format($analysis->report()), true);
        self::assertIsArray($report);
        $at = null;
        foreach (JsonPath::arrayAt($report, ['findings']) as $i => $row) {
            self::assertIsArray($row);
            if ($row['package'] === 'wallabag/rulerz') {
                $at = $i;
            }
        }
        self::assertIsInt($at);
        // The helper edits the first finding: rulerz, a dev-master pin, is moved there.
        $findings = JsonPath::arrayAt($report, ['findings']);
        $report['findings'] = array_merge([$findings[$at]], array_values(array_diff_key($findings, [$at => true])));
        $documents = [
            Schemas::REPORT => [$report, ['findings', 0]],
            Schemas::EXPLAIN => [json_decode((new ExplainFormatter())->json(new Explanation($finding, $facts, new Thresholds(), '8.4', $analysis->report())), true), ['finding']],
        ];
        foreach ($documents as $schema => [$decoded, $path]) {
            self::assertIsArray($decoded);
            self::assertSame(Libyears::BRANCH_SNAPSHOT, JsonPath::arrayAt($decoded, $path)['libyears_unmeasured'], $schema.': rulerz is a branch snapshot');
            foreach (array_merge(Libyears::REASONS, [null, self::ABSENT]) as $value) {
                $what = $schema.' '.var_export($value, true);
                $changed = self::withFindingKey($decoded, $path, 'libyears_unmeasured', $value);
                $this->assertValid($schema, $changed, $what);
                $this->assertValid($schema, $changed, $what, true);
            }
            foreach (['some_future_reason', 'branch_snapshots'] as $value) {
                $changed = self::withFindingKey($decoded, $path, 'libyears_unmeasured', $value);
                $this->assertValid($schema, $changed, $schema.' '.$value.': the published schema cannot tell a typo from a new reason');
                $errors = $this->errors($schema, $changed, true);
                self::assertNotSame([], array_filter($errors, static fn (string $error): bool => strpos($error, 'libyears_unmeasured') !== false), $schema.' '.$value.': '.implode("\n", $errors));
            }
            foreach (['Branch-Snapshot', '', 1, false] as $value) {
                self::assertNotSame([], $this->errors($schema, self::withFindingKey($decoded, $path, 'libyears_unmeasured', $value), false), $schema.' '.var_export($value, true));
            }
        }

        // The block's keys: one a later release adds is a count; a key that is not a count fails.
        $block = JsonPath::arrayAt($report, ['libyears', 'unmeasured']);
        $withKey = static function ($value) use ($report, $block): string {
            $libyears = JsonPath::arrayAt($report, ['libyears']);
            $libyears['unmeasured'] = array_merge($block, ['installed_undated' => $value]);
            $report['libyears'] = $libyears;

            return (string) json_encode($report);
        };
        $this->assertValid(Schemas::REPORT, $withKey(2), 'a reason a later release counts');
        self::assertNotSame([], $this->errors(Schemas::REPORT, $withKey('two'), false), 'a count that is a word');
        self::assertNotSame([], $this->errors(Schemas::REPORT, $withKey(-1), false), 'a negative count');
    }

    /**
     * wallabag's report with one package's finding moved first, where {@see withFindingKey()} edits,
     * and that package's `--explain` document, both decoded.
     *
     * @return array<string, array{array<mixed, mixed>, list<int|string>}>
     */
    private static function documentsFor(string $package): array
    {
        $analysis = self::analysis('apps/wallabag_wallabag');
        $finding = $analysis->finding($package);
        $facts = $analysis->facts($package);
        self::assertNotNull($finding, $package);
        self::assertNotNull($facts, $package);
        $report = json_decode((new JsonFormatter())->format($analysis->report()), true);
        self::assertIsArray($report);
        $findings = JsonPath::arrayAt($report, ['findings']);
        $at = array_search($package, array_column($findings, 'package'), true);
        self::assertIsInt($at, $package);
        $report['findings'] = array_merge([$findings[$at]], array_values(array_diff_key($findings, [$at => true])));
        $explain = json_decode((new ExplainFormatter())->json(new Explanation($finding, $facts, new Thresholds(), '8.4', $analysis->report())), true);
        self::assertIsArray($explain);

        return [Schemas::REPORT => [$report, ['findings', 0]], Schemas::EXPLAIN => [$explain, ['finding']]];
    }

    /**
     * `priority_basis` in both schemas: a step reason a later release adds validates against the
     * published schema and the strict twin holds the reason to the ones this release writes; `none`
     * is no step's `from` or `to` in either; a 0.12 finding without the key validates against both.
     */
    public function testAFindingSaysHowItsPriorityWasReached(): void
    {
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            self::markTestSkipped('Composer 2.2 has no advisory API, so doctrine/cache carries no S9.');
        }
        foreach (self::documentsFor('doctrine/cache') as $schema => [$decoded, $path]) {
            $basis = JsonPath::arrayAt($decoded, array_merge($path, ['priority_basis']));
            self::assertSame(['base' => 'critical', 'steps' => [['reason' => 'transitive', 'from' => 'critical', 'to' => 'high'], ['reason' => 'no_fix_expected', 'from' => 'high', 'to' => 'critical']]], $basis, $schema.': doctrine/cache, abandoned, transitive, one advisory no release fixes');
            $withStep = static fn (array $step): array => ['base' => 'high', 'steps' => [$step]];
            foreach ([self::ABSENT, ['base' => 'none', 'steps' => []], $withStep(['reason' => 'dev', 'from' => 'high', 'to' => 'medium'])] as $value) {
                $what = $schema.' '.json_encode($value);
                $changed = self::withFindingKey($decoded, $path, 'priority_basis', $value);
                $this->assertValid($schema, $changed, $what);
                $this->assertValid($schema, $changed, $what, true);
            }
            $unknown = self::withFindingKey($decoded, $path, 'priority_basis', $withStep(['reason' => 'vendored', 'from' => 'high', 'to' => 'medium']));
            $this->assertValid($schema, $unknown, $schema.': a step reason a later release adds');
            $typo = self::withFindingKey($decoded, $path, 'priority_basis', $withStep(['reason' => 'transitve', 'from' => 'high', 'to' => 'medium']));
            $this->assertValid($schema, $typo, $schema.': the published schema cannot tell a typo from a new reason');
            $errors = $this->errors($schema, $typo, true);
            self::assertNotSame([], array_filter($errors, static fn (string $error): bool => strpos($error, 'reason') !== false), $schema.': '.implode("\n", $errors));
            foreach ([
                'from none' => $withStep(['reason' => 'dev', 'from' => 'none', 'to' => 'low']),
                'to none' => $withStep(['reason' => 'dev', 'from' => 'low', 'to' => 'none']),
                'a step without its to' => $withStep(['reason' => 'dev', 'from' => 'high']),
                'a base outside the order' => ['base' => 'urgent', 'steps' => []],
                'no steps' => ['base' => 'high'],
                'null' => null,
                'a reason in capitals' => $withStep(['reason' => 'Dev', 'from' => 'high', 'to' => 'medium']),
            ] as $what => $value) {
                self::assertNotSame([], $this->errors($schema, self::withFindingKey($decoded, $path, 'priority_basis', $value), false), $schema.' '.$what);
            }
        }
    }

    /**
     * `no_fix_expected` in both schemas: null, an empty list and a list of `{id, reason}` validate,
     * a no-fix reason a later release adds passes the published schema and the strict twin rejects a
     * typo in one; a 0.12 finding without the key validates against both.
     */
    public function testAFindingNamesTheAdvisoriesNoFixIsExpectedFor(): void
    {
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            self::markTestSkipped('Composer 2.2 has no advisory API, so doctrine/cache carries no S9.');
        }
        foreach (self::documentsFor('doctrine/cache') as $schema => [$decoded, $path]) {
            self::assertSame([['id' => 'PKSA-cache-1', 'reason' => 'no_release_fixes']], JsonPath::arrayAt($decoded, $path)['no_fix_expected'], $schema.': 2.2.0 is the highest release, and the range covers it');
            $item = static fn (string $reason): array => [['id' => 'PKSA-cache-1', 'reason' => $reason]];
            foreach ([self::ABSENT, null, [], $item('releases_unknown'), $item('not_on_installed_branch')] as $value) {
                $what = $schema.' '.json_encode($value);
                $changed = self::withFindingKey($decoded, $path, 'no_fix_expected', $value);
                $this->assertValid($schema, $changed, $what);
                $this->assertValid($schema, $changed, $what, true);
            }
            $this->assertValid($schema, self::withFindingKey($decoded, $path, 'no_fix_expected', $item('withdrawn_upstream')), $schema.': a reason a later release adds');
            $typo = self::withFindingKey($decoded, $path, 'no_fix_expected', $item('releses_unknown'));
            $this->assertValid($schema, $typo, $schema.': the published schema cannot tell a typo from a new reason');
            $errors = $this->errors($schema, $typo, true);
            self::assertNotSame([], array_filter($errors, static fn (string $error): bool => strpos($error, 'reason') !== false), $schema.': '.implode("\n", $errors));
            foreach ([
                'an item without its reason' => [['id' => 'PKSA-cache-1']],
                'an item without its id' => [['reason' => 'no_release_fixes']],
                'a bare id' => ['PKSA-cache-1'],
                'a boolean' => true,
                'a count' => 1,
                'an empty reason' => $item(''),
            ] as $what => $value) {
                self::assertNotSame([], $this->errors($schema, self::withFindingKey($decoded, $path, 'no_fix_expected', $value), false), $schema.' '.$what);
            }
        }
    }

    /** S9's `releases_read` is an optional boolean in the report schema; the explain schema types signal data as any object. */
    public function testS9SaysWhetherTheReleasesWereRead(): void
    {
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            self::markTestSkipped('Composer 2.2 has no advisory API, so doctrine/cache carries no S9.');
        }
        [$report] = self::documentsFor('doctrine/cache')[Schemas::REPORT];
        $signals = JsonPath::arrayAt($report, ['findings', 0, 'signals']);
        $at = array_search(Signal::S9, array_column($signals, 'id'), true);
        self::assertIsInt($at, 'doctrine/cache carries S9');
        self::assertTrue(JsonPath::arrayAt($report, ['findings', 0, 'signals', $at, 'data'])['releases_read']);
        $withValue = static function ($value) use ($report, $at): string {
            $finding = JsonPath::arrayAt($report, ['findings', 0]);
            $signals = JsonPath::arrayAt($finding, ['signals']);
            $data = JsonPath::arrayAt($signals, [$at, 'data']);
            if ($value === self::ABSENT) {
                unset($data['releases_read']);
            } else {
                $data['releases_read'] = $value;
            }
            $signals[$at] = array_merge(JsonPath::arrayAt($signals, [$at]), ['data' => $data]);
            $finding['signals'] = $signals;
            $findings = JsonPath::arrayAt($report, ['findings']);
            $findings[0] = $finding;
            $report['findings'] = $findings;

            return (string) json_encode($report);
        };
        foreach ([true, false, self::ABSENT] as $value) {
            $this->assertValid(Schemas::REPORT, $withValue($value), var_export($value, true));
            $this->assertValid(Schemas::REPORT, $withValue($value), var_export($value, true), true);
        }
        foreach ([null, 'yes', 1] as $value) {
            self::assertNotSame([], $this->errors(Schemas::REPORT, $withValue($value), false), var_export($value, true));
        }
    }

    /**
     * Every key {@see Finding::toArray()} writes is a property both schemas' finding lists. The two
     * lists are not compared with each other: the report's finding also carries `baseline`.
     */
    public function testEveryKeyAFindingWritesIsListedInBothSchemas(): void
    {
        $finding = self::analysis('apps/wallabag_wallabag')->finding('doctrine/cache');
        self::assertNotNull($finding);
        foreach ([Schemas::REPORT, Schemas::EXPLAIN] as $schema) {
            $listed = array_keys(JsonPath::arrayAt(JsonPath::decodeFile(Schemas::path($schema)), ['definitions', 'finding', 'properties']));
            self::assertSame([], array_values(array_diff(array_keys($finding->toArray()), $listed)), $schema);
        }
    }

    /**
     * @param array<mixed, mixed> $document
     * @param list<int|string>    $path
     * @param mixed               $value {@see ABSENT} removes the key
     */
    private static function withFindingKey(array $document, array $path, string $key, $value): string
    {
        $finding = JsonPath::arrayAt($document, $path);
        if ($value === self::ABSENT) {
            unset($finding[$key]);
        } else {
            $finding[$key] = $value;
        }
        if ($path === ['finding']) {
            $document['finding'] = $finding;
        } else {
            $findings = JsonPath::arrayAt($document, ['findings']);
            $findings[0] = $finding;
            $document['findings'] = $findings;
        }

        return (string) json_encode($document);
    }

    /**
     * The report with wallabag/rulerz's S6 data changed: a key set to {@see ABSENT} is removed.
     *
     * @param array<string, mixed> $change
     */
    private static function withRulerzS6(string $json, array $change): string
    {
        $document = json_decode($json, true);
        self::assertIsArray($document);
        $findings = JsonPath::arrayAt($document, ['findings']);
        $changed = false;
        foreach ($findings as $at => $finding) {
            self::assertIsArray($finding);
            if ($finding['package'] !== 'wallabag/rulerz') {
                continue;
            }
            $signals = JsonPath::arrayAt($finding, ['signals']);
            foreach ($signals as $i => $signal) {
                self::assertIsArray($signal);
                if ($signal['id'] !== Signal::S6) {
                    continue;
                }
                $data = JsonPath::arrayAt($signal, ['data']);
                foreach ($change as $key => $value) {
                    if ($value === self::ABSENT) {
                        unset($data[$key]);
                    } else {
                        $data[$key] = $value;
                    }
                }
                $signal['data'] = $data;
                $signals[$i] = $signal;
                $changed = true;
            }
            $finding['signals'] = $signals;
            $findings[$at] = $finding;
        }
        self::assertTrue($changed, 'wallabag/rulerz carries S6');
        $document['findings'] = $findings;

        return (string) json_encode($document);
    }

    public function testTheExplanationValidatesAgainstItsSchemaAndItsStrictTwin(): void
    {
        $analysis = self::analysis('apps/wallabag_wallabag');
        // One package per data shape: a left-behind one with advisories on it, an abandoned direct
        // one with an archived repository, and a branch snapshot of one the repository lists
        // branches for and not a single tag.
        foreach (['doctrine/cache', 'sensio/framework-extra-bundle', 'wallabag/rulerz'] as $package) {
            $finding = $analysis->finding($package);
            $facts = $analysis->facts($package);
            self::assertNotNull($finding, $package);
            self::assertNotNull($facts, $package);
            $json = (new ExplainFormatter())->json(new Explanation($finding, $facts, new Thresholds(), '8.4', $analysis->report()));

            self::assertStringStartsWith("{\n    \"\$schema\": \"https://lockrot.dev/schema/explain-1.json\",\n", $json);
            $this->assertValid(Schemas::EXPLAIN, $json, $package);
            $this->assertValid(Schemas::EXPLAIN, $json, $package, true);
        }
    }

    public function testTheBaselineFileValidatesAgainstItsSchemaAndItsStrictTwin(): void
    {
        $dir = sys_get_temp_dir().'/lockrot-schema-'.uniqid('', true);
        mkdir($dir);
        try {
            foreach (['apps/wallabag_wallabag', 'skeletons/laravel'] as $fixture) {
                $file = BaselineFile::resolve($dir, null);
                $file->write(Baseline::fromReport(self::analysis($fixture)->report()));
                $json = (string) file_get_contents($file->path());

                self::assertStringStartsWith("{\n    \"\$schema\": \"https://lockrot.dev/schema/baseline-1.json\",\n", $json);
                $this->assertValid(Schemas::BASELINE, $json, $fixture);
                $this->assertValid(Schemas::BASELINE, $json, $fixture, true);
                // And what lockrot wrote, lockrot reads back through the same schema.
                self::assertSame(\count(self::analysis($fixture)->report()->flagged()), $file->read()->count(), $fixture);
            }
        } finally {
            array_map('unlink', glob($dir.'/*') ?: []);
            rmdir($dir);
        }
    }

    /** @return iterable<string, array{string, string}> every ```json block in docs/, with the schema it must match */
    public static function docSamples(): iterable
    {
        foreach (glob(self::DOCS.'*.md') ?: [] as $path) {
            $text = (string) file_get_contents($path);
            preg_match_all('/```json\n(.*?)\n```/s', $text, $matches);
            foreach ($matches[1] as $i => $block) {
                $document = self::documentOf($block);
                $schema = self::schemaFor($document);
                if ($schema === null) {
                    continue;
                }
                yield basename($path).' #'.($i + 1) => [$schema, $block];
            }
        }
    }

    /**
     * @dataProvider docSamples
     */
    #[DataProvider('docSamples')]
    public function testTheDocsSamplesValidate(string $schema, string $block): void
    {
        $document = self::documentOf($block);
        if ($schema === Schemas::CONFIG) {
            // composer.json samples: the schema describes extra.lockrot, not the file around it.
            $document = JsonPath::arrayAt($document, ['extra', 'lockrot']);
        }
        $json = (string) json_encode($document);

        $this->assertValid($schema, $json, 'docs sample');
        $this->assertValid($schema, $json, 'docs sample', true);
    }

    /** @return iterable<string, array{string}> */
    public static function schemaDocuments(): iterable
    {
        foreach ([Schemas::REPORT, Schemas::EXPLAIN, Schemas::BASELINE, Schemas::CONFIG] as $document) {
            yield $document => [$document];
        }
    }

    /**
     * A lock-only run: lockrot needs no composer.json ({@see ProjectConfig::fromFile()}), and
     * without one there are no direct requirements, so nothing reaches any package and every
     * finding carries an empty chain. wallabag's lock has 200 of them, and the document has to
     * validate all the same — the published schema cannot demand a chain the run cannot have.
     */
    public function testALockWithoutItsComposerJsonValidates(): void
    {
        $server = self::$server;
        self::assertNotNull($server);
        $lock = LockFile::fromFile(self::FIXTURES.'apps/wallabag_wallabag/composer.lock');
        $analysis = self::analyzer()->analyzeWithFacts($lock->packages(false), $lock, ProjectConfig::empty(), false);

        // What the command records for a lock read without its manifest: no name to display and
        // no root package, both written as null — the null branch of each has to validate too.
        $json = (new JsonFormatter())->format($analysis->report()->withRun(new RunSettings(null, null, '8.4', null, 'none', null)));

        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);
        $findings = JsonPath::arrayAt($decoded, ['findings']);
        self::assertNotSame([], $findings);
        foreach ($findings as $finding) {
            self::assertIsArray($finding);
            self::assertSame([], $finding['chain'], 'no root, so no chain reaches the package');
            self::assertFalse($finding['direct']);
        }
        // Nothing reaches any package, so nothing is attributed — and nothing is above the cap
        // either: a package no direct requirement reaches is in neither list.
        self::assertSame([], $decoded['exposure']);
        self::assertSame([], $decoded['unattributed']);
        self::assertSame(['max_fan_in' => 8], $decoded['exposure_rule']);
        $this->assertValid(Schemas::REPORT, $json, 'a lock without its composer.json');
        $this->assertValid(Schemas::REPORT, $json, 'a lock without its composer.json', true);
        $run = JsonPath::arrayAt($decoded, ['run']);
        self::assertArrayHasKey('root_package', $run);
        self::assertNull($run['root_package']);
        self::assertNull($run['project']);
        self::assertArrayHasKey('project_php', $run);
        self::assertNull($run['project_php'], 'no manifest, so no require.php');

        $first = $analysis->report()->findings()[0];
        $facts = $analysis->facts($first->package());
        self::assertNotNull($facts);
        $explanation = new Explanation($first, $facts, new Thresholds(), '8.4', $analysis->report());
        $this->assertValid(Schemas::EXPLAIN, (new ExplainFormatter())->json($explanation), 'an explanation without a chain');
    }

    /**
     * @dataProvider schemaDocuments
     */
    #[DataProvider('schemaDocuments')]
    public function testEachSchemaIsValidDraft04AndNamesItsPublishedUrl(string $document): void
    {
        $schema = self::schema($document);

        // validate() takes its subject by reference; a copy keeps $schema typed for the reads below.
        $subject = self::schema($document);
        $validator = new Validator();
        $validator->validate($subject, (object) ['$ref' => 'http://json-schema.org/draft-04/schema#'], Constraint::CHECK_MODE_VALIDATE_SCHEMA);
        self::assertTrue($validator->isValid(), $document.': '.json_encode($validator->getErrors()));

        $number = $document === Schemas::BASELINE ? Baseline::SCHEMA : JsonFormatter::SCHEMA;
        $header = get_object_vars($schema);
        self::assertSame('http://json-schema.org/draft-04/schema#', $header['$schema'] ?? null);
        self::assertSame(Schemas::url($document, $number), $header['id'] ?? null);
        self::assertSame('https://lockrot.dev/schema/'.$document.'-'.$number.'.json', $header['id'] ?? null);
    }

    /**
     * A docs sample as a decoded document. The samples in docs/ elide with `…` lines, which leave a
     * trailing comma behind; both are removed before decoding.
     *
     * @return array<string, mixed>
     */
    private static function documentOf(string $block): array
    {
        $json = (string) preg_replace('/^\s*…\s*\n/m', '', $block);
        $json = (string) preg_replace('/,(\s*[\]}])/', '$1', $json);
        $document = json_decode($json, true);
        self::assertIsArray($document, 'a docs sample decodes: '.$block);
        $typed = [];
        foreach ($document as $key => $value) {
            $typed[(string) $key] = $value;
        }

        return $typed;
    }

    /** @param array<string, mixed> $document */
    private static function schemaFor(array $document): ?string
    {
        $extra = $document['extra'] ?? null;
        if (\is_array($extra) && isset($extra['lockrot'])) {
            return Schemas::CONFIG;
        }
        if (!isset($document['lockrot'])) {
            return null;
        }
        if (isset($document['finding'], $document['lock'])) {
            return Schemas::EXPLAIN;
        }
        if (!isset($document['findings'])) {
            // An envelope-only fragment, as schema.md shows one: nothing to validate.
            return null;
        }

        $findings = $document['findings'] ?? null;

        return \is_array($findings) && $findings !== [] && array_keys($findings) === range(0, \count($findings) - 1) ? Schemas::REPORT : Schemas::BASELINE;
    }
}
