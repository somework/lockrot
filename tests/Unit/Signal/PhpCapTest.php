<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal;

use Lockrot\Signal\PhpCap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** R, the newest PHP minor that the root php admits, and S11's test against it: docs/verdicts.md#capped. */
final class PhpCapTest extends TestCase
{
    private const KNOWN = ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'];

    public function testWithNoRootPhpTheNewestPhpIsTheNewestKnownMinor(): void
    {
        $cap = new PhpCap(self::KNOWN);

        self::assertSame(['8.5', PhpCap::KNOWN], [$cap->newest(), $cap->source()]);
    }

    public function testARequirePhpThatAdmitsTheNewestMinorKeepsTheSourceKnown(): void
    {
        $cap = new PhpCap(self::KNOWN, '>=7.4');

        self::assertSame(['8.5', PhpCap::KNOWN], [$cap->newest(), $cap->source()]);
    }

    public function testARequirePhpBelowTheNewestMinorLimitsIt(): void
    {
        $cap = new PhpCap(self::KNOWN, '~8.1.0 || ~8.2.0 || ~8.3.0');

        self::assertSame(['8.3', PhpCap::REQUIRE_PHP], [$cap->newest(), $cap->source()]);
    }

    /** Without a `require.php`, the lock's `platform.php` is the root php. */
    public function testTheLocksPlatformPhpLimitsItWithoutARequirePhp(): void
    {
        $cap = new PhpCap(self::KNOWN, null, '~8.1.0 || ~8.2.0 || ~8.3.0');

        self::assertSame(['8.3', PhpCap::PLATFORM_PHP], [$cap->newest(), $cap->source()]);
    }

    /** A blank `require.php` states no php: the lock's `platform.php` is the root php. */
    public function testABlankRequirePhpLeavesTheLocksPlatformPhp(): void
    {
        $cap = new PhpCap(self::KNOWN, '  ', '~8.1.0 || ~8.2.0 || ~8.3.0');

        self::assertSame(['8.3', PhpCap::PLATFORM_PHP], [$cap->newest(), $cap->source()]);
    }

    public function testABlankRootPhpLeavesTheNewestKnownMinor(): void
    {
        $cap = new PhpCap(self::KNOWN, null, '  ');

        self::assertSame(['8.5', PhpCap::KNOWN], [$cap->newest(), $cap->source()]);
    }

    public function testARequirePhpWinsOverTheLocksPlatformPhp(): void
    {
        $cap = new PhpCap(self::KNOWN, '>=8.0', '~7.4.0');

        self::assertSame(['8.5', PhpCap::KNOWN], [$cap->newest(), $cap->source()]);
    }

    /** @return iterable<string, array{string}> */
    public static function rootsWithoutNewest(): iterable
    {
        yield 'a root php that does not parse' => ['not a constraint'];
        yield 'a root php above every known minor' => ['>=9.0'];
        yield 'a root php below every known minor' => ['<7.0'];
    }

    /**
     * Such a root php gives no R, so nothing is capped.
     *
     * @dataProvider rootsWithoutNewest
     */
    #[DataProvider('rootsWithoutNewest')]
    public function testARootPhpThatDoesNotParseOrAdmitsNoKnownMinorGivesNoNewestPhp(string $root): void
    {
        $cap = new PhpCap(self::KNOWN, $root);

        self::assertNull($cap->newest());
        self::assertFalse($cap->stopsBelow('^7.0'));
    }

    public function testACapBelowTheNewestMinorStopsAtTheNewestMinorItAdmits(): void
    {
        $cap = new PhpCap(self::KNOWN);

        self::assertSame(['7.4', '8.1', '8.4'], [$cap->stopsAt('^7.0'), $cap->stopsAt('>=7.4, <8.2'), $cap->stopsAt('~8.1.0 || ~8.2.0 || ~8.3.0 || ~8.4.0')]);
        self::assertTrue($cap->stopsBelow('^7.0'));
    }

    /** @return iterable<string, array{string}> */
    public static function notCapped(): iterable
    {
        yield 'a floor above R' => ['>=8.4.1'];
        yield 'a disjunction with an open end' => ['^7.4 || >=8.1'];
        yield 'an open end' => ['>=7.4'];
        yield 'a cap above R' => ['<8.4'];
        yield 'a gap over R' => ['~8.2.0 || ~8.4.0'];
    }

    /**
     * Under a root that allows up to 8.3, each requirement admits something at or above `8.3.0`.
     *
     * @dataProvider notCapped
     */
    #[DataProvider('notCapped')]
    public function testARequirementThatAdmitsSomethingAtOrAboveTheNewestMinorIsNotCapped(string $requires): void
    {
        $cap = new PhpCap(self::KNOWN, '~8.1.0 || ~8.2.0 || ~8.3.0');

        self::assertFalse($cap->stopsBelow($requires));
        self::assertNull($cap->stopsAt($requires));
    }

    public function testARequirementThatDoesNotParseIsNotCapped(): void
    {
        $cap = new PhpCap(self::KNOWN);

        self::assertSame([false, null], [$cap->stopsBelow('not a constraint'), $cap->stopsAt('not a constraint')]);
    }

    /** A requirement that admits no known minor is not capped either. */
    public function testARequirementThatAdmitsNoKnownMinorIsNotCapped(): void
    {
        self::assertFalse((new PhpCap(self::KNOWN))->stopsBelow('<7.0'));
    }

    public function testARequirementThatStatesNoPhpGivesNoAnswer(): void
    {
        $cap = new PhpCap(self::KNOWN);

        self::assertNull($cap->stopsBelow(null));
        self::assertNull($cap->stopsBelow(''));
        self::assertNull($cap->stopsBelow('  '));
        self::assertNull($cap->admitsNewest(null));
    }

    public function testTheNewestMinorIsAdmittedByAnyPatchOfIt(): void
    {
        $cap = new PhpCap(self::KNOWN);

        self::assertTrue($cap->admitsNewest('~8.5.0'));
        self::assertTrue($cap->admitsNewest('>=8.5.3'));
        self::assertFalse($cap->admitsNewest('<8.5'));
        self::assertNull(PhpCap::none()->admitsNewest('*'));
    }

    public function testARequirementThatStartsAboveTheNewestMinorDoesNotAdmitIt(): void
    {
        self::assertFalse((new PhpCap(self::KNOWN))->admitsNewest('>=8.6'));
    }
}
