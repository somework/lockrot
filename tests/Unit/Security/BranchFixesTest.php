<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Security;

use Lockrot\Security\BranchFixes;
use PHPUnit\Framework\TestCase;

final class BranchFixesTest extends TestCase
{
    /** With no release data the scan has no branch row, so the S9 rows count the installed one. */
    public function testTheInstalledRowOfTheS9RowsHasNoCandidate(): void
    {
        $row = BranchFixes::installedFromRows('1.x', 1, 2, 3);

        self::assertSame('1.x', $row->branch());
        self::assertTrue($row->installed());
        self::assertSame([1, 2, 3], [$row->fixed(), $row->unknown(), $row->of()]);
        self::assertNull($row->candidate());
        self::assertNull($row->fixKind());
        self::assertNull($row->lowest());
        self::assertNull($row->newest());
        self::assertSame([], $row->clears());
    }
}
