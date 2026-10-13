<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Each PHPUnit configuration in CONFIGS excludes the groups of phpunit.xml.dist. The default suite,
 * the PHPUnit 9 legs and the core coverage gate then skip the same slow tests. The core coverage
 * gate also excludes covers-nothing, whose tests collect no coverage.
 */
final class PhpunitConfigsTest extends TestCase
{
    private const CONFIGS = ['phpunit.xml.dist', 'phpunit9.xml.dist', 'phpunit.core-coverage.xml.dist'];

    public function testTheConfigurationsExcludeTheSameGroups(): void
    {
        $excluded = [];
        foreach (self::CONFIGS as $config) {
            $document = new \DOMDocument();
            self::assertTrue($document->load(__DIR__.'/../../'.$config), $config);
            $excluded[$config] = [];
            foreach ((new \DOMXPath($document))->query('/phpunit/groups/exclude/group') ?: [] as $group) {
                $excluded[$config][] = (string) $group->nodeValue;
            }
        }

        self::assertContains('sweep', $excluded['phpunit.xml.dist']);
        self::assertSame($excluded['phpunit.xml.dist'], $excluded['phpunit9.xml.dist']);
        self::assertSame(array_merge($excluded['phpunit.xml.dist'], ['covers-nothing']), $excluded['phpunit.core-coverage.xml.dist']);
    }
}
