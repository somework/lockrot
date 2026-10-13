<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Tests\Support\CorpusFloor;
use PHPUnit\Framework\TestCase;

/**
 * The flags and the score over the corpus floor, against what report-1 recorded. No watched project
 * configures Composer's abandoned ignore list, so every S1 is in the floor.
 */
final class CorpusFloorTest extends TestCase
{
    public function testTheFloorHoldsReport1DocumentsOfOneRelease(): void
    {
        $reports = CorpusFloor::reports();

        self::assertNotSame([], $reports);
        self::assertSame(['0.13.0'], array_values(array_unique(array_column($reports, 'lockrot'))));
    }

    public function testTheProvenanceNamesThisFloor(): void
    {
        $provenance = json_decode((string) file_get_contents(CorpusFloor::PROVENANCE), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($provenance);
        $reports = CorpusFloor::reports();

        self::assertSame(hash_file('sha256', CorpusFloor::PATH), $provenance['sha256']);
        self::assertSame(\count($reports), $provenance['reports']);
        self::assertSame(array_sum(array_map(static fn (array $report): int => \count($report['findings']), $reports)), $provenance['findings']);
    }


}
