<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Json\Schemas;
use Lockrot\Output\JsonFormatter;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\ValidatesJsonSchemas;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Backward compatibility of the published schemas: a document an earlier lockrot release wrote
 * still validates against the current `report-1.json` and `explain-1.json`.
 *
 * tests/fixtures/schema-evolution/<version>/ holds what the signed release PHAR of that version
 * wrote over wallabag's lock, recorded once by bin/record-schema-evolution and never edited. Each
 * document is validated as JsonSchemaConformanceTest validates the current formatters' output: against
 * the schema as published, which catches a field made required or a type or enum narrowed, and
 * against the strict twin, which also catches a field the older document carries and the current
 * schema stopped listing — under one schema number neither may happen.
 *
 * This is the backward direction only. The forward one — a document the newest lockrot writes,
 * validated against a copy of the schema an older release published — is not tested here, and does
 * not hold today: 0.10.0's schemas list signal ids S1–S9 and demand a non-empty `chain`, and
 * current documents can carry S10 and an empty chain.
 */
final class SchemaEvolutionTest extends TestCase
{
    use ValidatesJsonSchemas;

    private const DIR = __DIR__.'/../fixtures/schema-evolution/';

    /** Every recorded version. A version is added, never re-recorded and never dropped. */
    private const RECORDED = ['0.10.0', '0.11.0'];

    /** The `--explain` documents recorded for each version, one per shape (bin/record-schema-evolution). */
    private const EXPLAINED = [
        'doctrine~cache.json',
        'javibravo~simpleue.json',
        'scheb~2fa-backup-code.json',
        'sensio~framework-extra-bundle.json',
        'spomky-labs~otphp.json',
        'wallabag~rulerz.json',
    ];

    /** The signal ids each recorded report carries, as measured on the recording. */
    private const SIGNALS = [
        '0.10.0' => [Signal::S1, Signal::S2, Signal::S3, Signal::S4, Signal::S5, Signal::S6, Signal::S7, Signal::S8, Signal::S9],
        '0.11.0' => [Signal::S1, Signal::S2, Signal::S3, Signal::S4, Signal::S5, Signal::S6, Signal::S7, Signal::S8, Signal::S9, Signal::S10],
    ];

