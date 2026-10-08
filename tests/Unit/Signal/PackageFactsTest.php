<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal;

use Lockrot\Security\PackageFixes;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use PHPUnit\Framework\TestCase;

final class PackageFactsTest extends TestCase
{
    public function testWithFixesReturnsACopyAndKeepsTheFactsItWasCalledOn(): void
    {
        $facts = F::facts(F::package());
        $fixes = new PackageFixes([], [], null, null, null);

        $withFixes = $facts->withFixes($fixes);

        self::assertNotSame($facts, $withFixes);
        self::assertSame($fixes, $withFixes->fixes());
        self::assertNull($facts->fixes());
    }
}
