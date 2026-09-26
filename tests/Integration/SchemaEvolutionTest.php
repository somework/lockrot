<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Baseline\Baseline;
use Lockrot\Baseline\BaselineFile;
use Lockrot\Json\Schemas;
use Lockrot\Output\ExplainFormatter;
use Lockrot\Output\JsonFormatter;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\SchemaWidening;
use Lockrot\Tests\Support\ValidatesJsonSchemas;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Backward compatibility of the published schemas: under one schema number, whatever an earlier
 * release's schema accepted, the current one accepts too, and what an earlier release wrote still
 * validates.
 *
 * Two checks, on two kinds of fixture under tests/fixtures/schema-evolution/:
 *
 * - `schemas/<version>/` holds the report, explain, baseline and config schemas each release from
 *   0.9.0 on published (the first with a number in its URL). Each one under the current number must
 *   be accepted by the current file: {@see SchemaWidening} finds a member made required, a type or an
 *   enum value lost, a bound tightened, a listed property dropped or an object closed. This is the
 *   primary check: it covers every branch of the schema, whatever a recording happens to carry.
 * - `<version>/` holds what the signed release PHAR of that version wrote over wallabag's lock,
 *   recorded once by bin/record-schema-evolution: its baseline file, the `--format=json` report
 *   compared against it, and six `--explain --format=json` documents. Each is validated against the
 *   schema its `$schema` names — the current file, or for an older number the newest published copy
 *   of it — as published and against the strict twin, which also catches a field the older document
 *   carries and the current schema stopped listing. The baseline is also read back through
 *   BaselineFile, as a user's committed baseline is. This is the second, real-output check.
 *
 * This is the backward direction only. The forward one — a document the newest lockrot writes,
 * validated against a copy of the schema an older release published — does not hold: 0.10.0's
 * schemas list signal ids S1–S9 and demand a non-empty `chain`, and current documents can carry S10
 * and an empty chain.
 */
final class SchemaEvolutionTest extends TestCase
{
    use ValidatesJsonSchemas;

    private const DIR = __DIR__.'/../fixtures/schema-evolution/';
    private const SCHEMAS = self::DIR.'schemas/';
    private const CHANGELOG = __DIR__.'/../../CHANGELOG.md';

    /** Every recorded version. A version is added, never re-recorded and never dropped. */
    private const RECORDED = ['0.9.0', '0.10.0', '0.11.0'];

    /**
     * The sha256 of each recording's provenance.json, which holds the sha256 of every document next
     * to it: a document edited, with its hash in provenance.json to match, still fails here.
     */
    private const PROVENANCE = [
        '0.9.0' => '48b1b381222c097ec6ceef678eb07a999d63e0e0f3d2fbe400ea1f70d13b9ba1',
        '0.10.0' => '083f3bf484d352e1a9b2d006bbc73833447a7d71b45dd301bbbf886456197073',
        '0.11.0' => '965555027bece3c8a9352093f423d5876a1e5cca8966544f7ec9fc474c82f595',
    ];

    /** The digest GitHub lists for each release's lockrot.phar asset (`gh release view v<version> --json assets`). */
    private const RELEASE_PHAR = [
        '0.9.0' => '9c334523667627810a04d5903aec683124d2df98421d5b7ab100d40749cb8c1c',
        '0.10.0' => 'e0f7e783a1344fec6f43340f4f0712031facf7e064a4a3cec3ace98e82eb3d45',
        '0.11.0' => 'd8196dbe5b5dbe4aa8cb626f5b135e3cb0ccd8d6989b587b8fe05eee8ceb301a',
    ];

    /** The signal ids each recorded report carries, and its explanations between them, as measured on the recording. */
    private const SIGNALS = [
        '0.9.0' => [Signal::S1, Signal::S2, Signal::S3, Signal::S4, Signal::S5, Signal::S6, Signal::S7, Signal::S8, Signal::S9],
        '0.10.0' => [Signal::S1, Signal::S2, Signal::S3, Signal::S4, Signal::S5, Signal::S6, Signal::S7, Signal::S8, Signal::S9],
        '0.11.0' => [Signal::S1, Signal::S2, Signal::S3, Signal::S4, Signal::S5, Signal::S6, Signal::S7, Signal::S8, Signal::S9, Signal::S10],
    ];

