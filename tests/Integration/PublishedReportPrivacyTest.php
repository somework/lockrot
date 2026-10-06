<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Repository\AdvisoryProviderInterface;
use Composer\Repository\RepositoryFactory;
use Composer\Repository\RepositoryInterface;
use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analysis;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Analyzer\RunNote;
use Lockrot\Clock;
use Lockrot\Data\Advisory\RepositoryAdvisoryLoader;
use Lockrot\Data\Forge\ActivityClient;
use Lockrot\Data\Forge\ActivityFetchPlanner;
use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\RepoLocator;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Data\Http\RecordedHttpClient;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\RepositoryMetadataLoader;
use Lockrot\Explain\Explanation;
use Lockrot\Html\PageData;
use Lockrot\Json\Schemas;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Output\ExplainFormatter;
use Lockrot\Output\FormatContext;
use Lockrot\Output\Formatters;
use Lockrot\Output\InstallSummaryFormatter;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\ValidatesJsonSchemas;
use Lockrot\Verdict\VerdictEngine;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * A report is published. A run against private repositories that cannot be reached — one with a
 * login, a password and a `?token=` in its URL, one with a bare token in the user slot — and a lock
 * entry from a checkout on the machine writes every format and every explanation, and none of them
 * carries a credential or a path of the machine: the messages come from Composer itself, which masks
 * only the password.
 *
 * @coversNothing
 */
#[CoversNothing]
final class PublishedReportPrivacyTest extends TestCase
{
    use ValidatesJsonSchemas;

    private const NOW = '2026-09-14T00:00:00+00:00';
    private const NEVER_WRITTEN = ['ci-user-x9', 's3cr3t-x9', 't0k3n-x9', 'glpat-x9', 'glp***', 'client-x9', '/Users/'];
    private const FORMATS = ['table', 'json', 'github', 'sarif', 'gitlab', 'markdown', 'html'];

    private string $dir = '';

    protected function tearDown(): void
    {
        foreach (['composer.json', 'composer.lock'] as $file) {
            @unlink($this->dir.'/'.$file);
        }
        @rmdir($this->dir.'/cache');
        @rmdir($this->dir);
    }

    public function testNoFormatAndNoExplanationCarriesACredentialOrAPathOfTheMachine(): void
    {
        $port = self::closedPort();
        $urls = [
            'http://ci-user-x9:s3cr3t-x9@127.0.0.1:'.$port.'/sub?token=t0k3n-x9',
            'http://glpat-x9secretsecret@127.0.0.1:'.$port.'/other',
        ];
        $analysis = $this->analyse($urls);
        $report = $analysis->report();

        $written = [];
        foreach (self::FORMATS as $format) {
            $context = FormatContext::create($this->dir.'/composer.lock', 'none', '0.13.0', 200, $this->dir);
            $written[$format] = Formatters::for($format, $context, new PageData($analysis, new Thresholds(), '8.4'))->format($report, true);
        }
        $written['install-time'] = implode("\n", (new InstallSummaryFormatter())->format($report));
        foreach ($report->findings() as $finding) {
            $facts = $analysis->facts($finding->package());
            self::assertNotNull($facts);
            $explanation = new Explanation($finding, $facts, new Thresholds(), '8.4', $report);
            $written['explain text '.$finding->package()] = (new ExplainFormatter())->text($explanation);
            $written['explain json '.$finding->package()] = (new ExplainFormatter())->json($explanation);
            $this->assertValid(Schemas::EXPLAIN, $written['explain json '.$finding->package()], $finding->package(), true);
        }
        $this->assertValid(Schemas::REPORT, $written['json'], 'the report', true);

        $codes = array_map(static fn (RunNote $note): string => $note->code(), $report->runNotes());
        self::assertContains(RunNote::METADATA_UNAVAILABLE, $codes, 'the repositories failed, which the test is about');
        // Composer 2.2 has no advisories to ask a repository for.
        self::assertContains(interface_exists(AdvisoryProviderInterface::class) ? RunNote::ADVISORIES_UNAVAILABLE : RunNote::ADVISORIES_NOT_CHECKED, $codes);
        self::assertStringContainsString('http://127.0.0.1:'.$port.'/other', $written['json'], 'the host stays, which the reader is checking');
        // SARIF's %SRCROOT% is the project directory on purpose: code scanning resolves results against it.
        $sarif = json_decode($written['sarif'], true);
        self::assertIsArray($sarif);
        $runs = [];
        foreach (JsonPath::arrayAt($sarif, ['runs']) as $run) {
            self::assertIsArray($run);
            unset($run['originalUriBaseIds']);
            $runs[] = $run;
        }
        $sarif['runs'] = $runs;
        $written['sarif'] = (string) json_encode($sarif, \JSON_UNESCAPED_SLASHES);
        $project = array_unique([$this->dir, (string) realpath($this->dir)]);
        foreach ($written as $what => $text) {
            foreach (array_merge(self::NEVER_WRITTEN, $project, str_replace('/', '\\/', $project)) as $secret) {
                self::assertStringNotContainsString($secret, $text, $what.' carries '.$secret);
            }
        }
    }

