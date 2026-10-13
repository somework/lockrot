<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Analyzer\Report;
use Lockrot\Clock;
use Lockrot\Data\Forge\ActivityClient;
use Lockrot\Data\Forge\ActivityFetchPlanner;
use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\RepoLocator;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Data\Http\RecordedHttpClient;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\RepositoryMetadataLoader;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The root `rot_depth` of the wallabag fixture, counted again from the written findings' chains and
 * `named_in`, offline.
 *
 * It covers nothing: RotDepthTest kills the mutants of {@see \Lockrot\Analyzer\RotDepth}.
 *
 * @coversNothing
 *
 * @group covers-nothing
 */
#[CoversNothing]
#[Group('covers-nothing')]
final class RotDepthAcceptanceTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../fixtures/';
    private const APP = self::FIXTURES.'apps/wallabag_wallabag/';
    private const NOW = '2026-09-14T00:00:00+00:00';

    public function testTheBlockIsTheGradedFindingsCountedFromTheirChains(): void
    {
        $document = JsonPath::decoded(self::report()->toArray());
        $block = JsonPath::arrayAt($document, ['rot_depth']);
        $graded = array_values(array_filter(JsonPath::arrayAt($document, ['findings']), static fn ($row): bool => \is_array($row) && $row['priority'] !== 'none'));
        self::assertNotSame([], $graded);

        $byDepth = [];
        $reach = ['direct' => 0, 'transitive' => 0, 'unreached' => 0];
        $notInComposerJson = 0;
        $requireDevNotWalked = 0;
        foreach ($graded as $row) {
            self::assertIsArray($row['chain']);
            ++$reach[JsonPath::stringAt($row, ['reach'])];
            if ($row['chain'] !== []) {
                $byDepth[\count($row['chain']) - 1] = ($byDepth[\count($row['chain']) - 1] ?? 0) + 1;
            }
            if ($row['reach'] !== 'direct' && $row['named_in'] === []) {
                ++$notInComposerJson;
            }
            if ($row['reach'] !== 'direct' && $row['named_in'] === ['require-dev']) {
                ++$requireDevNotWalked;
            }
        }
        $max = 0;
        foreach (array_keys($byDepth) as $depth) {
            $max = max($max, $depth);
        }
        $rows = [];
        for ($depth = 0; $depth <= $max; ++$depth) {
            $rows[] = ['depth' => $depth, 'flagged' => $byDepth[$depth] ?? 0];
        }

        self::assertSame(\count($graded), $block['flagged']);
        self::assertSame(JsonPath::intAt($document, ['packages_flagged']), $block['flagged']);
        self::assertSame($reach, $block['by_reach']);
        self::assertSame($rows, $block['by_depth']);
        self::assertSame($max, $block['max_depth']);
        self::assertSame($notInComposerJson, $block['not_in_composer_json']);
        self::assertSame($requireDevNotWalked, $block['require_dev_not_walked']);
        self::assertSame(['unreached' => $reach['unreached'], 'no_composer_json' => 0], $block['unmeasured']);
    }

    /** wallabag names symfony/var-dumper in require-dev, and a production package pulls it in. */
    public function testARequireDevNameReachedThroughProductionIsNotWalked(): void
    {
        $report = self::report();
        $found = array_values(array_filter($report->findings(), static fn ($finding): bool => $finding->package() === 'symfony/var-dumper'));
        self::assertCount(1, $found);
        $finding = $found[0];
        $counted = JsonPath::intAt(JsonPath::decoded($report->toArray()), ['rot_depth', 'require_dev_not_walked']);

        self::assertSame(['require-dev'], $finding->namedIn());
        self::assertFalse($finding->isDirect());
        self::assertNotNull($finding->depth());
        self::assertGreaterThanOrEqual($finding->isGraded() ? 1 : 0, $counted);
    }

    private static function report(): Report
    {
        $server = FixtureRepositoryServer::fromLockFiles([self::APP.'composer.lock']);
        $server->start();
        try {
            $clock = Clock::fixed(self::NOW);
            $auth = ForgeAuth::withTokens(new Tokens('recorded', null));
            $analyzer = new Analyzer(
                new RepositoryMetadataLoader($server->repositories(), $clock),
                new ActivityClient(new RecordedHttpClient(self::FIXTURES.'http/github'), $auth),
                new ActivityFetchPlanner($auth),
                new RepoLocator(),
                BuiltinAllowlist::load(),
                SignalSet::default($clock, new Thresholds(), '8.4', PhpReleaseDates::load()),
                $clock,
                true
            );
            $lock = LockFile::fromFile(self::APP.'composer.lock');

            return $analyzer->analyze($lock, ProjectConfig::fromFile(self::APP.'composer.json'), false);
        } finally {
            $server->stop();
        }
    }
}