    /**
     * Whether the release writes the report's `run` block and each finding's `baseline` standing,
     * both new in 0.10.0. The schema keeps them optional for 0.9.0's reports, which is what the
     * 0.9.0 recording holds it to.
     */
    private const WRITES_RUN_AND_STANDING = ['0.9.0' => false, '0.10.0' => true, '0.11.0' => true];

    /** The baseline file each recording keeps, under the name the report was given for it. */
    private const BASELINE = 'lockrot-schema-evolution-baseline.json';

    /** The first release whose schemas carry a number in their URL. */
    private const FIRST_NUMBERED = '0.9.0';

    /** The sha256 of every schema file each release published, as its tag holds it. */
    private const RELEASED_SCHEMAS = [
        '0.9.0' => [
            Schemas::REPORT => 'b08abbab7b6116551a474dcc07b6b2bf6d889da23ebf1d941b80fa2dbe60c738',
            Schemas::EXPLAIN => '72f4ef4d21c016f6b56eb4aa479c296208aeff01666f7c21843d436fd0908e27',
            Schemas::BASELINE => '4435c03d9d9927b5da58b48a4df85deaa9c8b25f88703f3c6081e63b7c2ed9f9',
            Schemas::CONFIG => '0a1a20627764525f5e78a89863bd1fdf133c9275e86ef525dd613e41f286829a',
        ],
        '0.10.0' => [
            Schemas::REPORT => '020a83517b4c4c4ee59c8e0f00d628a6d54e938d1c8b8ad784796cc5c7d85cc9',
            Schemas::EXPLAIN => '72f4ef4d21c016f6b56eb4aa479c296208aeff01666f7c21843d436fd0908e27',
            Schemas::BASELINE => '4435c03d9d9927b5da58b48a4df85deaa9c8b25f88703f3c6081e63b7c2ed9f9',
            Schemas::CONFIG => '7457dbfe567f35f13aa9f27dbdf323b507eeb344973874412f5804bc206b98c1',
        ],
        '0.11.0' => [
            Schemas::REPORT => 'b2295ceebef2c97bef526975fd43fc2d5768af0fa066192407c7e6f03873f4a6',
            Schemas::EXPLAIN => '4802ed4b9e93322a266232170a52dd04bc65d15be0afc4368704f387b8c8b1db',
            Schemas::BASELINE => '4435c03d9d9927b5da58b48a4df85deaa9c8b25f88703f3c6081e63b7c2ed9f9',
            Schemas::CONFIG => 'c643d1740ea647848a7372a3e083ce766a12a536c01470368adba273caa6843b',
        ],
        '0.12.0' => [
            Schemas::REPORT => 'b2295ceebef2c97bef526975fd43fc2d5768af0fa066192407c7e6f03873f4a6',
            Schemas::EXPLAIN => '4802ed4b9e93322a266232170a52dd04bc65d15be0afc4368704f387b8c8b1db',
            Schemas::BASELINE => '4435c03d9d9927b5da58b48a4df85deaa9c8b25f88703f3c6081e63b7c2ed9f9',
            Schemas::CONFIG => 'c643d1740ea647848a7372a3e083ce766a12a536c01470368adba273caa6843b',
        ],
        '0.13.0' => [
            Schemas::REPORT => '49f6d0fff5d51bc9204aea8729ed26592fd3a7689ddcf439dd47c16f51861563',
            Schemas::EXPLAIN => 'd2677bec65ec3179b939203b234fd310c580a2b021c96a09febc7bb56a62c05f',
            Schemas::BASELINE => '4435c03d9d9927b5da58b48a4df85deaa9c8b25f88703f3c6081e63b7c2ed9f9',
            Schemas::CONFIG => 'c53cad5ac3ec0a1fde23e8277898a7d3e4fe0918abcdd9de2b5fc5dfefdb2e0f',
        ],
    ];

    private const RELEASE_KEY = '39ECC3F64AE8D06A9A63FD99AB6F7F52AE513141';

    /**
     * A path of the machine a document was written on: a home, temp or mount directory, a CI
     * workspace (GitHub's /github/workspace and container /__w, GitLab's /builds), a file:// URL,
     * or a Windows drive.
     */
    private const MACHINE_PATH = '{(?<![\w.:-])/(?:Users|home|root|private|var/folders|tmp|Volumes|opt|srv|mnt|github/workspace|__w|builds)/|\bfile://|(?<!\w)[A-Za-z]:[\\\\/]}';

