<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Exception\ConfigException;
use Lockrot\Output\Formatters;
use PHPUnit\Framework\TestCase;

final class FormattersTest extends TestCase
{
    public function testAnUnknownFormatIsRefusedByName(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Unknown output format "yaml"');

        Formatters::for('yaml');
    }
}
