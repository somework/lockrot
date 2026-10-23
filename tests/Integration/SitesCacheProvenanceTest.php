<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * tests/fixtures/corpus/sites/ holds the recorded answers of the site engine page cases
 * (`Lockrot\Tests\Support\Contract\EnginePage::CASES`). Its digest is the one that
 * corpus/sites.provenance.json names. A re-recorded answer then cannot pass for the recorded one,
 * as `CorpusProvenanceTest` checks for the corpus cache.
 *
 * @coversNothing
 *
 * @group covers-nothing
 */
#[CoversNothing]
#[Group('covers-nothing')]
final class SitesCacheProvenanceTest extends TestCase
{
    private const CORPUS = __DIR__.'/../fixtures/corpus/';

    public function testTheSitesCacheIsTheOneThatItsProvenanceNames(): void
    {
        $provenance = JsonPath::decodeFile(self::CORPUS.'sites.provenance.json');
        $cache = self::CORPUS.JsonPath::stringAt($provenance, ['path']);
        $files = [];
        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($cache, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[] = substr($file->getPathname(), \strlen($cache) + 1);
        }
        sort($files);
        $lines = array_map(static fn (string $file): string => hash_file('sha256', $cache.'/'.$file).'  '.$file."\n", $files);

        self::assertSame(
            [JsonPath::intAt($provenance, ['files']), JsonPath::stringAt($provenance, ['sha256'])],
            [\count($files), hash('sha256', implode('', $lines))],
            'corpus/sites.provenance.json: re-record with its `recorder` command, then write the new count and digest'
        );
    }
}
