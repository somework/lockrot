<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Security;

use Lockrot\Security\Holder;
use Lockrot\Security\RefusedMoves;
use PHPUnit\Framework\TestCase;

final class RefusedMovesTest extends TestCase
{
    public function testARefusalGoesToACopy(): void
    {
        $none = RefusedMoves::none();

        $one = $none->with('^2.0', []);

        self::assertSame([0, 1], [$none->count(), $one->count()]);
        self::assertFalse($none->refuses('2.1.0.0'));
    }

    public function testAReleaseTakesTheHoldsOfEveryRefusalThatAdmitsIt(): void
    {
        $refused = RefusedMoves::none()
            ->with('^2.0', [new Holder(Holder::ROOT, 'acme/app', null, Holder::REQUIRE, '^1.0')])
            ->with('>=2.1', [new Holder(Holder::PACKAGE, 'acme/tool', '1.0.0', Holder::REQUIRE, '<2.1'), new Holder(Holder::PACKAGE, 'acme/other', '1.0.0', Holder::CONFLICT, '>=2.1')])
            ->with('^3.0', [new Holder(Holder::PACKAGE, 'acme/third', '1.0.0', Holder::REQUIRE, '<3.0')]);

        self::assertSame(['acme/app', 'acme/tool', 'acme/other'], array_map(static fn (Holder $holder): ?string => $holder->package(), $refused->holding('2.1.0.0')));
        self::assertSame([], $refused->holding('1.0.0.0'));
    }
}