    private const TOKEN = '{\bgh[pousr]_[A-Za-z0-9]{20,}|\bgithub_pat_|\bglpat-}';

    /** @return iterable<string, array{string}> */
    public static function versions(): iterable
    {
        foreach (self::RECORDED as $version) {
            yield $version => [$version];
        }
    }

    /** @return iterable<string, array{string, string}> every recorded document, by version and file */
    public static function documents(): iterable
    {
        foreach (self::RECORDED as $version) {
            foreach (self::files($version) as $file) {
                yield $version.' '.$file => [$version, $file];
            }
        }
    }

    /** @return iterable<string, array{string, string}> every recorded explanation, by version and file */
    public static function explanations(): iterable
    {
        foreach (self::documents() as $name => [$version, $file]) {
            if (strpos($file, 'explain/') === 0) {
                yield $name => [$version, $file];
            }
        }
    }

    /** @return iterable<string, array{string, string}> every schema every release published, by version and document */
    public static function releasedSchemas(): iterable
    {
        foreach (self::RELEASED_SCHEMAS as $version => $schemas) {
            foreach (array_keys($schemas) as $document) {
                yield $version.' '.$document => [$version, $document];
            }
        }
    }

    public function testEveryRecordedVersionIsKeptAndNothingElse(): void
    {
        self::assertSame(array_merge(self::RECORDED, ['schemas']), self::entries(self::DIR, true));
    }

    /**
     * Every release from the first numbered one keeps a copy of its schemas here, so a later change
     * is held to each of them. A release that has none yet fails: copy resources/lockrot-*.schema.json
     * as that release's tag holds them to schemas/<version>/ and pin their sha256 in RELEASED_SCHEMAS.
     */
    public function testEveryReleaseKeepsTheSchemasItPublished(): void
    {
        preg_match_all('/^## \[(\d+\.\d+\.\d+)\]/m', (string) file_get_contents(self::CHANGELOG), $matches);
        $released = array_values(array_filter($matches[1], static fn (string $version): bool => version_compare($version, self::FIRST_NUMBERED, '>=')));
        usort($released, static fn (string $a, string $b): int => version_compare($a, $b));

        self::assertSame($released, array_keys(self::RELEASED_SCHEMAS), 'the releases the changelog lists');
        self::assertSame($released, self::entries(self::SCHEMAS, true), 'the releases schemas/ holds');
    }

    /**
     * @dataProvider releasedSchemas
     */
    #[DataProvider('releasedSchemas')]
    public function testEachReleasedSchemaIsAcceptedByTheCurrentOneUnderTheSameNumber(string $version, string $document): void
    {
        $path = self::SCHEMAS.$version.'/lockrot-'.$document.'.schema.json';
        self::assertSame(self::RELEASED_SCHEMAS[$version][$document], hash_file('sha256', $path), $version.' '.$document.' is what the release published');
        self::assertSame(['lockrot-baseline.schema.json', 'lockrot-config.schema.json', 'lockrot-explain.schema.json', 'lockrot-report.schema.json'], self::entries(self::SCHEMAS.$version, false));

        $released = self::decodeFile($path);
        $current = self::decodeFile(Schemas::path($document));
        if (self::numberOf($released, $document) !== self::numberOf($current, $document)) {
            // A different number is a different contract: nothing is promised across it.
            $this->addToAssertionCount(1);

            return;
        }

        self::assertSame([], SchemaWidening::narrowings($released, $current), $version.'\'s '.$document.' schema accepts documents the current one rejects');
    }

