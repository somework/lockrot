<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Baseline;

use Lockrot\Baseline\BaselineEntry;
use PHPUnit\Framework\TestCase;

final class BaselineEntryTest extends TestCase
{
    public function testAnEntryRefusesAFlagThatIsNotAMaintenanceFlag(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('a baseline entry stores maintenance flags only, not vulnerable, bogus');

        new BaselineEntry('acme/a', '1.0.0', '2026-01-15', ['abandoned' => '2026-01-15', 'vulnerable' => '2026-01-15', 'bogus' => '2026-01-15'], []);
    }
}
