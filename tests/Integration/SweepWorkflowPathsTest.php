<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The default suite runs a sample of each sweep. A pull request that changes a test of the sweep
 * group or a sweep support class gets the whole stream only when sweep.yml's paths filter names it.
 *
 * @coversNothing
 *
 * @group covers-nothing
 */
#[CoversNothing]
#[Group('covers-nothing')]
final class SweepWorkflowPathsTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    public function testEverySweepTestAndSweepSupportFileStartsTheSweepWorkflow(): void
    {
        $patterns = self::pullRequestPaths();
        $files = array_merge(
            array_filter(self::phpFiles('tests'), static fn (string $path): bool => preg_match("/^\\s*#\\[Group\\('sweep'\\)\\]/m", (string) file_get_contents(self::ROOT.'/'.$path)) === 1),
            array_filter(self::phpFiles('tests/Support'), static fn (string $path): bool => strpos(basename($path), 'Sweep') !== false),
        );
        sort($files);
        self::assertContains('tests/Support/GateSweep.php', $files);
        self::assertContains('tests/Integration/GateAxisSweepTest.php', $files);

        $unmatched = array_values(array_filter($files, static function (string $path) use ($patterns): bool {
            foreach ($patterns as $pattern) {
                if (fnmatch($pattern, $path, FNM_PATHNAME) || (substr($pattern, -3) === '/**' && strpos($path, substr($pattern, 0, -2)) === 0)) {
                    return false;
                }
            }

            return true;
        }));

        self::assertSame([], $unmatched, 'add each file to the pull_request paths of .github/workflows/sweep.yml');
    }

    /**
     * @return list<string>
     */
    private static function pullRequestPaths(): array
    {
        $workflow = (string) file_get_contents(self::ROOT.'/.github/workflows/sweep.yml');
        self::assertSame(1, preg_match('/^  pull_request:\n    paths:\n((?:      - .+\n)+)/m', $workflow, $block));
        preg_match_all("/^      - '([^']+)'$/m", $block[1], $paths);
        self::assertNotSame([], $paths[1]);

        return $paths[1];
    }

    /**
     * @return list<string>
     */
    private static function phpFiles(string $directory): array
    {
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT.'/'.$directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php' && strpos($file->getPathname(), '/fixtures/') === false) {
                $found[] = $directory.substr($file->getPathname(), \strlen(self::ROOT.'/'.$directory));
            }
        }

        return $found;
    }
}
