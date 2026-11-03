<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Composer writes vendor/composer/*.php of the PHAR from its own source. PharTest and the
 * byte-identical rebuild of ci.yml prove the archive that phar.yml signs only when the three jobs
 * build with one Composer release.
 *
 * @coversNothing
 *
 * @group covers-nothing
 */
#[CoversNothing]
#[Group('covers-nothing')]
final class PharComposerPinTest extends TestCase
{
    private const WORKFLOWS = __DIR__.'/../../.github/workflows/';
    private const BUILDS = [
        'ci.yml' => ['phar', 'phar-reproducible'],
        'phar.yml' => ['phar'],
    ];

    public function testEveryPharBuildPinsOneComposerRelease(): void
    {
        $pins = [];
        foreach (self::BUILDS as $file => $jobs) {
            $workflow = (string) file_get_contents(self::WORKFLOWS.$file);
            foreach ($jobs as $job) {
                preg_match('/^  '.preg_quote($job, '/').':\n(.*?)(?=^  \S|\z)/ms', $workflow, $block);
                preg_match_all("/tools: 'composer:([^']*)'/", $block[1] ?? '', $found);
                $pins[$file.' '.$job] = $found[1];
            }
        }

        $releases = array_unique(array_merge(...array_values($pins)));
        self::assertCount(1, $releases, 'pin one Composer release in each PHAR build: '.json_encode($pins));
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $releases[0], 'pin a Composer release, not a range');
        foreach ($pins as $build => $found) {
            self::assertCount(1, $found, $build.' sets up Composer once');
        }
    }
}
