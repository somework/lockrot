<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Output\Via;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\TestCase;

final class ViaTest extends TestCase
{
    /**
     * @param list<string> $chain
     * @param list<string> $dependents
     */
    private static function finding(array $chain, array $dependents): Finding
    {
        return new Finding('acme/pkg', '1.0.0', Verdict::STALE, [], $chain, null, null, null, false, $dependents);
    }

    public function testDirectPackageNothingElseReaches(): void
    {
        $f = self::finding(['acme/pkg'], ['acme/pkg']);
        self::assertSame('direct', Via::chain($f, ' › '));
        self::assertSame('', Via::also($f));
        self::assertSame('direct', Via::inline($f, ' › '));
        self::assertSame('direct', Via::cell($f, ' › '));
        self::assertSame('', Via::suffix($f, ' > '));
    }

    public function testDirectPackageAnotherRootAlsoReaches(): void
    {
        $f = self::finding(['acme/pkg'], ['acme/pkg', 'r/one']);
        self::assertSame('direct, also via r/one', Via::inline($f, ' › '));
        self::assertSame('direct, also via r/one', Via::cell($f, ' › '));
        self::assertSame(' (also via r/one)', Via::suffix($f, ' > '));
    }

    public function testTransitivePackage(): void
    {
        $f = self::finding(['r/one', 'mid/dle', 'acme/pkg'], ['r/one']);
        self::assertSame('via r/one › mid/dle', Via::inline($f, ' › '));
        self::assertSame('r/one › mid/dle', Via::cell($f, ' › '));
        self::assertSame(' (via r/one > mid/dle)', Via::suffix($f, ' > '));
    }

    public function testTransitivePackageWithOtherRootsCappedAtThreeNames(): void
    {
        $f = self::finding(['r/one', 'acme/pkg'], ['r/five', 'r/four', 'r/one', 'r/three', 'r/two']);
        self::assertSame('also via r/five, r/four, r/three and 1 more', Via::also($f));
        self::assertSame('via r/one, also via r/five, r/four, r/three and 1 more', Via::inline($f, ' › '));
        self::assertSame(' (via r/one, also via r/five, r/four, r/three and 1 more)', Via::suffix($f, ' > '));
    }

    public function testUnplaceablePackage(): void
    {
        $f = self::finding([], []);
        self::assertSame('?', Via::inline($f, ' › '));
        self::assertSame('?', Via::cell($f, ' › '));
        self::assertSame('', Via::suffix($f, ' > '));
    }

    public function testAFindingBuiltWithoutDependentsReadsAsBefore(): void
    {
        $f = self::finding(['r/one', 'acme/pkg'], []);
        self::assertSame('via r/one', Via::inline($f, ' › '));
        self::assertSame(' (via r/one)', Via::suffix($f, ' > '));
    }
}
