<?php

declare(strict_types=1);

namespace Lockrot\Tests\E2E;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** @group e2e */
#[Group('e2e')]
final class PharTest extends TestCase
{
    public function testPharRunsOnFixtureDirectory(): void
    {
        $phar = \dirname(__DIR__, 2).'/build/lockrot.phar';
        if (!is_file($phar)) {
            self::markTestSkipped('build/lockrot.phar not built; run build/build-phar.sh');
        }
        $process = new Process(['php', $phar, '-d', \dirname(__DIR__).'/fixtures/skeletons/laravel', '--format=json', '--target-php=8.4']);
        $process->setTimeout(300)->run();
        $json = json_decode($process->getOutput(), true);
        self::assertIsArray($json, $process->getErrorOutput());
        $counts = $json['counts'];
        self::assertIsArray($counts);
        self::assertSame(0, $counts['abandoned']);
    }
}
