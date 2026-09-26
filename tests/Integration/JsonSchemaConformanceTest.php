<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Composer\Repository\AdvisoryProviderInterface;
use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;
use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analysis;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Analyzer\RunSettings;
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
use Lockrot\Html\PageData;
use Lockrot\Json\Schemas;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Output\ExplainFormatter;
use Lockrot\Output\HtmlFormatter;
use Lockrot\Output\JsonFormatter;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\ValidatesJsonSchemas;
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
            ->withRun(new RunSettings('Acme internal API', 'acme/internal-api', '8.4', '/home/someone/clients/acme/composer.lock', 'silent', new Thresholds(2, 4, 2, 4)))
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
    }

    public function testTheExplanationValidatesAgainstItsSchemaAndItsStrictTwin(): void
    {
        $analysis = self::analysis('apps/wallabag_wallabag');
        // One package per data shape: a left-behind one with advisories on it, an abandoned direct
        // one with an archived repository, and one the repository does not list at all.
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
        $this->assertValid(Schemas::REPORT, $json, 'a lock without its composer.json');
        $this->assertValid(Schemas::REPORT, $json, 'a lock without its composer.json', true);
        $run = JsonPath::arrayAt($decoded, ['run']);
        self::assertArrayHasKey('root_package', $run);
        self::assertNull($run['root_package']);
        self::assertNull($run['project']);

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
