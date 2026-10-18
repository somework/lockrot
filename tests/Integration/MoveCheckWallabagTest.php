<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Package\Loader\RootPackageLoader;
use Composer\Repository\AdvisoryProviderInterface;
use Composer\Repository\RepositoryFactory;
use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Analyzer\Report;
use Lockrot\Clock;
use Lockrot\Data\Advisory\RepositoryAdvisoryLoader;
use Lockrot\Data\Forge\ActivityClient;
use Lockrot\Data\Forge\ActivityFetchPlanner;
use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\RepoLocator;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\RepositoryMetadataLoader;
use Lockrot\Deadline;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Solver\MoveCheckers;
use Lockrot\Tests\Support\FakeHttpClient;
use Lockrot\Tests\Support\FindingRelations;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Verdict\MoveChecks;
use PHPUnit\Framework\TestCase;

/**
 * The lock of the external audit: wallabag 2.6.14 at `config.platform.php` 7.4.29, with its own
 * recorded answers (tests/fixtures/sites/wallabag-2.6.14, provenance.json). Composer refuses the
 * moves of symfony/monolog-bridge and symfony/yaml, and on 2.9 or later the plain
 * `composer update` of each installs a dev snapshot.
 */
final class MoveCheckWallabagTest extends TestCase
{
    private const SITE = __DIR__.'/../fixtures/sites/wallabag-2.6.14/';
    private const NOW = '2026-10-18T00:00:00+00:00';

    private static ?Report $report = null;

