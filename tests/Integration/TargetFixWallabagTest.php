<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Package\Loader\RootPackageLoader;
use Composer\Repository\RepositoryFactory;
use Lockrot\Analyzer\PhpWindow;
use Lockrot\Analyzer\TargetFix;
use Lockrot\Clock;
use Lockrot\Data\Advisory\RepositoryAdvisoryLoader;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Deadline;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Security\Holder;
use Lockrot\Solver\MoveCheckers;
use Lockrot\Solver\RepositoryVersions;
use Lockrot\Tests\Support\Contract\BaselinedScenario;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * wallabag 2.6.14 at the target PHP 8.4, on the repository of the wallabag-2.6 baselined scenario
 * ({@see BaselinedScenario::repositoryServer()}). Its root admits `dev`, and an
 * advisory blocks every tagged guzzlehttp/guzzle 5.3 release. Composer 2.10 keeps guzzlehttp/guzzle
 * 5.3.x-dev in the `-W` solve, so its refusal names only the guzzle5 adapter's root requirement.
 *
 * @covers \Lockrot\Analyzer\TargetFix
 * @covers \Lockrot\Solver\MoveCheckers
 * @covers \Lockrot\Solver\RepositoryVersions
 */
#[CoversClass(TargetFix::class)]
#[CoversClass(MoveCheckers::class)]
#[CoversClass(RepositoryVersions::class)]
final class TargetFixWallabagTest extends TestCase
{
    private const SCENARIO = 'wallabag-2.6';
    private const SITE = BaselinedScenario::FIXTURES.'baselined/wallabag-2.6/after/';
    private const NOW = '2026-10-14T00:00:00+00:00';

    public function testTheRefusalAtPhp84NamesOnlyTheRootRequirementOfTheGuzzle5Adapter(): void
    {
        $server = BaselinedScenario::repositoryServer(self::SCENARIO);
        $server->start();
        try {
            $fix = self::targetFix($server);
        } finally {
            $server->stop();
        }

        self::assertSame(['laminas/laminas-code', 'php-http/guzzle5-adapter', 'friendsoftwig/twigcs'], array_values(array_unique(array_column($fix['packages'], 'name'))));
        self::assertSame('refused', $fix['check']);
        self::assertSame(
            [['source' => Holder::ROOT, 'link' => Holder::ROOT_REQUIRE, 'target' => 'php-http/guzzle5-adapter', 'constraint' => '^2.0']],
            array_map(static fn (Holder $hold): array => ['source' => $hold->source(), 'link' => $hold->link(), 'target' => $hold->target(), 'constraint' => $hold->constraint()], $fix['held_by'])
        );
    }

    /** @return array{packages: list<array{name: ?string}>, check: string, held_by: list<Holder>} */
    private static function targetFix(FixtureRepositoryServer $server): array
    {
        $json = JsonPath::decodeFile(self::SITE.'composer.json');
        $config = $server->config();
        $config->merge(['config' => ['platform' => JsonPath::arrayAt($json, ['config', 'platform'])]]);
        $io = new NullIO();
        /** @var array<string, mixed> $json */
        $root = (new RootPackageLoader(RepositoryFactory::manager($io, $config, Factory::createHttpDownloader($io, $config)), $config, null, null, $io))->load($json + ['version' => '1.0.0']);
        $lock = LockFile::fromFile(self::SITE.'composer.lock');
        $project = ProjectConfig::fromFile(self::SITE.'composer.json');
        // A run hands the move check its advisory records: without them, no solve blocks a release.
        $versions = [];
        foreach ($lock->packages(false) as $package) {
            $versions[$package->name()] = $package->normalizedVersion() ?? $package->version();
        }
        $advisories = (new RepositoryAdvisoryLoader($server->repositories()))->load($versions);
        $checker = MoveCheckers::forProject($root, $lock, $project, $config, $server->repositories(), '8.4', false, Deadline::never())
            ->withAdvisories($advisories->records(), $advisories->asked());
        $window = PhpWindow::of($lock, $project, '8.4', PhpReleaseDates::load()->minors(Clock::fixed(self::NOW)));
        self::assertNotNull($window);
        $fix = $window->withTargetFix($checker)->toArray()['target_fix'];
        self::assertNotNull($fix, 'the target is outside the window');

        return $fix;
    }
}
