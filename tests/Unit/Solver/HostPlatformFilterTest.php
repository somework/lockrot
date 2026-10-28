<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Solver;

use Lockrot\Solver\HostPlatformFilter;
use PHPUnit\Framework\TestCase;

final class HostPlatformFilterTest extends TestCase
{
    /**
     * @dataProvider requirements
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('requirements')]
    public function testTheFilterIgnoresAHostRequirementThatConfigPlatformDoesNotSet(string $req, bool $ignored): void
    {
        $filter = new HostPlatformFilter(['lib-icu', 'ext-intl', 'php']);

        self::assertSame([$ignored, $ignored], [$filter->isIgnored($req), $filter->isUpperBoundIgnored($req)]);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function requirements(): iterable
    {
        yield 'an extension' => ['ext-gd', true];
        yield 'a library' => ['lib-curl', true];
        yield 'a host feature' => ['php-zts', true];
        yield 'an extension that config.platform sets' => ['ext-intl', false];
        yield 'a library that config.platform sets' => ['lib-icu', false];
        yield 'php' => ['php', false];
        yield 'php-64bit' => ['php-64bit', false];
        yield 'composer-plugin-api' => ['composer-plugin-api', false];
        yield 'a package' => ['acme/lib', false];
    }
}