    private const RELEASE_KEY = '39ECC3F64AE8D06A9A63FD99AB6F7F52AE513141';

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
            foreach (self::files() as $file) {
                yield $version.' '.$file => [$version, $file];
            }
        }
    }

    public function testEveryRecordedVersionIsKeptAndNothingElse(): void
    {
        $dirs = array_map('basename', glob(self::DIR.'*', \GLOB_ONLYDIR) ?: []);
        usort($dirs, 'strnatcmp');

        self::assertSame(self::RECORDED, $dirs);
    }

    /**
     * The recording is what the release wrote: its provenance names the release, and every file on
     * disk is one it lists, with the bytes it hashed. A document edited to make it validate fails here.
     *
     * @dataProvider versions
     */
    #[DataProvider('versions')]
    public function testTheRecordingIsExactlyWhatTheReleaseWrote(string $version): void
    {
        $provenance = self::decode($version, 'provenance.json');

        self::assertSame($version, JsonPath::stringAt($provenance, ['lockrot']));
        self::assertSame('v'.$version, JsonPath::stringAt($provenance, ['tag']));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', JsonPath::stringAt($provenance, ['phar_sha256']));
        self::assertSame(self::RELEASE_KEY, JsonPath::stringAt($provenance, ['verified', 'gpg']));
        self::assertSame('tests/fixtures/apps/wallabag_wallabag', JsonPath::stringAt($provenance, ['lock']));

        $listed = array_keys(JsonPath::arrayAt($provenance, ['files']));
        sort($listed);
        $onDisk = array_map(
            static fn (string $path): string => substr($path, \strlen(self::DIR.$version.'/')),
            array_merge(glob(self::DIR.$version.'/*.json') ?: [], glob(self::DIR.$version.'/explain/*.json') ?: [])
        );
        $onDisk = array_values(array_diff($onDisk, ['provenance.json']));
        sort($onDisk);
        $expected = self::files();
        sort($expected);

        self::assertSame($expected, $onDisk, 'the documents on disk');
        self::assertSame($expected, $listed, 'the documents the recording lists');
        foreach ($expected as $file) {
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
    public function testAnOlderDocumentValidatesAgainstTheCurrentSchema(string $version, string $file): void
    {
        $json = self::read($version, $file);
        $document = self::decode($version, $file);
        $what = $version.' '.$file;

        $schema = self::schemaOf($document, $what);
        self::assertSame($file === 'report.json' ? Schemas::REPORT : Schemas::EXPLAIN, $schema, $what);
        self::assertSame($version, JsonPath::stringAt($document, ['lockrot', 'version']), $what.' was written by '.$version);
        self::assertSame(JsonFormatter::SCHEMA, JsonPath::intAt($document, ['lockrot', 'schema']), $what);

        $this->assertValid($schema, $json, $what);
        $this->assertValid($schema, $json, $what, true);
    }

    /**
     * The per-signal `data` branches are only exercised if the older documents really carry them; a
     * signal missing here is a branch whose compatibility goes untested. The report carries every
     * signal the lock has, and so do the explanations between them.
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
        foreach (self::EXPLAINED as $file) {
            $inExplanations = array_merge($inExplanations, JsonPath::column(self::decode($version, 'explain/'.$file), ['finding', 'signals'], 'id'));
        }

        self::assertSame(self::SIGNALS[$version], self::distinctIds($inReport), $version.' report.json');
        self::assertSame(self::SIGNALS[$version], self::distinctIds($inExplanations), $version.' explain/');
    }

    /**
     * `run` and `baseline` are optional blocks: absent or null, they validate without proving
     * anything about their shape. The recordings fill both.
     *
     * @dataProvider versions
     */
    #[DataProvider('versions')]
    public function testTheOlderReportsCarryARunAndABaselineComparison(string $version): void
    {
        $report = self::decode($version, 'report.json');

        self::assertSame('8.4', JsonPath::stringAt($report, ['run', 'target_php']));
        self::assertSame('composer.lock', JsonPath::stringAt($report, ['run', 'lock_file']));
        self::assertSame('lockrot-schema-evolution-baseline.json', JsonPath::stringAt($report, ['baseline', 'path']));

        $standings = [];
        foreach (array_keys(JsonPath::arrayAt($report, ['findings'])) as $at) {
            if (JsonPath::has($report, ['findings', $at, 'baseline', 'status'])) {
                $standings[JsonPath::stringAt($report, ['findings', $at, 'baseline', 'status'])] = true;
            }
        }
        self::assertArrayHasKey('known', $standings, $version.' findings carry their baseline standing');
    }

    /**
     * The recordings are committed, so they must not carry the machine they were recorded on: no
     * absolute path (the report prints the baseline path as given, which is why the recorder names a
     * relative one), and no token. Checked on the bytes and on the unescaped form, since a JSON
     * encoder may write a path as `\/Users\/…`.
     */
    public function testTheRecordingsNameNoMachineAndCarryNoToken(): void
    {
        $checked = 0;
        foreach (self::RECORDED as $version) {
            foreach (array_merge(self::files(), ['provenance.json']) as $file) {
                $raw = self::read($version, $file);
                $unescaped = (string) json_encode(json_decode($raw), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
                foreach ([$raw, $unescaped] as $text) {
                    self::assertDoesNotMatchRegularExpression('{(?<![\w.:/-])/(Users|home|root|private|var/folders|tmp)/}', $text, $version.' '.$file.' names a path of the machine');
                    self::assertDoesNotMatchRegularExpression('{\bgh[pousr]_[A-Za-z0-9]{20,}|\bgithub_pat_|\bglpat-}', $text, $version.' '.$file.' carries a token');
                }
                ++$checked;
            }
        }

        self::assertSame(\count(self::RECORDED) * (\count(self::files()) + 1), $checked);
    }

    /**
     * The check can fail: an older document with a required member removed is rejected by the
     * published schema, and one carrying a field the schema does not list passes the published
     * schema (objects stay open) and fails the strict twin.
     */
    public function testTheCheckRejectsWhatAnIncompatibleSchemaWouldBreak(): void
    {
        $version = self::RECORDED[0];
        $report = json_decode(self::read($version, 'report.json'));
        self::assertInstanceOf(\stdClass::class, $report);

        $withoutCounts = clone $report;
        unset($withoutCounts->counts);
        self::assertNotSame([], $this->errors(Schemas::REPORT, (string) json_encode($withoutCounts), false), 'a report without counts');

        $withAnUnlistedField = json_decode(self::read($version, 'report.json'));
        self::assertInstanceOf(\stdClass::class, $withAnUnlistedField);
        self::assertIsArray($withAnUnlistedField->findings);
        self::assertInstanceOf(\stdClass::class, $withAnUnlistedField->findings[0]);
        $withAnUnlistedField->findings[0]->x_future = true;
        $json = (string) json_encode($withAnUnlistedField);
        self::assertSame([], $this->errors(Schemas::REPORT, $json, false), 'the published schema keeps a finding open');
        self::assertNotSame([], $this->errors(Schemas::REPORT, $json, true), 'the strict twin lists every field');

        $explanation = json_decode(self::read($version, 'explain/'.self::EXPLAINED[0]));
        self::assertInstanceOf(\stdClass::class, $explanation);
        unset($explanation->lock);
        self::assertNotSame([], $this->errors(Schemas::EXPLAIN, (string) json_encode($explanation), false), 'an explanation without its lock');
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

    /** @return list<string> the documents of one version, relative to its directory */
    private static function files(): array
    {
        return array_merge(['report.json'], array_map(static fn (string $file): string => 'explain/'.$file, self::EXPLAINED));
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

    /**
     * The schema a document names in its `$schema`, never one guessed from its file name: a URL
     * that is neither the report's nor the explanation's fails rather than being skipped.
     *
     * @param array<mixed, mixed> $document
     */
    private static function schemaOf(array $document, string $what): string
    {
        $url = JsonPath::stringAt($document, ['$schema']);
        foreach ([Schemas::REPORT, Schemas::EXPLAIN] as $schema) {
            if ($url === Schemas::url($schema, JsonFormatter::SCHEMA)) {
                return $schema;
            }
        }

        self::fail($what.' names '.$url.', which is neither the report schema nor the explain schema');
    }
}
