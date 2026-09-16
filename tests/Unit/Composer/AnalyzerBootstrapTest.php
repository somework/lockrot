<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Composer;

use Composer\Config;
use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use Lockrot\Allowlist\Allowlist;
use Lockrot\Allowlist\AllowlistEntry;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Clock;
use Lockrot\Composer\AnalyzerBootstrap;
use Lockrot\Config\LockrotConfig;
use Lockrot\Data\Forge\ActivityClient;
use Lockrot\Data\Forge\ActivityFetchPlanner;
use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\RepoLocator;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Data\Http\RecordedHttpClient;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\MetadataBatch;
use Lockrot\Data\Repository\MetadataLoaderInterface;
use Lockrot\Deadline;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Signal\SignalSet;
use Lockrot\Verdict\VerdictEngine;
use PHPUnit\Framework\TestCase;

final class AnalyzerBootstrapTest extends TestCase
{
    private function emptyLoader(): MetadataLoaderInterface
    {
        return new class () implements MetadataLoaderInterface {
            public function load(array $names): MetadataBatch
            {
                return new MetadataBatch([], $names, []);
            }
        };
    }

    private function baseAnalyzer(Clock $clock, LockrotConfig $lockrot): Analyzer
    {
        return new Analyzer(
            $this->emptyLoader(),
            new ActivityClient(new RecordedHttpClient(__DIR__.'/../../fixtures/http/github'), ForgeAuth::anonymous()),
            new ActivityFetchPlanner(ForgeAuth::anonymous()),
            new RepoLocator(),
            new Allowlist([new AllowlistEntry('vendor/builtin', null, 'builtin match', null, 'builtin')]),
            SignalSet::default($clock, $lockrot->thresholds(), '8.4', PhpReleaseDates::load()),
            new VerdictEngine(),
            $clock,
            false
        );
    }

    public function testCreateMergesTheProjectIgnoreListOntoTheFactoryBuiltAllowlist(): void
    {
        $lockrot = LockrotConfig::fromSources([], [], [], '8.4.0', null);
        $factory = function (IOInterface $io, Config $config, array $repositories, LockrotConfig $lockrot, Tokens $tokens, Clock $clock, Deadline $deadline): Analyzer {
            return $this->baseAnalyzer($clock, $lockrot);
        };
        $project = ProjectConfig::fromArray([
            'extra' => ['lockrot' => ['ignore' => [['package' => 'vendor/project', 'reason' => 'tracked in ACME-1']]]],
        ]);

        $analyzer = AnalyzerBootstrap::create($factory, new NullIO(), new Config(false, sys_get_temp_dir()), [], $project, $lockrot, [], Deadline::never());

        $patterns = array_map(static fn (AllowlistEntry $e): string => $e->pattern(), $analyzer->allowlist()->entries());
        self::assertContains('vendor/builtin', $patterns, "the factory-built analyzer's own allowlist entry must survive the merge");
        self::assertContains('vendor/project', $patterns, 'the project ignore list must be merged in');
    }

    public function testCreatePassesTheDeadlineThroughToTheFactory(): void
    {
        $lockrot = LockrotConfig::fromSources([], [], [], '8.4.0', null);
        $recorded = null;
        $factory = function (IOInterface $io, Config $config, array $repositories, LockrotConfig $lockrot, Tokens $tokens, Clock $clock, Deadline $deadline) use (&$recorded): Analyzer {
            $recorded = $deadline;

            return $this->baseAnalyzer($clock, $lockrot);
        };
        $deadline = Deadline::inSeconds(5.0);

        AnalyzerBootstrap::create($factory, new NullIO(), new Config(false, sys_get_temp_dir()), [], ProjectConfig::empty(), $lockrot, [], $deadline);

        self::assertSame($deadline, $recorded);
    }
}
