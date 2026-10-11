<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Analyzer\RunSettings;
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
use Lockrot\Output\JsonFormatter;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\RootRecount;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\VerdictEngine;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * RootRecountTest over every fixture app and skeleton, with and without packages-dev: the built-in
 * allowlist accepts flags and the recorded repositories name replacements, which the cases do not.
 *
 * It covers nothing: it analyses every fixture lock twice, which outlasts the timeout of a mutation
 * run. Report2RootTest and ScoreRulesUsedTest kill those mutants.
 *
 * @coversNothing
 *
 * @group covers-nothing
 */
#[CoversNothing]
#[Group('covers-nothing')]
final class AppRootRecountTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../fixtures/';
    private const NOW = '2026-09-14T00:00:00+00:00';

    public function testEveryRootBlockOfEveryAppRecountsFromItsFindings(): void
    {
        $dirs = array_merge(glob(self::FIXTURES.'apps/*', \GLOB_ONLYDIR) ?: [], glob(self::FIXTURES.'skeletons/*', \GLOB_ONLYDIR) ?: []);
        sort($dirs);
        self::assertNotSame([], $dirs);
        $server = FixtureRepositoryServer::fromLockFiles(array_map(static fn (string $dir): string => $dir.'/composer.lock', $dirs));
        $server->start();
        $mismatches = [];
        $seen = ['accepted' => 0, 'replacement' => 0, 'reaching' => 0, 'libyears' => 0];
        try {
            foreach ($dirs as $dir) {
                foreach ([false, true] as $dev) {
                    $document = self::document($server, $dir, $dev);
                    foreach (RootRecount::mismatches($document) as $mismatch) {
                        $mismatches[] = basename($dir).($dev ? ' --dev ' : ' ').$mismatch;
                    }
                    foreach (JsonPath::arrayAt($document, ['flags']) as $flag) {
                        $seen['accepted'] += \is_array($flag) && \is_array($flag['accepted'] ?? null) ? JsonPath::intAt($flag, ['accepted', 'all']) : 0;
                    }
                    $seen['replacement'] += JsonPath::intAt($document, ['abandoned', 'with_replacement']) + JsonPath::intAt($document, ['abandoned', 'with_suggestion']);
                    $seen['reaching'] += JsonPath::intAt($document, ['gate', 'reaching']);
                    $seen['libyears'] += \is_float(JsonPath::arrayAt($document, ['libyears'])['total'] ?? null) ? 1 : 0;
                }
            }
        } finally {
            $server->stop();
        }

        self::assertSame([], $mismatches);
        self::assertSame([], array_keys(array_filter($seen, static fn (int $count): bool => $count === 0)), 'the fixtures make every counted fact occur');
    }

    /** @return array<mixed, mixed> */
    private static function document(FixtureRepositoryServer $server, string $dir, bool $dev): array
    {
        $clock = Clock::fixed(self::NOW);
        $auth = ForgeAuth::withTokens(new Tokens('recorded', null));
        $project = ProjectConfig::fromFile($dir.'/composer.json');
        $analyzer = new Analyzer(
            new RepositoryMetadataLoader($server->repositories(), $clock),
            new ActivityClient(new RecordedHttpClient(self::FIXTURES.'http/github'), $auth),
            new ActivityFetchPlanner($auth),
            new RepoLocator(),
            BuiltinAllowlist::load(),
            SignalSet::default($clock, new Thresholds(), '8.4', PhpReleaseDates::load(), $project->requirePhp()),
            new VerdictEngine(),
            $clock,
            false
        );
        $run = new RunSettings(null, null, '8.4', RunSettings::SOURCE_OPTION, null, FailOn::fromString('medium'), RunSettings::SOURCE_OPTION, null, null, false, 'check', $dev);
        $report = $analyzer->analyze(LockFile::fromFile($dir.'/composer.lock'), $project, $dev)->withRun($run);
        $document = json_decode((new JsonFormatter())->format($report), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($document);

        return $document;
    }
}
