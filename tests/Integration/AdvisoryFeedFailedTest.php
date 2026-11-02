<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Analyzer\RunNote;
use Lockrot\Tests\Support\FixtureCliRun;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\TestCase;

/**
 * drupal on the corpus cache with the metadata of packages.drupal.org served and only its advisory
 * API unreachable: the run reads every package's metadata and writes no `clear` after the failed
 * lookup. The corpus run drupal_drupal.feed-failed cannot show this, because there the whole
 * repository is unreachable (tools/corpus/README.md).
 */
final class AdvisoryFeedFailedTest extends TestCase
{
    private const APP = __DIR__.'/../fixtures/apps/drupal_drupal';
    private const CACHE = __DIR__.'/../fixtures/corpus/cache';
    private const ENTRY = __DIR__.'/../../tools/corpus/lockrot-without-move-check';
    /** The recording day of the corpus cache. */
    private const CLOCK = '2026-10-14T00:00:00+00:00';

    public function testOnlyAFailedAdvisoryLookupLeavesTheMetadataReadAndWritesNoClear(): void
    {
        $run = FixtureCliRun::forRecording(self::APP, self::CACHE, true, true);
        try {
            $process = $run->run(self::ENTRY, self::APP, self::CLOCK, ['--target-php=8.4', '--all', '--format=json']);
        } finally {
            $run->stop();
        }

        self::assertContains($process->getExitCode(), [0, 1], $process->getErrorOutput());
        $report = JsonPath::decoded(json_decode($process->getOutput(), true));
        $codes = array_column(JsonPath::arrayAt($report, ['note_details']), 'code');
        self::assertContains(RunNote::ADVISORIES_UNAVAILABLE, $codes);
        self::assertNotContains(RunNote::METADATA_UNAVAILABLE, $codes);
        $statuses = [];
        foreach (JsonPath::arrayAt($report, ['findings']) as $finding) {
            $security = JsonPath::arrayAt(JsonPath::decoded($finding), ['security']);
            $statuses[] = [$security['status'] ?? null, $security['unchecked_reason'] ?? null];
        }
        self::assertNotContains('clear', array_column($statuses, 0));
        self::assertContains(['unchecked', 'lookup_failed'], $statuses);
    }
}
