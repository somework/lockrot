<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The corpus differential compares lockrot with the model documents of `tests/fixtures/corpus/model-report-2/`, which the
 * model wrote from `tests/fixtures/corpus/cache/`. Its provenance check fails when either one is not the one that
 * `provenance.json` describes, so a re-recorded cache or an edited model document cannot pass for the recorded one.
 */
final class CorpusProvenanceTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';
    private const MODEL = self::ROOT.'/tests/fixtures/corpus/model-report-2';
    private const CACHE = self::ROOT.'/tests/fixtures/corpus/cache';

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/lockrot-provenance-'.bin2hex(random_bytes(8));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            (new Process(['rm', '-rf', $this->dir]))->run();
        }
    }

    public function testTheCommittedModelAndCacheMatchTheirProvenance(): void
    {
        $check = self::check(self::MODEL, self::CACHE);

        self::assertSame(0, $check->getExitCode(), $check->getErrorOutput());
    }

    public function testOneChangedByteInAModelDocumentFailsTheCheck(): void
    {
        self::copyTree(self::MODEL, $this->dir.'/model');
        self::changeOneByte($this->dir.'/model/koel_koel.json', '"text": "');

        $check = self::check($this->dir.'/model', self::CACHE);

        self::assertSame(1, $check->getExitCode(), $check->getErrorOutput());
        self::assertStringContainsString('the model document koel_koel.json differs from its sha256', $check->getErrorOutput());
    }

    public function testOneChangedByteInACacheFileFailsTheCheck(): void
    {
        self::copyTree(self::CACHE, $this->dir.'/cache');
        $files = glob($this->dir.'/cache/github/*.json') ?: [];
        self::assertNotSame([], $files, 'the cache holds GitHub answers');
        self::changeOneByte($files[0], '"body":"');

        $check = self::check(self::MODEL, $this->dir.'/cache');

        self::assertSame(1, $check->getExitCode(), $check->getErrorOutput());
        self::assertStringContainsString('its digest differs from provenance.json', $check->getErrorOutput());
    }

    private static function check(string $model, string $cache): Process
    {
        $process = new Process([\PHP_BINARY, self::ROOT.'/tools/corpus/differential.php', '--provenance', $model, $cache]);
        $process->run();

        return $process;
    }

    /** Changes the byte after the first $marker, so that the file stays the same size. */
    private static function changeOneByte(string $path, string $marker): void
    {
        $contents = (string) file_get_contents($path);
        $at = strpos($contents, $marker);
        self::assertNotFalse($at, $path.' holds '.$marker);
        $at += \strlen($marker);
        $contents[$at] = $contents[$at] === 'X' ? 'Y' : 'X';
        file_put_contents($path, $contents);
    }

    private static function copyTree(string $from, string $to): void
    {
        if (!is_dir($to)) {
            mkdir($to, 0777, true);
        }
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir($from.'/'.$entry)) {
                self::copyTree($from.'/'.$entry, $to.'/'.$entry);
            } else {
                copy($from.'/'.$entry, $to.'/'.$entry);
            }
        }
    }
}
