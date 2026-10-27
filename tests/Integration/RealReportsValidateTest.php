<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Json\Schemas;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\ValidatesJsonSchemas;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * bin/lockrot's documents of real locks, on the live repositories, validate against the schemas that
 * they name. Wallabag 2.6.14 at PHP 7.4 has a move whose installed release clears nothing
 * (SPEC-0.14.md I14, ruling R2-9). Set GITHUB_TOKEN to read repository activity past the anonymous cap.
 *
 * @coversNothing
 *
 * @group network
 * @group covers-nothing
 */
#[CoversNothing]
#[Group('network')]
#[Group('covers-nothing')]
final class RealReportsValidateTest extends TestCase
{
    use ValidatesJsonSchemas;

    private const FIXTURES = __DIR__.'/../fixtures/';
    private const ENTRY = __DIR__.'/../../bin/lockrot';

    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            self::removeTree($dir);
        }
        $this->dirs = [];
    }

    /** @return iterable<string, array{string, string}> */
    public static function locks(): iterable
    {
        yield 'wallabag 2.6.14 at 7.4' => ['sites/wallabag-2.6.14', '7.4'];
        yield 'wallabag 2.6.14 at 8.4' => ['sites/wallabag-2.6.14', '8.4'];
        yield 'laminas at 8.4' => ['skeletons/laminas', '8.4'];
        yield 'mautic at 8.2' => ['apps/mautic_mautic', '8.2'];
        yield 'monica at 8.4' => ['apps/monicahq_monica', '8.4'];
    }

    /**
     * @dataProvider locks
     */
    #[DataProvider('locks')]
    public function testTheReportOfARealLockValidatesAgainstReport2(string $fixture, string $targetPhp): void
    {
        $json = $this->lockrot($fixture, ['--target-php='.$targetPhp, '--all', '--format=json']);

        $this->assertValid(Schemas::REPORT, $json, $fixture.' at '.$targetPhp, true);
    }

    public function testTheExplanationOfAMoveWhoseInstalledReleaseClearsNothingValidatesAgainstExplain2(): void
    {
        $json = $this->lockrot('sites/wallabag-2.6.14', ['--target-php=7.4', '--explain=symfony/google-mailer', '--format=json']);

        $this->assertValid(Schemas::EXPLAIN, $json, 'symfony/google-mailer at 7.4', true);
        $document = json_decode($json, true);
        self::assertIsArray($document);
        $step = JsonPath::arrayAt($document, ['finding', 'next_step']);
        self::assertSame([[], null], [$step['clears'], $step['if_applied']], 'the move installs a release that clears nothing: '.json_encode($step['check']));
    }

    /** @param list<string> $arguments */
    private function lockrot(string $fixture, array $arguments): string
    {
        $home = $this->freshDir('lockrot-real-home-');
        $tmp = $this->freshDir('lockrot-real-tmp-');
        $project = $this->freshDir('lockrot-real-project-');
        copy(self::FIXTURES.$fixture.'/composer.json', $project.'/composer.json');
        copy(self::FIXTURES.$fixture.'/composer.lock', $project.'/composer.lock');

        // The live repositories hold releases after the suite's fixed date, so the run reads the real clock, as the real reports did.
        $env = ['COMPOSER_HOME' => $home, 'COMPOSER_CACHE_DIR' => $home.'/cache', 'TMPDIR' => $tmp, 'LOCKROT_TODAY' => false];
        $process = new Process(array_merge([\PHP_BINARY, self::ENTRY, '-d', $project], $arguments), null, $env);
        $process->setTimeout(900)->run();

        // Exit 1 is a gate that fails. Exit 2 is a run that did not finish.
        self::assertContains($process->getExitCode(), [0, 1], $fixture.': '.$process->getErrorOutput());

        return $process->getOutput();
    }

    private function freshDir(string $prefix): string
    {
        $dir = sys_get_temp_dir().'/'.$prefix.bin2hex(random_bytes(8));
        mkdir($dir, 0777, true);
        $this->dirs[] = $dir;

        return $dir;
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
