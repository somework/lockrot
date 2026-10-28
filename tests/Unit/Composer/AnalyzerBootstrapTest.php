<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Composer;

use Composer\Config;
use Composer\IO\BufferIO;
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
use Lockrot\Data\Forge\RepoRef;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Data\Http\RecordedHttpClient;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\MetadataBatch;
use Lockrot\Data\Repository\MetadataLoaderInterface;
use Lockrot\Deadline;
use Lockrot\Exception\ConfigException;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Signal\SignalSet;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;

final class AnalyzerBootstrapTest extends TestCase
{
    private function emptyLoader(): MetadataLoaderInterface
    {
        return new class () implements MetadataLoaderInterface {
            public function load(array $installedByName): MetadataBatch
            {
                return new MetadataBatch([], array_keys($installedByName), []);
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

        $patterns = array_map(static fn (AllowlistEntry $e): ?string => $e->pattern(), $analyzer->allowlist()->entries());
        self::assertContains('vendor/builtin', $patterns, "the factory-built analyzer's own allowlist entry must survive the merge");
        self::assertContains('vendor/project', $patterns, 'the project ignore list must be merged in');
    }

    /** `extra.lockrot.accept-unchecked` reaches the analyzer, and an invalid entry stops the run. */
    public function testCreatePassesTheProjectsUncheckedAcceptanceToTheAnalyzer(): void
    {
        $lockrot = LockrotConfig::fromSources([], [], [], '8.4.0', null);
        $factory = fn (IOInterface $io, Config $config, array $repositories, LockrotConfig $lockrot, Tokens $tokens, Clock $clock, Deadline $deadline): Analyzer => $this->baseAnalyzer($clock, $lockrot);
        $create = static fn (array $extra): Analyzer => AnalyzerBootstrap::create($factory, new NullIO(), new Config(false, sys_get_temp_dir()), [], ProjectConfig::fromArray(['extra' => ['lockrot' => $extra]]), $lockrot, [], Deadline::never());

        self::assertTrue($create([])->uncheckedAcceptance()->isEmpty());
        self::assertFalse($create(['accept-unchecked' => [['check' => 'advisories', 'package' => 'acme/*', 'reason' => 'no feed']]])->uncheckedAcceptance()->isEmpty());
        $this->expectException(ConfigException::class);
        $create(['accept-unchecked' => [['check' => 'advisories', 'host' => 'codeberg.org', 'reason' => 'a host is no scope for advisories']]]);
    }

    /** The project's php requirement is one of the two floors that S8 keeps the branch it names within. */
    public function testCreatePassesTheProjectsPhpRequirementThroughToTheFactory(): void
    {
        $lockrot = LockrotConfig::fromSources([], [], [], '8.4.0', null);
        $seen = [];
        $factory = function (IOInterface $io, Config $config, array $repositories, LockrotConfig $lockrot, Tokens $tokens, Clock $clock, Deadline $deadline, ?string $projectPhp = null) use (&$seen): Analyzer {
            $seen[] = $projectPhp;

            return $this->baseAnalyzer($clock, $lockrot);
        };

        AnalyzerBootstrap::create($factory, new NullIO(), new Config(false, sys_get_temp_dir()), [], ProjectConfig::fromArray(['require' => ['php' => '>=7.2.5']]), $lockrot, [], Deadline::never());
        AnalyzerBootstrap::create($factory, new NullIO(), new Config(false, sys_get_temp_dir()), [], ProjectConfig::empty(), $lockrot, [], Deadline::never());

        self::assertSame(['>=7.2.5', null], $seen, 'as written, and null for a project without a manifest');
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

    /** A GitHub Enterprise Server runner's token stays home: the run is anonymous on github.com. */
    public function testAnInstanceTokenIsNotPassedOnForGithubCom(): void
    {
        $lockrot = LockrotConfig::fromSources([], [], [], '8.4.0', null);
        $seen = null;
        $factory = function (IOInterface $io, Config $config, array $repositories, LockrotConfig $lockrot, Tokens $tokens, Clock $clock, Deadline $deadline) use (&$seen): Analyzer {
            $seen = $tokens;

            return $this->baseAnalyzer($clock, $lockrot);
        };
        $env = ['GITHUB_TOKEN' => 'ghs_instance', 'GITHUB_SERVER_URL' => 'https://ghes.example.com'];

        AnalyzerBootstrap::create($factory, new NullIO(), new Config(false, sys_get_temp_dir()), [], ProjectConfig::empty(), $lockrot, $env, Deadline::never());

        self::assertInstanceOf(Tokens::class, $seen);
        $auth = ForgeAuth::withTokens($seen);
        $github = new RepoRef(RepoRef::GITHUB, 'github.com', 'o/r');
        self::assertNull($auth->tokenFor($github));
        self::assertFalse($auth->isAuthenticated($github), 'the planner caps the run as anonymous');
    }

    public function testEachWithheldTokenWritesOneLineAtVerbose(): void
    {
        $env = ['GITHUB_TOKEN' => 'b', 'GITHUB_SERVER_URL' => "https://ghes.example.com", 'GITLAB_TOKEN' => 'g', 'GITLAB_HOST' => 'gitlab.example.com'];

        self::assertSame(
            "lockrot: GITHUB_TOKEN is for ghes.example.com, so lockrot does not send it to github.com. Set LOCKROT_GITHUB_TOKEN to a github.com token.\n"
            ."lockrot: GITLAB_TOKEN is for gitlab.example.com, so lockrot does not send it to gitlab.com. Set LOCKROT_GITLAB_TOKEN to a gitlab.com token.\n",
            $this->stderrOf($env, OutputInterface::VERBOSITY_VERBOSE)
        );
        self::assertSame('', $this->stderrOf($env, OutputInterface::VERBOSITY_NORMAL));
        self::assertSame('', $this->stderrOf(['GITHUB_TOKEN' => 'b'], OutputInterface::VERBOSITY_VERBOSE));
    }

    /** An issuer that does not parse is named by its raw value, which can carry a terminal escape. */
    public function testAWithheldIssuerIsNeutralised(): void
    {
        $stderr = $this->stderrOf(['GITHUB_TOKEN' => 'b', 'GITHUB_SERVER_URL' => "https://?\x1b]8;;"], OutputInterface::VERBOSITY_VERBOSE);

        self::assertStringNotContainsString("\x1b", $stderr);
        self::assertStringContainsString('https://?\\x1B]8;;', $stderr);
    }

    /** @param array<string, string> $env */
    private function stderrOf(array $env, int $verbosity): string
    {
        $lockrot = LockrotConfig::fromSources([], [], [], '8.4.0', null);
        $factory = fn (IOInterface $io, Config $config, array $repositories, LockrotConfig $lockrot, Tokens $tokens, Clock $clock, Deadline $deadline): Analyzer => $this->baseAnalyzer($clock, $lockrot);
        $io = new BufferIO('', $verbosity);

        AnalyzerBootstrap::create($factory, $io, new Config(false, sys_get_temp_dir()), [], ProjectConfig::empty(), $lockrot, $env, Deadline::never());

        return $io->getOutput();
    }
}
