<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Support;

use Lockrot\Tests\Support\Contract\StagedRecording;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class StagedRecordingTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/lockrot-staged-'.bin2hex(random_bytes(8));
        foreach (['one', 'two'] as $scenario) {
            mkdir($this->dir.'/'.$scenario.'/check', 0777, true);
            file_put_contents($this->dir.'/'.$scenario.'/check/old.json', 'old '.$scenario);
            file_put_contents($this->dir.'/'.$scenario.'/README.md', 'kept '.$scenario);
        }
    }

    protected function tearDown(): void
    {
        (new Process(['chmod', '-R', 'u+w', $this->dir]))->run();
        (new Process(['rm', '-rf', $this->dir]))->run();
    }

    public function testAScenarioThatMissesItsFloorsLeavesEveryScenarioAsItWas(): void
    {
        $before = $this->tree();

        try {
            StagedRecording::replace(
                ['one' => $this->dir.'/one/', 'two' => $this->dir.'/two/'],
                static fn (string $id): array => ['check/new.json' => 'new '.$id],
                static fn (string $id): array => $id === 'two' ? ['a floor'] : [],
                ['check'],
            );
            self::fail('a missed floor stops the recording');
        } catch (\UnexpectedValueException $missed) {
            self::assertStringContainsString('two misses the floors: a floor', $missed->getMessage());
        }

        self::assertSame($before, $this->tree());
    }

    public function testEveryScenarioIsReplacedWhenAllMeetTheirFloors(): void
    {
        StagedRecording::replace(
            ['one' => $this->dir.'/one/', 'two' => $this->dir.'/two/'],
            static fn (string $id): array => ['check/new.json' => 'new '.$id, 'provenance.json' => '{}'],
            static fn (string $id): array => [],
            ['check', 'provenance.json'],
        );

        self::assertSame([
            'one/README.md' => 'kept one',
            'one/check/new.json' => 'new one',
            'one/provenance.json' => '{}',
            'two/README.md' => 'kept two',
            'two/check/new.json' => 'new two',
            'two/provenance.json' => '{}',
        ], $this->tree());
        self::assertSame(['one', 'two'], $this->entries());
    }

    public function testAMoveThatFailsPutsBackTheScenariosThatAlreadyMoved(): void
    {
        $before = $this->tree();
        chmod($this->dir.'/two', 0555);

        try {
            StagedRecording::replace(
                ['one' => $this->dir.'/one/', 'two' => $this->dir.'/two/'],
                static fn (string $id): array => ['check/new.json' => 'new '.$id],
                static fn (string $id): array => [],
                ['check'],
            );
            self::fail('a move into a read-only scenario fails');
        } catch (\RuntimeException $failed) {
            chmod($this->dir.'/two', 0755);
        }

        self::assertSame($before, $this->tree());
        self::assertSame(['one', 'two'], $this->entries());
    }

    public function testAScenarioWithoutADirectoryGetsOne(): void
    {
        StagedRecording::replace(
            ['three' => $this->dir.'/three/'],
            static fn (string $id): array => ['check/new.json' => 'new '.$id],
            static fn (string $id): array => [],
            ['check'],
        );

        self::assertSame('new three', file_get_contents($this->dir.'/three/check/new.json'));
        self::assertSame(['one', 'three', 'two'], $this->entries());
    }

    /** @return list<string> the entries of the test directory, hidden ones included */
    private function entries(): array
    {
        $entries = array_values(array_diff(scandir($this->dir) ?: [], ['.', '..']));
        sort($entries);

        return $entries;
    }

    /** @return array<string, string> every file below the test directory by its relative path */
    private function tree(): array
    {
        $files = [];
        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[substr($file->getPathname(), \strlen($this->dir) + 1)] = (string) file_get_contents($file->getPathname());
        }
        ksort($files);

        return $files;
    }
}