    /**
     * The recording is what the release wrote: the release asset's digest is pinned, provenance.json
     * is pinned, and every file on disk is one it lists, with the bytes it hashed. A document edited
     * to make it validate fails here, with or without its hash updated in provenance.json.
     *
     * @dataProvider versions
     */
    #[DataProvider('versions')]
    public function testTheRecordingIsExactlyWhatTheReleaseWrote(string $version): void
    {
        self::assertSame(self::PROVENANCE[$version], hash_file('sha256', self::DIR.$version.'/provenance.json'), $version.' provenance.json is the one the recording wrote');
        $provenance = self::decode($version, 'provenance.json');

        self::assertSame($version, JsonPath::stringAt($provenance, ['lockrot']));
        self::assertSame('v'.$version, JsonPath::stringAt($provenance, ['tag']));
        self::assertSame(self::RELEASE_PHAR[$version], JsonPath::stringAt($provenance, ['phar_sha256']), 'the release asset ran');
        self::assertSame(self::RELEASE_KEY, JsonPath::stringAt($provenance, ['verified', 'gpg']));
        self::assertSame('tests/fixtures/apps/wallabag_wallabag', JsonPath::stringAt($provenance, ['lock']));

        $listed = self::files($version);
        self::assertContains('report.json', $listed);
        self::assertContains(self::BASELINE, $listed);
        self::assertSame($listed, array_values(array_diff(self::onDisk($version), ['provenance.json'])), 'the documents on disk');
        foreach ($listed as $file) {
            self::assertSame(
                JsonPath::stringAt($provenance, ['files', $file, 'sha256']),
                hash_file('sha256', self::DIR.$version.'/'.$file),
                $version.' '.$file.' is byte for byte what the release wrote'
            );
        }
    }

    /**
     * @dataProvider documents
     */
    #[DataProvider('documents')]
    public function testAnOlderDocumentValidatesAgainstTheSchemaItNames(string $version, string $file): void
    {
        $json = self::read($version, $file);
        $document = self::decode($version, $file);
        $what = $version.' '.$file;

        [$kind, $number] = self::schemaOf($document, $what);
        self::assertSame(self::kindOf($file), $kind, $what);
        self::assertSame($version, JsonPath::stringAt($document, ['lockrot', 'version']), $what.' was written by '.$version);
        self::assertSame($number, JsonPath::intAt($document, ['lockrot', 'schema']), $what);

        $schema = self::schemaFile($kind, $number);
        $this->assertValidAgainst(self::schemaAt($schema), $json, $what);
        $this->assertValidAgainst(self::schemaAt($schema), $json, $what, true);
    }

    /**
     * The baseline an older release wrote is one a user has committed; the current lockrot reads it
     * back through the same schema check, and refuses one it does not match.
     *
     * @dataProvider versions
     */
    #[DataProvider('versions')]
    public function testTheBaselineAnOlderReleaseWroteIsReadBack(string $version): void
    {
        $findings = JsonPath::arrayAt(self::decode($version, self::BASELINE), ['findings']);
        self::assertNotSame([], $findings);

        self::assertSame(\count($findings), BaselineFile::resolve(self::DIR.$version, self::BASELINE)->read()->count());
        self::assertSame(self::BASELINE, JsonPath::stringAt(self::decode($version, 'report.json'), ['baseline', 'path']), 'the report was compared against it');
    }

    /**
     * explain-1 types a signal's `data` as a bare object, so validating an explanation checks none of
     * it. Its finding is the object the report's `findings[]` carries (explain-1 says so and points
     * to report-1 for the data), so each explanation's signals are held to report-1's signal
     * definitions, published and strict. That is this test's check, not one a consumer validating
     * against explain-1 gets.
     *
     * @dataProvider explanations
     */
    #[DataProvider('explanations')]
    public function testAnOlderExplanationsSignalsValidateAgainstTheReportsSignalDefinitions(string $version, string $file): void
    {
        $document = self::decode($version, $file);
        $what = $version.' '.$file.' signals';
        $signals = JsonPath::arrayAt($document, ['finding', 'signals']);
        self::assertNotSame([], $signals, $what);
        [, $number] = self::schemaOf($document, $what);

        $json = (string) json_encode($signals);
        $this->assertValidAgainst(self::signalsSchema($number), $json, $what);
        $this->assertValidAgainst(self::signalsSchema($number), $json, $what, true);
    }

