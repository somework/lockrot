<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Solver;

use Lockrot\Analyzer\RunNote;
use Lockrot\Solver\MoveCheck;
use PHPUnit\Framework\TestCase;

final class MoveCheckTest extends TestCase
{
    public function testAReleaseBelowTheNewestIsNamed(): void
    {
        self::assertSame('5.3.8', MoveCheck::resolves(MoveCheck::INLINE, '5.3.8')->installsBelow('5.3.9'));
        self::assertNull(MoveCheck::resolves(MoveCheck::INLINE, '5.3.9')->installsBelow('v5.3.9'));
    }

    public function testNoReleaseIsBelowAMissingNewestOrBesideAMissingInstall(): void
    {
        self::assertNull(MoveCheck::resolves(MoveCheck::INLINE, '5.3.8')->installsBelow(null));
        self::assertNull(MoveCheck::resolves(MoveCheck::INLINE)->installsBelow('5.3.9'));
        self::assertNull(MoveCheck::refused()->installsBelow('5.3.9'));
        self::assertNull(MoveCheck::resolves(MoveCheck::INLINE, 'dev-main')->installsBelow('not a version'));
    }

    /** SPEC §7.9.21 holds the words, and the update-now note of §6.2 reads them. */
    public function testAnIncompleteAdvisoryLookupNamesThePackageThatTheLookupDidNotCover(): void
    {
        self::assertSame(
            "update now: not checked with Composer's solver (the advisory lookup did not cover a package it installs), so lockrot writes no command for a/b",
            RunNote::updateNowNotChecked(MoveCheck::ADVISORY_LOOKUP_INCOMPLETE, ['a/b'])->text()
        );
    }
}
