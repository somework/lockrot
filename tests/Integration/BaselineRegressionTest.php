<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Composer\Config;
use Composer\Console\Application;
use Composer\IO\IOInterface;
use Composer\Repository\AdvisoryProviderInterface;
use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Clock;
use Lockrot\Composer\LockrotCommand;
use Lockrot\Config\LockrotConfig;
use Lockrot\Data\Advisory\RepositoryAdvisoryLoader;
use Lockrot\Data\Forge\ActivityClient;
use Lockrot\Data\Forge\ActivityFetchPlanner;
use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\RepoLocator;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Data\Http\RecordedHttpClient;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\RepositoryMetadataLoader;
use Lockrot\Json\Schemas;
use Lockrot\Signal\SignalSet;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Tests\Support\ValidatesJsonSchemas;
use Lockrot\Verdict\VerdictEngine;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** A package whose only fact is an advisory is not written into a baseline-1 file, and is not new on the next run. */
final class BaselineRegressionTest extends TestCase
{
    use ValidatesJsonSchemas;

    private const FIXTURE = __DIR__.'/../fixtures/skeletons/laravel';
    private const SEEDED = 'guzzlehttp/guzzle';

    private ?FixtureRepositoryServer $server = null;
    private string $cwd = '';
    private string $dir = '';

    protected function setUp(): void
    {
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            self::markTestSkipped('Composer 2.2 has no advisory API, so no advisory reaches the seeded package.');
        }
        $this->server = FixtureRepositoryServer::fromLockFiles([self::FIXTURE.'/composer.lock']);
        $this->server->withAdvisoryApi([self::SEEDED => [[
            'advisoryId' => 'PKSA-seeded-1',
            'packageName' => self::SEEDED,
            'remoteId' => 'GHSA-seeded-1',
            'title' => 'Seeded advisory',
            'link' => 'https://example.test/PKSA-seeded-1',
            'cve' => 'CVE-2026-0001',
            'affectedVersions' => '>=8.0.0,<8.3.0',
            'reportedAt' => '2026-09-01 00:00:00',
            'severity' => 'high',
            'sources' => [['name' => 'GitHub', 'remoteId' => 'GHSA-seeded-1']],
        ]]]);
        $this->server->start();
        $this->cwd = (string) getcwd();
        $this->dir = sys_get_temp_dir().'/lockrot-baseline-regression-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->dir));
        copy(self::FIXTURE.'/composer.lock', $this->dir.'/composer.lock');
        copy(self::FIXTURE.'/composer.json', $this->dir.'/composer.json');
        chdir($this->dir);
    }

    protected function tearDown(): void
    {
        if ($this->cwd !== '') {
            chdir($this->cwd);
        }
        if ($this->server !== null) {
            $this->server->stop();
        }
        if ($this->dir === '' || !is_dir($this->dir)) {
            return;
        }
        foreach ((array) glob($this->dir.'/*') as $file) {
            unlink((string) $file);
        }
        rmdir($this->dir);
    }

    public function testAGeneratedBaselineIsBaseline1AndTheVulnerableOnlyPackageIsNotNewOnTheNextRun(): void
    {
        $report = $this->jsonRun(['--format' => 'json', '--target-php' => '8.4']);
        self::assertSame('ok', self::finding($report, self::SEEDED)['verdict'], 'the seeded package has the report-1 verdict ok');
        $signals = self::finding($report, self::SEEDED)['signals'];
        self::assertIsArray($signals);
        self::assertSame(['S9'], array_column($signals, 'id'), 'the seeded advisory is its only fact');

        $generate = $this->tester();
        self::assertSame(0, $generate->execute(['--generate-baseline' => true, '--target-php' => '8.4']));
        $baseline = (string) file_get_contents($this->dir.'/lockrot-baseline.json');
        $this->assertValid(Schemas::BASELINE, $baseline, 'the generated baseline', false, 1);
        $this->assertValid(Schemas::BASELINE, $baseline, 'the generated baseline', true, 1);
        $written = json_decode($baseline, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($written);
        self::assertIsArray($written['findings']);
        self::assertArrayNotHasKey(self::SEEDED, $written['findings']);

        $next = $this->tester();
        self::assertSame(0, $next->execute(['--format' => 'json', '--fail-on' => 'low', '--target-php' => '8.4']), $next->getDisplay());
        $read = json_decode($next->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($read);
        self::assertIsArray($read['baseline']);
        self::assertSame(0, $read['baseline']['new']);
        self::assertNull(self::finding($read, self::SEEDED)['baseline']);
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<mixed>
     */
    private function jsonRun(array $args): array
    {
        $tester = $this->tester();
        self::assertSame(0, $tester->execute($args), $tester->getDisplay());
        $report = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($report);

        return $report;
    }

    /**
     * @param array<mixed> $report
     *
     * @return array<mixed>
     */
    private static function finding(array $report, string $package): array
    {
        self::assertIsArray($report['findings']);
        foreach ($report['findings'] as $finding) {
            self::assertIsArray($finding);
            if ($finding['package'] === $package) {
                return $finding;
            }
        }
        self::fail('no finding for '.$package);
    }

    private function tester(): CommandTester
    {
        $server = $this->server;
        self::assertNotNull($server);
        $factory = static function (IOInterface $io, Config $config, array $repositories, LockrotConfig $lockrot, Tokens $tokens, Clock $clock) use ($server): Analyzer {
            $auth = ForgeAuth::withTokens(new Tokens('recorded', null));

            return new Analyzer(
                new RepositoryMetadataLoader($server->repositories(), $clock),
                new ActivityClient(new RecordedHttpClient(__DIR__.'/../fixtures/http/github'), $auth),
                new ActivityFetchPlanner($auth),
                new RepoLocator(),
                BuiltinAllowlist::load(),
                SignalSet::default($clock, $lockrot->thresholds(), $lockrot->targetPhp(), PhpReleaseDates::load()),
                new VerdictEngine(),
                $clock,
                false,
                new RepositoryAdvisoryLoader($server->repositories())
            );
        };
        $command = new LockrotCommand($factory);
        $app = new Application();
        $app->setAutoExit(false);
        $app->add($command);
        $command->setApplication($app);

        return new CommandTester($command);
    }
}