    /** @param list<string> $urls */
    private function analyse(array $urls): Analysis
    {
        $this->dir = sys_get_temp_dir().'/lockrot-privacy-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->dir.'/cache', 0777, true));
        $repositories = array_map(static fn (string $url): array => ['type' => 'composer', 'url' => $url], $urls);
        file_put_contents($this->dir.'/composer.json', (string) json_encode(['name' => 'acme/app', 'repositories' => $repositories, 'require' => ['acme/private-lib' => '^1.0', 'acme/local-lib' => 'dev-main']]));
        file_put_contents($this->dir.'/composer.lock', (string) json_encode(['content-hash' => 'x', 'packages' => [
            [
                'name' => 'acme/private-lib',
                'version' => '1.0.0',
                'source' => ['type' => 'git', 'url' => 'https://ci-user-x9:s3cr3t-x9@git.acme.test/acme/private-lib.git?token=t0k3n-x9', 'reference' => 'abc'],
                'dist' => ['type' => 'zip', 'url' => 'https://ci-user-x9:s3cr3t-x9@repo.acme.test/dists/private-lib.zip', 'reference' => 'abc'],
                'notification-url' => 'http://ci-user-x9:s3cr3t-x9@127.0.0.1/downloads/',
                'type' => 'library',
                'time' => '2020-01-01T00:00:00+00:00',
            ],
            [
                'name' => 'acme/local-lib',
                'version' => 'dev-main',
                'source' => ['type' => 'git', 'url' => '/Users/ci-user-x9/client-x9/local-lib', 'reference' => 'def'],
                'type' => 'library',
                'time' => '2020-01-01T00:00:00+00:00',
            ],
        ], 'packages-dev' => []]));

        $clock = Clock::fixed(self::NOW);
        $auth = ForgeAuth::withTokens(new Tokens(null, null));
        $composer = $this->repositories($repositories);
        $analyzer = new Analyzer(
            new RepositoryMetadataLoader($composer, $clock),
            new ActivityClient(new RecordedHttpClient(__DIR__.'/../fixtures/http/github'), $auth),
            new ActivityFetchPlanner($auth),
            new RepoLocator(),
            BuiltinAllowlist::load(),
            SignalSet::default($clock, new Thresholds(), '8.4', PhpReleaseDates::load()),
            new VerdictEngine(),
            $clock,
            false,
            new RepositoryAdvisoryLoader($composer, false)
        );
        $lock = LockFile::fromFile($this->dir.'/composer.lock');

        return $analyzer->analyzeWithFacts($lock->packages(false), $lock, ProjectConfig::fromFile($this->dir.'/composer.json'), false);
    }

    /**
     * @param list<array{type: string, url: string}> $repositories
     *
     * @return list<RepositoryInterface>
     */
    private function repositories(array $repositories): array
    {
        $io = new NullIO();
        $config = Factory::createConfig($io);
        $config->merge(['config' => ['cache-dir' => $this->dir.'/cache', 'secure-http' => false], 'repositories' => ['packagist.org' => false] + $repositories]);

        return array_values(RepositoryFactory::defaultRepos($io, $config, RepositoryFactory::manager($io, $config, Factory::createHttpDownloader($io, $config))));
    }

    /** A port nothing listens on, so Composer's own "could not connect" is what the notes quote. */
    private static function closedPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
