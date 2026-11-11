<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Security;

use Lockrot\Security\Gets;
use Lockrot\Security\PhpCheck;
use Lockrot\Signal\PhpFloor;
use PHPUnit\Framework\TestCase;

final class GetsTest extends TestCase
{
    public function testACappedGetsNamesNoVersionAndClearsNothing(): void
    {
        $gets = Gets::capped(self::check('>=8.1'));

        self::assertSame([true, null, [], false], [$gets->isCapped(), $gets->version(), $gets->clears(), $gets->clearsAll()]);
    }

    public function testAtAReleaseOfACappedGetsIsNotCappedAndLeavesTheCappedGetsAsItIs(): void
    {
        $gets = Gets::capped(self::check('>=8.1'));

        $at = $gets->at('1.1.0');

        self::assertSame([false, '1.1.0'], [$at->isCapped(), $at->version()]);
        self::assertSame([true, null], [$gets->isCapped(), $gets->version()]);
    }

    public function testAtAKnownReleaseLeavesThatReleasesFactsAsTheyAre(): void
    {
        $known = new Gets('1.1.0', self::check('>=7.4'), ['A'], true);
        $gets = (new Gets('1.2.0', self::check('>=8.1'), ['A', 'B'], true))->withReleases(['1.1.0.0' => $known]);

        $at = $gets->at('v1.1.0');

        self::assertSame(['v1.1.0', '>=7.4'], [$at->version(), $at->phpCheck()->requires()]);
        self::assertSame('1.1.0', $known->version());
    }

    public function testWithReleasesLeavesTheOriginalWithoutThem(): void
    {
        $original = new Gets('1.2.0', self::check('>=8.1'), ['A'], true);

        $original->withReleases(['1.1.0.0' => new Gets('1.1.0', self::check('>=7.4'), [], false)]);

        self::assertSame('>=8.1', $original->at('1.1.0')->phpCheck()->requires());
    }

    private static function check(string $php): PhpCheck
    {
        return PhpCheck::of($php, new PhpFloor('8.4', '>=7.4'));
    }
}
