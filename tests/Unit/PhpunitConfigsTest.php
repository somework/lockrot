<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The three PHPUnit configurations exclude the same groups, so the default suite, the PHPUnit 9 legs
 * and the core coverage gate skip the same slow tests.
 */
final class PhpunitConfigsTest extends TestCase
{
    private const CONFIGS = ['phpunit.xml.dist', 'phpunit9.xml.dist', 'phpunit.core-coverage.xml.dist'];

    public function testTheConfigurationsExcludeTheSameGroups(): void
    {
        $excluded = [];
        foreach (self::CONFIGS as $config) {
            $xml = simplexml_load_file(__DIR__.'/../../'.$config);
            self::assertNotFalse($xml, $config);
            $excluded[$config] = array_map('strval', $xml->xpath('/phpunit/groups/exclude/group') ?: []);
        }

        self::assertContains('sweep', $excluded['phpunit.xml.dist']);
        self::assertSame($excluded['phpunit.xml.dist'], $excluded['phpunit9.xml.dist']);
        self::assertSame($excluded['phpunit.xml.dist'], $excluded['phpunit.core-coverage.xml.dist']);
    }
}
