<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Score;

use Lockrot\Score\CountedAdvisory;
use PHPUnit\Framework\TestCase;

/** One counted advisory as the score engine reads it. */
final class CountedAdvisoryTest extends TestCase
{
    public function testItKeepsItsIdSeverityAndFixKind(): void
    {
        $advisory = new CountedAdvisory('PKSA-a', 'moderate', 'upgrade');

        self::assertSame(['PKSA-a', 'moderate', 'upgrade'], [$advisory->id(), $advisory->severity(), $advisory->fixKind()]);
    }

    /** The engine compares buckets, so it gives the advisory the bucket of Composer's word. */
    public function testItTakesTheBucketOfComposersSeverityWord(): void
    {
        $bucketed = (new CountedAdvisory('PKSA-a', 'moderate', 'upgrade'))->bucketed();

        self::assertSame(['PKSA-a', 'medium', 'upgrade'], [$bucketed->id(), $bucketed->severity(), $bucketed->fixKind()]);
    }
}
