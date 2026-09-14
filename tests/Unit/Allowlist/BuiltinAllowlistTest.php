<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Allowlist;

use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use PHPUnit\Framework\TestCase;

final class BuiltinAllowlistTest extends TestCase
{
    public function testKnownFinishedPackages(): void
    {
        $list = BuiltinAllowlist::load();
        $now = new \DateTimeImmutable(F::NOW);
        self::assertNotNull($list->match(F::package(['name' => 'ralouphie/getallheaders', 'version' => '3.0.3']), null, $now));
        self::assertNotNull($list->match(F::package(['name' => 'psr/cache', 'version' => '3.0.0']), null, $now));
        self::assertNotNull($list->match(F::package(['name' => 'symfony/polyfill-mbstring', 'version' => 'v1.31.0']), null, $now));
        self::assertNotNull($list->match(F::package(['name' => 'symfony/twig-pack', 'version' => 'v1.0.1']), null, $now));
        self::assertNotNull($list->match(F::package(['name' => 'paragonie/random_compat', 'version' => 'v9.99.100']), null, $now));
        self::assertNull($list->match(F::package(['name' => 'paragonie/random_compat', 'version' => 'v2.0.21']), null, $now));
        self::assertNull($list->match(F::package(['name' => 'phpzip/phpzip', 'version' => '2.0.8']), null, $now));
    }
}
