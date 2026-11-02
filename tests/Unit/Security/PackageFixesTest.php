<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Security;

use Lockrot\Security\PackageFixes;
use PHPUnit\Framework\TestCase;

final class PackageFixesTest extends TestCase
{
    public function testAnAdvisoryTheScanWasNotGivenIsAnOutOfBoundsError(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionMessage('The release scan was given no advisory PKSA-none.');

        (new PackageFixes([], [], null, null, null))->forAdvisory('PKSA-none');
    }
}
