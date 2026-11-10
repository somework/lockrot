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

    public function testAReleaseAboveTheNewestIsNamed(): void
    {
        self::assertSame('3.0.0', MoveCheck::resolves(MoveCheck::INLINE, '3.0.0')->installsAbove('2.12.3'));
        self::assertNull(MoveCheck::resolves(MoveCheck::INLINE, '2.12.3')->installsAbove('v2.12.3'));
        self::assertNull(MoveCheck::resolves(MoveCheck::INLINE, '2.12.2')->installsAbove('2.12.3'));
        self::assertNull(MoveCheck::resolves(MoveCheck::INLINE, '3.0.0')->installsAbove(null));
        self::assertNull(MoveCheck::refused()->installsAbove('2.12.3'));
        self::assertNull(MoveCheck::resolves(MoveCheck::INLINE, 'dev-main')->installsAbove('not a version'));
    }

    /** @return iterable<string, array{?string, bool}> */
    public static function versions(): iterable
    {
        yield 'a branch alias' => ['1.x-dev', false];
        yield 'the default branch alias' => ['9999999-dev', false];
        yield 'a normalized branch alias' => ['1.9999999.9999999.9999999-dev', false];
        yield 'a branch' => ['dev-main', false];
        yield 'a tag with a v' => ['v1.2.3', true];
        yield 'a pre-release' => ['1.0.0-beta1', true];
        yield 'no version' => [null, false];
    }

    /** @dataProvider versions */
    #[\PHPUnit\Framework\Attributes\DataProvider('versions')]
    public function testOnlyAVersionThatIsNoDevVersionIsARelease(?string $version, bool $release): void
    {
        self::assertSame($release, MoveCheck::isRelease($version));
    }

    /** Ruling R17-1: the version that no form gets past is no release that `security.gets` or "(gets …)" can name. */
    public function testACheckThatInstallsNoReleaseNamesItsVersionButNoReleaseBesideTheNewest(): void
    {
        $check = MoveCheck::installsNoRelease('1.0.0');

        self::assertSame(['result' => 'not_checked', 'form' => null, 'reason' => MoveCheck::INSTALLS_NO_RELEASE, 'installs' => '1.0.0'], $check->jsonSerialize());
        self::assertNull($check->installsBelow('1.0.9'));
        self::assertNull(MoveCheck::installsNoRelease('9.0.0')->installsAbove('1.0.9'));
        self::assertSame("Composer's solver does not reach the release", MoveCheck::reasonWords(MoveCheck::INSTALLS_NO_RELEASE));
    }

    /** SPEC §7.9.21 holds the words, and the update-now note of §6.2 reads them. */
    public function testAnIncompleteAdvisoryLookupNamesThePackageThatTheLookupDidNotCover(): void
    {
        self::assertSame(
            "update now: not checked with Composer's solver (the advisory lookup did not cover a package in the result), so lockrot writes no command for a/b",
            RunNote::updateNowNotChecked(MoveCheck::ADVISORY_LOOKUP_INCOMPLETE, ['a/b'])->text()
        );
    }
}
