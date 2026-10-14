<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Tests\Support\Contract\ContractBundle;
use Lockrot\Tests\Support\JsonPath;
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
        $committed = array_diff_key(ContractBundle::tree(ContractBundle::DIRECTORY), array_flip(ContractBundle::NOT_RECORDED));

        self::assertSame(array_keys($committed), array_keys($recorded['files']), 're-record with tools/report/record: the file sets differ');
        foreach ($recorded['files'] as $path => $contents) {
            self::assertSame($committed[$path], $contents, $path.': re-record with tools/report/record');
        }
        $provenance = JsonPath::decodeFile(ContractBundle::DIRECTORY.'/provenance.json');
        self::assertSame($recorded['without_inputs'], JsonPath::arrayAt($provenance, ['cases_without_inputs']));
        self::assertSame(JsonPath::decoded($recorded['goldens']), JsonPath::arrayAt($provenance, ['model_goldens']));
    }

    public function testTheBundleHashIsTheHashOfTheCommittedFiles(): void
    {
        self::assertSame(trim((string) file_get_contents(ContractBundle::DIRECTORY.'/bundle_sha256')), ContractBundle::sha256(ContractBundle::bundleFiles()));
    }
}
