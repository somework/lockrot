<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Advisory;

use Lockrot\Data\Advisory\AdvisoryBatch;
use Lockrot\Data\Advisory\AdvisoryCoverage;
use PHPUnit\Framework\TestCase;

final class AdvisoryBatchTest extends TestCase
{
    public function testABatchWithoutALookupRecordsTheDefaultScopeAndNoRepository(): void
    {
        $coverage = AdvisoryBatch::empty()->coverage();

        self::assertSame([AdvisoryCoverage::SCOPE_ALL, AdvisoryCoverage::SOURCE_DEFAULT, [], 0], [$coverage->scope(), $coverage->scopeSource(), $coverage->repositories(), $coverage->otherRepositories()]);
        self::assertNull($coverage->for('vendor/pkg'));
        self::assertFalse(AdvisoryBatch::empty()->complete());
        self::assertSame([], AdvisoryBatch::empty()->ignored('vendor/pkg'));
        self::assertSame([], AdvisoryBatch::empty()->every('vendor/pkg'));
    }

    public function testTheRecordsGoToACopy(): void
    {
        $batch = new AdvisoryBatch([]);

        $with = $batch->withRecords(['acme/lib' => []], ['acme/lib']);

        self::assertSame([['acme/lib' => []], ['acme/lib']], [$with->records(), $with->asked()]);
        self::assertSame([[], []], [$batch->records(), $batch->asked()]);
    }
}
