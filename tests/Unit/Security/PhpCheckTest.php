<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Security;

use Lockrot\Security\PhpCheck;
use Lockrot\Signal\PhpFloor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The `php_check`: what a release needs against the project's floor point. */
final class PhpCheckTest extends TestCase
{
    /**
     * @dataProvider raises
     */
    #[DataProvider('raises')]
    public function testARaiseNamesTheReleasesPointAndItsSize(?string $requirePhp, string $releasePhp, ?string $raiseTo, ?string $raiseSize): void
    {
        $check = PhpCheck::of($releasePhp, new PhpFloor('8.4', $requirePhp));

        self::assertSame([$raiseTo, $raiseSize], [$check->toArray()['raise_to'], $check->toArray()['raise_size']]);
    }

    /** @return iterable<string, array{?string, string, ?string, ?string}> */
    public static function raises(): iterable
    {
        yield 'a major' => ['>=7.4', '>=8.1', '>=8.1', PhpCheck::MAJOR];
        yield 'a minor' => ['>=8.1', '>=8.2', '>=8.2', PhpCheck::MINOR];
        yield 'a patch' => ['>=8.1.0', '>=8.1.5', '>=8.1.5', PhpCheck::PATCH];
        yield 'a release the project admits' => ['>=8.2', '>=8.1', null, null];
        yield 'no project floor' => [null, '>=8.2', null, null];
        yield 'alternatives that skip the project floor' => ['>=7.4', '7.1.* || >=8.1', '>=8.1', PhpCheck::MAJOR];
        yield 'alternatives with a gap at the project floor' => ['^7.4 || ^8.0', '>=7.1,!=7.4.0', '>=7.4.1', PhpCheck::PATCH];
        yield 'a range that holds no stable version' => ['>=7.4', '>7.4.0 <7.4.1 || >=8.1', '>=8.1', PhpCheck::MAJOR];
        yield 'two holes above the project floor' => ['>=7.4', '>=7.3 !=7.4.0 !=7.4.1', '>=7.4.2', PhpCheck::PATCH];
        yield 'a patch suffix at the project floor' => ['>=7.4', '>=7.4.0-p1', '>=7.4.1', PhpCheck::PATCH];
        yield 'a dev branch' => ['>=7.4', '7.4.x-dev', null, null];
    }
}