    /**
     * The per-signal `data` branches are only exercised if the older documents really carry them; a
     * signal missing here is a branch whose recorded output goes unchecked (the widening check still
     * covers its schema). The report carries every signal the lock has, and so do the explanations
     * between them.
     *
     * @dataProvider versions
     */
    #[DataProvider('versions')]
    public function testEachOlderRecordingExercisesTheSignalsItWasRecordedFor(string $version): void
    {
        $report = self::decode($version, 'report.json');
        $inReport = [];
        foreach (array_keys(JsonPath::arrayAt($report, ['findings'])) as $at) {
            $inReport = array_merge($inReport, JsonPath::column($report, ['findings', $at, 'signals'], 'id'));
        }
        $inExplanations = [];
        foreach (self::files($version) as $file) {
            if (strpos($file, 'explain/') === 0) {
                $inExplanations = array_merge($inExplanations, JsonPath::column(self::decode($version, $file), ['finding', 'signals'], 'id'));
            }
        }

        self::assertSame(self::SIGNALS[$version], self::distinctIds($inReport), $version.' report.json');
        self::assertSame(self::SIGNALS[$version], self::distinctIds($inExplanations), $version.' explain/');
    }

    /**
     * `run` and a finding's `baseline` are optional: absent or null, they validate without proving
     * anything about their shape, and present, they prove nothing about their absence. 0.9.0 wrote
     * neither, which is what keeps them optional; 0.10.0 on fill both.
     *
     * @dataProvider versions
     */
    #[DataProvider('versions')]
    public function testTheOlderReportsCarryWhatTheirReleaseWrote(string $version): void
    {
        $report = self::decode($version, 'report.json');
        $carried = 0;
        $standings = [];
        foreach (array_keys(JsonPath::arrayAt($report, ['findings'])) as $at) {
            $carried += \array_key_exists('baseline', JsonPath::arrayAt($report, ['findings', $at])) ? 1 : 0;
            if (JsonPath::has($report, ['findings', $at, 'baseline', 'status'])) {
                $standings[] = JsonPath::stringAt($report, ['findings', $at, 'baseline', 'status']);
            }
        }

        if (!self::WRITES_RUN_AND_STANDING[$version]) {
            self::assertArrayNotHasKey('run', $report, $version.' writes no run');
            self::assertSame(0, $carried, $version.' writes no baseline standing');

            return;
        }
        self::assertSame('8.4', JsonPath::stringAt($report, ['run', 'target_php']));
        self::assertSame('composer.lock', JsonPath::stringAt($report, ['run', 'lock_file']));
        self::assertContains('known', $standings, $version.' findings carry their baseline standing');
    }

    /**
     * The recordings are committed, so they must not carry the machine they were recorded on: no
     * absolute path (the report prints the baseline path as given, which is why the recorder names a
     * relative one), and no token. Checked on the bytes and on the unescaped form, since a JSON
     * encoder may write a path as `\/Users\/…`.
     */
    public function testTheRecordingsNameNoMachineAndCarryNoToken(): void
    {
        foreach (self::RECORDED as $version) {
            foreach (array_merge(self::files($version), ['provenance.json']) as $file) {
                $raw = self::read($version, $file);
                self::assertFalse(self::matchesEitherForm(self::MACHINE_PATH, $raw), $version.' '.$file.' names a path of the machine');
                self::assertFalse(self::matchesEitherForm(self::TOKEN, $raw), $version.' '.$file.' carries a token');
            }
        }
    }

    /** @return iterable<string, array{string, bool}> text as a document carries it, and whether it names a path of the machine */
    public static function machinePaths(): iterable
    {
        foreach ([
            '"/Users/alice/app/composer.lock"', '"\/Users\/alice\/app"', '"/home/runner/work/app"', '"/root/app"',
            '"/private/var/folders/x"', '"/var/folders/ab/T/x"', '"/tmp/lockrot.x/composer.lock"', '"/Volumes/Work/app"',
            '"/opt/app/composer.lock"', '"/srv/app/"', '"/mnt/c/app/"', '"/github/workspace/composer.lock"',
            '"/__w/lockrot/lockrot/composer.lock"', '"/builds/group/app/composer.lock"', '"file:///Users/alice/app"',
            '"file://server/share"', '"C:\\\\Users\\\\alice\\\\app"', '"C:/Users/alice/app"', '"see d:\\\\work"',
        ] as $text) {
            yield $text => [$text, true];
        }
        foreach ([
            '"https://lockrot.dev/schema/report-1.json"', '"https:\/\/lockrot.dev\/schema\/report-1.json"',
            '"https://github.com/opt/repo"', '"symfony/mnt"', '"2026-09-25T00:00:00+00:00"', '"lockrot-schema-evolution-baseline.json"',
            '"<copy of tests/fixtures/apps/wallabag_wallabag>"', '"php: >=7.1 || ^8.0"', '"S1: marked abandoned"',
        ] as $text) {
            yield $text => [$text, false];
        }
    }

