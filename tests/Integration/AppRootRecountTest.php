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
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * RootRecountTest over every fixture app and skeleton, with and without packages-dev: the built-in
 * allowlist accepts flags and the recorded repositories name replacements, which the cases do not.
 *
 * It covers nothing: it analyses every fixture lock twice, which outlasts the timeout of a mutation
 * run. Report2RootTest and ScoreRulesUsedTest kill those mutants. The group schema-sweep gives this
 * class to the schema-sweep process of ci.yml.
 *
 * @coversNothing
 *
 * @group covers-nothing
 * @group schema-sweep
 */
#[CoversNothing]
#[Group('covers-nothing')]
#[Group('schema-sweep')]
final class AppRootRecountTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../fixtures/';
    private const NOW = '2026-09-14T00:00:00+00:00';

    public function testTheRowsAreEveryFixtureAppAndSkeleton(): void
    {
        $dirs = [];
        foreach (['apps/', 'skeletons/'] as $group) {
            foreach (scandir(self::FIXTURES.$group) ?: [] as $entry) {
                if ($entry[0] !== '.' && is_dir(self::FIXTURES.$group.$entry)) {
                    $dirs[] = $group.$entry;
                }
            }
        }
        sort($dirs);

        self::assertSame($dirs, array_keys(iterator_to_array(self::fixtureApps())));
    }

    public function testTheRowsHoldAFixtureApp(): void
    {
        self::assertNotSame([], iterator_to_array(self::fixtureApps()));
    }

    /** @return iterable<string, array{string}> every fixture app and skeleton, by its directory below tests/fixtures */
    public static function fixtureApps(): iterable
    {
        foreach (self::dirs() as $dir) {
            yield substr($dir, \strlen(self::FIXTURES)) => [$dir];
        }
    }

    /**
     * @dataProvider fixtureApps
     */
    #[DataProvider('fixtureApps')]
    public function testEveryRootBlockOfTheAppRecountsFromItsFindings(string $dir): void
    {
        $server = self::server();
        $mismatches = [];
        try {
            foreach ([false, true] as $dev) {
                foreach (RootRecount::mismatches(self::document($server, $dir, $dev)) as $mismatch) {
                    $mismatches[] = basename($dir).($dev ? ' --dev ' : ' ').$mismatch;
                }
            }
        } finally {
            $server->stop();
        }

        self::assertSame([], $mismatches);
    }

    /** It stops when the set is complete, because the rows already analyse every lock. */
    public function testTheFixturesMakeEveryCountedFactOccur(): void
    {
        $server = self::server();
        $seen = ['accepted' => 0, 'replacement' => 0, 'reaching' => 0, 'libyears' => 0];
        try {
            foreach (self::dirs() as $dir) {
                foreach ([false, true] as $dev) {
                    $document = self::document($server, $dir, $dev);
                    foreach (JsonPath::arrayAt($document, ['flags']) as $flag) {
                        $seen['accepted'] += \is_array($flag) && \is_array($flag['accepted'] ?? null) ? JsonPath::intAt($flag, ['accepted', 'all']) : 0;
                    }
                    $seen['replacement'] += JsonPath::intAt($document, ['abandoned', 'with_replacement']) + JsonPath::intAt($document, ['abandoned', 'with_suggestion']);
                    $seen['reaching'] += JsonPath::intAt($document, ['gate', 'reaching']);
                    $seen['libyears'] += \is_float(JsonPath::arrayAt($document, ['libyears'])['total'] ?? null) ? 1 : 0;
                }
                if (!\in_array(0, $seen, true)) {
                    break;
                }
            }
        } finally {
            $server->stop();
        }

        self::assertSame([], array_keys(array_filter($seen, static fn (int $count): bool => $count === 0)), 'the fixtures make every counted fact occur');
    }

    /** @return list<string> */
    private static function dirs(): array
    {
        $dirs = array_merge(glob(self::FIXTURES.'apps/*', \GLOB_ONLYDIR) ?: [], glob(self::FIXTURES.'skeletons/*', \GLOB_ONLYDIR) ?: []);
        sort($dirs);

        return $dirs;
    }

    /** Every fixture lock served at once, whichever app a test reads, so each run reads the same metadata. */
    private static function server(): FixtureRepositoryServer
    {
        $server = FixtureRepositoryServer::fromLockFiles(array_map(static fn (string $dir): string => $dir.'/composer.lock', self::dirs()));
        $server->start();

        return $server;
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
            $clock,
            false
        );
        $run = new RunSettings(null, null, '8.4', RunSettings::SOURCE_OPTION, null, FailOn::parse('medium'), RunSettings::SOURCE_OPTION, null, null, false, 'check', $dev);
        $report = $analyzer->analyze(LockFile::fromFile($dir.'/composer.lock'), $project, $dev)->withRun($run);
        $document = json_decode((new JsonFormatter())->format($report), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($document);

        return $document;
    }
}