    public static function setUpBeforeClass(): void
    {
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            self::markTestSkipped('Composer 2.2 has no AdvisoryProviderInterface: no move reaches a vulnerable package');
        }
        $server = FixtureRepositoryServer::fromLockFiles([self::SITE.'composer.lock'], self::SITE.'http/p2');
        $server->withRecordedAdvisoryApi(self::SITE, self::SITE.'http/advisories');
        $server->start();
        try {
            self::$report = self::analyze($server);
        } finally {
            $server->stop();
        }
    }

    public function testComposerRefusesMonologBridgeAndNamesTheRootRequirementOnHttpKernel(): void
    {
        $move = self::move('symfony/monolog-bridge');

        self::assertSame(['result' => 'refused', 'form' => null, 'reason' => null], $move['check']);
        self::assertSame('upgrade', $move['fix_kind'], 'a refused move is held: SPEC §5.3 classes it upgrade');
        self::assertSame([], self::packageSteps($move), 'no update or require step that Composer refuses');
        self::assertContains(['source' => 'root', 'link' => 'root-require', 'target' => 'symfony/http-kernel', 'constraint' => '^4.4.51'], self::holds($move));
        self::assertContains(['source' => 'package', 'link' => 'require', 'target' => 'symfony/http-kernel', 'constraint' => '^5.3|^6.0'], self::holds($move));
        self::assertContains(['source' => 'package', 'link' => 'conflict', 'target' => 'symfony/http-foundation', 'constraint' => '<5.3'], self::holds($move));
    }

    public function testComposerRefusesYamlForItsConflictWithTheConsoleThatTheRootKeeps(): void
    {
        $move = self::move('symfony/yaml');

        self::assertSame('refused', JsonPath::stringAt($move, ['check', 'result']));
        self::assertSame([], self::packageSteps($move));
        self::assertContains(['source' => 'package', 'link' => 'conflict', 'target' => 'symfony/console', 'constraint' => '<5.3'], self::holds($move));
        self::assertContains(['source' => 'root', 'link' => 'root-require', 'target' => 'symfony/console', 'constraint' => '^4.4.49'], self::holds($move));
    }

    public function testAMoveThatComposerResolvesNamesItsConstraintInline(): void
    {
        $move = self::move('phpseclib/phpseclib');

        self::assertSame(['result' => 'resolves', 'form' => 'inline', 'reason' => null], $move['check']);
        self::assertSame([['composer', 'update', 'phpseclib/phpseclib:^3.0.57']], $move['commands']);
    }

    public function testUpdateNowHoldsOnlyTheMovesThatResolveTogether(): void
    {
        $report = self::report();
        $security = JsonPath::arrayAt($report, ['security']);
        $members = JsonPath::arrayAt($security, ['update_now']);

        self::assertContains('phpseclib/phpseclib', $members);
        self::assertNotContains('symfony/monolog-bridge', $members);
        self::assertNotContains('symfony/yaml', $members);
        self::assertContains('phpseclib/phpseclib:^3.0.57', JsonPath::arrayAt($security, ['update_now_command']));
        self::assertSame(['resolves', true], [JsonPath::stringAt($report, ['run', 'move_check', 'baseline']), JsonPath::arrayAt($report, ['run', 'move_check'])['ignores_extensions']]);
    }

    /** The relations of the move check hold on every move of the lock. */
    public function testNoMovePrintsAStepThatItsCheckRefuses(): void
    {
        $broken = array_values(array_filter(FindingRelations::violations(self::report()), static fn (string $violation): bool => strpos($violation, 'next_step:') !== false));

        self::assertSame([], $broken);
    }

    /** @return array<mixed, mixed> */
    private static function report(): array
    {
        self::assertNotNull(self::$report);

        return JsonPath::decoded(self::$report->toArray());
    }

    /** @return array<mixed, mixed> */
    private static function move(string $package): array
    {
        foreach (JsonPath::arrayAt(self::report(), ['findings']) as $finding) {
            if (\is_array($finding) && $finding['package'] === $package) {
                return JsonPath::arrayAt($finding, ['next_step']);
            }
        }
        self::fail($package.' has no finding');
    }

    /**
     * @param array<mixed, mixed> $move
     *
     * @return list<array<mixed, mixed>>
     */
    private static function packageSteps(array $move): array
    {
        return array_values(array_filter(JsonPath::arrayAt($move, ['commands']), static fn ($argv): bool => \is_array($argv) && \in_array($argv[1], ['update', 'require'], true)));
    }

    /**
     * @param array<mixed, mixed> $move
     *
     * @return list<array<string, mixed>>
     */
    private static function holds(array $move): array
    {
        return array_values(array_map(static fn ($hold): array => \is_array($hold) ? array_intersect_key($hold, array_flip(['source', 'link', 'target', 'constraint'])) : [], JsonPath::arrayAt($move, ['held_by'])));
    }

    private static function analyze(FixtureRepositoryServer $server): Report
    {
        $clock = Clock::fixed(self::NOW);
        $auth = ForgeAuth::withTokens(new Tokens(null, null));
        $json = JsonPath::decodeFile(self::SITE.'composer.json');
        $config = $server->config();
        $config->merge(['config' => ['platform' => JsonPath::arrayAt($json, ['config', 'platform'])]]);
        $repositories = $server->repositories();
        $io = new NullIO();
        /** @var array<string, mixed> $json */
        $root = (new RootPackageLoader(RepositoryFactory::manager($io, $config, Factory::createHttpDownloader($io, $config)), $config, null, null, $io))->load($json + ['version' => '1.0.0']);
        $lock = LockFile::fromFile(self::SITE.'composer.lock');
        $project = ProjectConfig::fromFile(self::SITE.'composer.json');
        $analyzer = (new Analyzer(
            new RepositoryMetadataLoader($repositories, $clock),
            new ActivityClient(new FakeHttpClient(), $auth),
            new ActivityFetchPlanner($auth),
            new RepoLocator(),
            BuiltinAllowlist::load(),
            SignalSet::default($clock, new Thresholds(), '7.4', PhpReleaseDates::load(), $project->requirePhp()),
            $clock,
            false,
            new RepositoryAdvisoryLoader($repositories)
        ))->withMoveChecks(MoveChecks::of(MoveCheckers::forProject($root, $lock, $project, $config, $repositories, '7.4', false, Deadline::never())));

        return $analyzer->analyze($lock, $project, false);
    }
}
