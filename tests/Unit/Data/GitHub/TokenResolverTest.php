<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\GitHub;

use Lockrot\Data\GitHub\TokenResolver;
use PHPUnit\Framework\TestCase;

final class TokenResolverTest extends TestCase
{
    public function testOrder(): void
    {
        self::assertSame('a', TokenResolver::resolve(['LOCKROT_GITHUB_TOKEN' => 'a', 'GITHUB_TOKEN' => 'b'], 'c'));
        self::assertSame('b', TokenResolver::resolve(['GITHUB_TOKEN' => 'b'], 'c'));
        self::assertSame('c', TokenResolver::resolve([], 'c'));
        self::assertNull(TokenResolver::resolve(['GITHUB_TOKEN' => ''], null));
    }
}