    /**
     * @dataProvider machinePaths
     */
    #[DataProvider('machinePaths')]
    public function testTheMachinePathCheckKnowsWhereMachinesKeepTheirFiles(string $text, bool $names): void
    {
        self::assertSame($names, self::matchesEitherForm(self::MACHINE_PATH, $text));
    }

    /** On the JSON text as written and as re-encoded without escaped slashes: `\/Users\/…` is a path too. */
    private static function matchesEitherForm(string $pattern, string $json): bool
    {
        $unescaped = (string) json_encode(json_decode($json), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return preg_match($pattern, $json) === 1 || preg_match($pattern, $unescaped) === 1;
    }

    /**
     * The check can fail: an older document with a required member removed is rejected by the
     * published schema, and one carrying a field the schema does not list passes the published
     * schema (objects stay open) and fails the strict twin. One finding is enough to show it.
     */
    public function testTheCheckRejectsWhatAnIncompatibleSchemaWouldBreak(): void
    {
        $version = self::RECORDED[0];
        $report = json_decode(self::read($version, 'report.json'));
        self::assertInstanceOf(\stdClass::class, $report);
        self::assertIsArray($report->findings);
        self::assertInstanceOf(\stdClass::class, $report->findings[0]);
        $report->findings = [$report->findings[0]];
        self::assertSame([], $this->errors(Schemas::REPORT, (string) json_encode($report), false), 'one finding validates');

        $withoutCounts = clone $report;
        unset($withoutCounts->counts);
        self::assertNotSame([], $this->errors(Schemas::REPORT, (string) json_encode($withoutCounts), false), 'a report without counts');

        $report->findings[0]->x_future = true;
        $json = (string) json_encode($report);
        self::assertSame([], $this->errors(Schemas::REPORT, $json, false), 'the published schema keeps a finding open');
        self::assertNotSame([], $this->errors(Schemas::REPORT, $json, true), 'the strict twin lists every field');

        $explanation = json_decode(self::read($version, 'explain/doctrine~cache.json'));
        self::assertInstanceOf(\stdClass::class, $explanation);
        unset($explanation->lock);
        self::assertNotSame([], $this->errors(Schemas::EXPLAIN, (string) json_encode($explanation), false), 'an explanation without its lock');

        // explain-1 takes any signal data; report-1's signal definitions do not.
        $garbled = json_decode(self::read($version, 'explain/scheb~2fa-backup-code.json'));
        self::assertInstanceOf(\stdClass::class, $garbled);
        self::assertInstanceOf(\stdClass::class, $garbled->finding);
        self::assertIsArray($garbled->finding->signals);
        self::assertInstanceOf(\stdClass::class, $garbled->finding->signals[0]);
        $garbled->finding->signals[0]->data = (object) ['garbage' => true, 'years' => 'not a number'];
        self::assertSame([], $this->errors(Schemas::EXPLAIN, (string) json_encode($garbled), true), 'the explain schema takes any signal data');
        $json = (string) json_encode($garbled->finding->signals);
        self::assertNotSame([], $this->errorsAgainst(self::signalsSchema(JsonFormatter::SCHEMA), $json, false), 'report-1 types it');
    }

    /**
     * @param list<mixed> $ids
     *
     * @return list<string> sorted naturally, S10 after S9
     */
    private static function distinctIds(array $ids): array
    {
        $distinct = [];
        foreach ($ids as $id) {
            self::assertIsString($id);
            $distinct[$id] = true;
        }
        $distinct = array_keys($distinct);
        usort($distinct, 'strnatcmp');

        return $distinct;
    }

    /**
     * The documents of one recording, relative to its directory, as its provenance.json lists them:
     * a later version may record other explanations than an earlier one did.
     *
     * @return list<string> sorted
     */
    private static function files(string $version): array
    {
        $files = array_map('strval', array_keys(JsonPath::arrayAt(self::decode($version, 'provenance.json'), ['files'])));
        sort($files);

        return $files;
    }

    /** @return list<string> every file under a recording, relative to it, sorted */
    private static function onDisk(string $version): array
    {
        $files = [];
        foreach (self::entries(self::DIR.$version, false) as $file) {
            $files[] = $file;
        }
        foreach (self::entries(self::DIR.$version, true) as $dir) {
            foreach (self::entries(self::DIR.$version.'/'.$dir, false) as $file) {
                $files[] = $dir.'/'.$file;
            }
        }
        sort($files);

        return $files;
    }

    /**
     * The directories, or the files, in one directory, naturally sorted. scandir() rather than
     * glob(): a checkout under a path holding `[` or `*` is no pattern.
     *
     * @return list<string>
     */
    private static function entries(string $dir, bool $directories): array
    {
        $names = scandir($dir);
        self::assertIsArray($names, $dir);
        $entries = array_values(array_filter(
            $names,
            static fn (string $name): bool => $name !== '.' && $name !== '..' && is_dir($dir.'/'.$name) === $directories
        ));
        usort($entries, 'strnatcmp');

        return $entries;
    }

    private static function read(string $version, string $file): string
    {
        $path = self::DIR.$version.'/'.$file;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** @return array<mixed, mixed> */
    private static function decode(string $version, string $file): array
    {
        $decoded = json_decode(self::read($version, $file), true);
        self::assertIsArray($decoded, $version.' '.$file.': '.json_last_error_msg());

        return $decoded;
    }

    /** @return array<mixed, mixed> */
    private static function decodeFile(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($decoded, $path.': '.json_last_error_msg());

        return $decoded;
    }

    private static function kindOf(string $file): string
    {
        if ($file === 'report.json') {
            return Schemas::REPORT;
        }

        return $file === self::BASELINE ? Schemas::BASELINE : Schemas::EXPLAIN;
    }

    /**
     * The schema a document names in its `$schema`, never one guessed from its file name: which
     * document it is and under which number. A URL that is not one of lockrot's fails rather than
     * being skipped.
     *
     * @param array<mixed, mixed> $document
     *
     * @return array{string, int}
     */
    private static function schemaOf(array $document, string $what): array
    {
        $url = JsonPath::stringAt($document, ['$schema']);
        $pattern = '{^'.preg_quote(Schemas::BASE_URL, '{').'('.Schemas::REPORT.'|'.Schemas::EXPLAIN.'|'.Schemas::BASELINE.')-([1-9]\d*)\.json$}';
        if (preg_match($pattern, $url, $match) !== 1) {
            self::fail($what.' names '.$url.', which is none of lockrot\'s document schemas');
        }
        self::assertSame(Schemas::url($match[1], (int) $match[2]), $url);

        return [$match[1], (int) $match[2]];
    }

    /**
     * The schema file of a document under a number: the current one under the number lockrot writes
     * today, else the newest copy a release published under that number. A number no release
     * published fails.
     */
    private static function schemaFile(string $document, int $number): string
    {
        $current = [
            Schemas::REPORT => JsonFormatter::SCHEMA,
            Schemas::EXPLAIN => ExplainFormatter::SCHEMA,
            Schemas::BASELINE => Baseline::SCHEMA,
        ];
        if ($number === $current[$document]) {
            return Schemas::path($document);
        }
        foreach (array_reverse(array_keys(self::RELEASED_SCHEMAS)) as $version) {
            $path = self::SCHEMAS.$version.'/lockrot-'.$document.'.schema.json';
            if (self::numberOf(self::decodeFile($path), $document) === $number) {
                return $path;
            }
        }

        self::fail('no release published '.Schemas::url($document, $number));
    }

    /** @param array<mixed, mixed> $schema */
    private static function numberOf(array $schema, string $document): int
    {
        $id = JsonPath::stringAt($schema, ['id']);
        if (preg_match('{^'.preg_quote(Schemas::BASE_URL.$document.'-', '{').'([1-9]\d*)\.json$}', $id, $match) !== 1) {
            self::fail($id.' is not the URL of a '.$document.' schema');
        }

        return (int) $match[1];
    }

    /**
     * A list of signals under the report schema of one number: that schema's own definitions, with
     * the root asking for an array of `#/definitions/signal`.
     */
    private static function signalsSchema(int $number): object
    {
        $schema = self::schemaAt(self::schemaFile(Schemas::REPORT, $number));
        self::assertInstanceOf(\stdClass::class, $schema);
        unset($schema->required, $schema->properties);
        $schema->type = 'array';
        $schema->items = (object) ['$ref' => '#/definitions/signal'];

        return $schema;
    }
}
