<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Analyzer\RunSettings;
use Lockrot\Tests\Support\Contract\ContractBundle;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\VersionMask;
use Lockrot\Verdict\ScoreModel;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * The committed contract bundle is what lockrot writes (SPEC-0.14.md §7.11 item 4). A fresh recording
 * equals tests/fixtures/contract/ byte for byte. `bundle_sha256` hashes the committed files.
 *
 * @coversNothing
 *
 * @group covers-nothing
 */
#[CoversNothing]
#[Group('covers-nothing')]
final class ContractBundleIsCurrentTest extends TestCase
{
    /**
     * @runInSeparateProcess
     */
    #[RunInSeparateProcess]
    public function testAFreshRecordingEqualsTheCommittedBundle(): void
    {
        $recorded = ContractBundle::record();
        $committed = array_filter(
            array_diff_key(ContractBundle::tree(ContractBundle::DIRECTORY), array_flip(ContractBundle::NOT_RECORDED)),
            static fn (string $path): bool => strpos($path, ContractBundle::ENGINE_PAGES) !== 0,
            \ARRAY_FILTER_USE_KEY
        );

        self::assertSame(array_keys($committed), array_keys($recorded['files']), 're-record with tools/report/record: the file sets differ');
        foreach ($recorded['files'] as $path => $contents) {
            self::assertSame($committed[$path], $contents, $path.': re-record with tools/report/record');
        }
        $provenance = JsonPath::decodeFile(ContractBundle::DIRECTORY.'/provenance.json');
        self::assertSame($recorded['without_inputs'], JsonPath::arrayAt($provenance, ['cases_without_inputs']));
        self::assertSame(JsonPath::decoded($recorded['goldens']), JsonPath::arrayAt($provenance, ['model_goldens']));
    }

    /** Every capped case that the model gives inputs for hydrates (R4-17). */
    public function testTheCappedCasesWithoutInputsAreOnlyTheListedOnes(): void
    {
        $withoutInputs = [
            'capped-unread‡' => 'its fetch failed, so the model has no releases to give',
        ];
        $provenance = JsonPath::decodeFile(ContractBundle::DIRECTORY.'/provenance.json');
        $capped = array_values(array_filter(JsonPath::arrayAt($provenance, ['cases_without_inputs']), static fn ($id): bool => \is_string($id) && strpos($id, 'capped-') === 0));

        self::assertSame(array_keys($withoutInputs), $capped, 'a capped case without inputs needs a reason here, and a hydrated one leaves the list');
    }

    /** A committed provenance.json with a count below its floor did not come from a passing recording. */
    public function testTheCommittedComparedCountsMeetTheFloors(): void
    {
        $provenance = JsonPath::decodeFile(ContractBundle::DIRECTORY.'/provenance.json');
        $compared = JsonPath::arrayAt($provenance, ['model_goldens', 'compared']);

        self::assertSame(array_keys(ContractBundle::COMPARED_FLOOR), array_keys($compared));
        foreach (ContractBundle::COMPARED_FLOOR as $key => $floor) {
            self::assertGreaterThanOrEqual($floor, $compared[$key], $key.': a recording compares fewer goldens than its floor');
        }
    }

    /**
     * A release ships the published -2 schemas and types beside the vendored renderer page. A change
     * to one moves bundle_sha256, so RendererManifestTest fails until a renderer pins the change.
     */
    public function testTheBundlePinsThePublishedSchemasAndTypes(): void
    {
        $root = \dirname(__DIR__, 2).'/';
        $paths = [
            'resources/lockrot-baseline-2.schema.json',
            'resources/lockrot-config-2.schema.json',
            'resources/lockrot-explain-2.schema.json',
            'resources/lockrot-report-2.schema.json',
            'types/lockrot-report-2.d.ts',
            'types/lockrot-report-2.known.d.ts',
        ];
        $expected = [];
        foreach ($paths as $path) {
            $expected[$path] = hash_file('sha256', $root.$path);
        }

        self::assertSame(['sha256' => $expected], JsonPath::decodeFile(ContractBundle::DIRECTORY.'/contract-files.json'), 're-record with tools/report/record');
    }

    /** The page goldens are copied, not recorded, so no recording catches a copy that skips the provenance. */
    public function testBothProvenanceRecordsNameTheCommittedPageGoldens(): void
    {
        $hash = hash_file('sha256', ContractBundle::DIRECTORY.'/page-goldens.json');
        $records = [
            'the contract provenance' => ContractBundle::DIRECTORY.'/provenance.json',
            'the flags provenance' => \dirname(ContractBundle::DIRECTORY).'/flags/provenance.json',
        ];

        foreach ($records as $name => $path) {
            self::assertSame($hash, JsonPath::stringAt(JsonPath::decodeFile($path), ['page_goldens', 'sha256']), $name.' names other page goldens than the committed page-goldens.json');
        }
    }

    /**
     * The byte comparison skips provenance.json, so a change to an input, a grammar number or the
     * version mask that leaves the output as it was needs this pin to show a stale record.
     */
    public function testTheProvenanceNamesTheCurrentInputsGrammarsAndVersionMask(): void
    {
        $fixtures = \dirname(ContractBundle::DIRECTORY).'/';
        $inputs = [];
        foreach (['flags/cases.json', 'flags/composite.json', 'flags/provenance.json'] as $input) {
            $inputs['tests/fixtures/'.$input] = hash_file('sha256', $fixtures.$input);
        }
        $provenance = JsonPath::decodeFile(ContractBundle::DIRECTORY.'/provenance.json');

        self::assertSame($inputs, JsonPath::arrayAt($provenance, ['inputs']), 're-record with tools/report/record: an input changed');
        self::assertSame(RunSettings::TEXT_GRAMMAR, JsonPath::intAt($provenance, ['text_grammar']), 're-record with tools/report/record');
        self::assertSame(ScoreModel::SCORE_TEXT_GRAMMAR, JsonPath::intAt($provenance, ['score_text_grammar']), 're-record with tools/report/record');
        self::assertSame(['paths' => VersionMask::PATHS, 'value' => VersionMask::VALUE], JsonPath::arrayAt($provenance, ['version_mask']), 're-record with tools/report/record');
    }

    public function testTheBundleHashIsTheHashOfTheCommittedFiles(): void
    {
        self::assertSame(trim((string) file_get_contents(ContractBundle::DIRECTORY.'/bundle_sha256')), ContractBundle::sha256(ContractBundle::bundleFiles()));
    }
}
