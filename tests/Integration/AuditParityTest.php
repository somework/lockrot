<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Composer\Advisory\AuditConfig;
use Composer\Advisory\Auditor;
use Composer\Config;
use Composer\IO\BufferIO;
use Composer\Package\AliasPackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\PackageInterface;
use Composer\Policy\PolicyConfig;
use Composer\Repository\AdvisoryProviderInterface;
use Composer\Repository\RepositorySet;
use Lockrot\Data\Advisory\AdvisoryIgnore;
use Lockrot\Data\Advisory\RepositoryAdvisoryLoader;
use Lockrot\Lock\LockFile;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use PHPUnit\Framework\TestCase;

/**
 * The installed-version result of lockrot's one lookup equals what Composer's own `Auditor`
 * reports, counted and ignored, for each lock and config of the table. Keyed by capability:
 * Composer 2.2 has no advisory API, 2.4 to 2.9 read `config.audit`, 2.10 and later the policy API.
 */
final class AuditParityTest extends TestCase
{
    private const WALLABAG_LOCK = __DIR__.'/../fixtures/apps/wallabag_wallabag/composer.lock';

    private const LOCK = [
        ['name' => 'acme/stable', 'version' => '1.2.0', 'notification-url' => 'https://packagist.org/downloads/'],
        ['name' => 'acme/alias', 'version' => '4.3.x-dev', 'notification-url' => 'https://packagist.org/downloads/'],
        ['name' => 'acme/main', 'version' => 'dev-main', 'notification-url' => 'https://packagist.org/downloads/'],
        ['name' => 'acme/local', 'version' => '1.0.0', 'dist' => ['type' => 'path', 'url' => '../local']],
        ['name' => 'acme/clean', 'version' => '3.0.0', 'notification-url' => 'https://packagist.org/downloads/'],
        ['name' => 'acme/branch', 'version' => 'dev-main', 'notification-url' => 'https://packagist.org/downloads/', 'extra' => ['branch-alias' => ['dev-main' => '4.3.x-dev']]],
    ];

    private static ?FixtureRepositoryServer $server = null;

    public static function setUpBeforeClass(): void
    {
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            return;
        }
        self::$server = FixtureRepositoryServer::fromLockFiles([self::WALLABAG_LOCK]);
        self::$server->withAdvisoryApi([
            'acme/stable' => [
                self::record('acme/stable', 'PKSA-s1', '<1.3', 'critical', 'CVE-2024-1001', 'GHSA-s1'),
                self::record('acme/stable', 'PKSA-s2', '>=2.0', 'high', null, 'GHSA-s2'),
                self::record('acme/stable', 'PKSA-s3', '<1.3', 'low', null, 'GHSA-s3'),
                self::record('acme/stable', 'PKSA-s4', '<1.5', null, 'CVE-2024-1004', 'GHSA-s4'),
            ],
            'acme/alias' => [
                self::record('acme/alias', 'PKSA-a1', '>=4.3.0,<4.4.13', 'medium', null, 'GHSA-a1'),
                self::record('acme/alias', 'PKSA-a2', '<4.0', 'high', null, 'GHSA-a2'),
            ],
            'acme/main' => [
                self::record('acme/main', 'PKSA-m1', '<5.0', 'high', null, 'GHSA-m1'),
                self::record('acme/main', 'PKSA-m2', 'dev-main', 'medium', null, 'GHSA-m2'),
            ],
            'acme/local' => [self::record('acme/local', 'PKSA-l1', '<2.0', 'high', null, 'GHSA-l1')],
            'acme/clean' => [],
            'acme/branch' => [
                self::record('acme/branch', 'PKSA-b1', '>=4.3.0,<4.4.13', 'high', null, 'GHSA-b1'),
                self::record('acme/branch', 'PKSA-b2', '<4.0', 'high', null, 'GHSA-b2'),
            ],
        ]);
        self::$server->start();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            self::$server->stop();
            self::$server = null;
        }
    }

    /** @return array<string, mixed> */
    private static function record(string $package, string $id, string $affected, ?string $severity, ?string $cve, string $remoteId): array
    {
        return [
            'advisoryId' => $id,
            'packageName' => $package,
            'remoteId' => $remoteId,
            'title' => 'Title of '.$id,
            'link' => 'https://example.test/'.$id,
            'cve' => $cve,
            'affectedVersions' => $affected,
            'sources' => [['name' => 'GitHub', 'remoteId' => $remoteId]],
            'reportedAt' => '2024-03-01 12:00:00',
            'composerRepository' => 'Packagist',
            'severity' => $severity,
        ];
    }

    /** @return iterable<string, array<string, mixed>> the `config` sections that every Composer from 2.4 reads */
    private static function auditConfigs(): iterable
    {
        yield 'no ignore list' => [];
        yield 'an advisory id' => ['audit' => ['ignore' => ['PKSA-s1']]];
        yield 'a CVE with a reason' => ['audit' => ['ignore' => ['CVE-2024-1004' => 'reviewed']]];
        yield 'a source id' => ['audit' => ['ignore' => ['GHSA-s3']]];
        yield 'a package' => ['audit' => ['ignore' => ['acme/alias']]];
        yield 'a severity' => ['audit' => ['ignore-severity' => ['low']]];
        yield 'an id and a package together' => ['audit' => ['ignore' => ['PKSA-l1' => 'ours', 'acme/stable' => 'vendored']]];
    }

    /** @return iterable<string, array<string, mixed>> */
    private static function policyConfigs(): iterable
    {
        yield from self::auditConfigs();
        yield 'a rule for blocking only' => ['audit' => ['ignore' => ['PKSA-s2' => ['apply' => 'block']]]];
        yield 'the policy lists' => ['policy' => ['advisories' => [
            'ignore-id' => ['PKSA-s1' => 'reviewed'],
            'ignore' => ['acme/main' => ['constraint' => '^9.0', 'reason' => 'a constraint audit drops']],
            'ignore-severity' => ['medium' => null],
        ]]];
    }

    public function testThePolicyApiLegMatchesComposersAuditor(): void
    {
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            self::markTestSkipped('Composer 2.2 has no advisory API: there is no lookup to compare');
        }
        if (!class_exists(PolicyConfig::class)) {
            self::markTestSkipped('Composer below 2.10 has no policy API: the config.audit leg covers it');
        }
        $compared = 0;
        foreach (self::policyConfigs() as $label => $section) {
            $config = self::config($section);
            $auditor = new Auditor();
            $composer = self::composerResult(static fn (BufferIO $io, RepositorySet $set, array $packages): int => $auditor->audit($io, $set, PolicyConfig::fromConfig($config), $packages, Auditor::FORMAT_JSON));

            self::assertSame($composer, self::lockrotResult($config), $label);
            ++$compared;
        }
        self::assertGreaterThan(0, $compared);
    }

    /** Composer 2.4 to 2.9 only. No CI leg has such a Composer. */
    public function testTheAuditConfigLegMatchesComposersAuditor(): void
    {
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            self::markTestSkipped('Composer 2.2 has no advisory API: there is no lookup to compare');
        }
        if (class_exists(PolicyConfig::class)) {
            self::markTestSkipped('Composer 2.10 or later: this leg runs only on Composer 2.4 to 2.9, which no CI leg has');
        }
        foreach (self::auditConfigs() as $label => $section) {
            $config = self::config($section);
            [$ignoreList, $ignoreSeverity] = self::legacyLists($config);
            $auditor = new Auditor();
            $audit = new \ReflectionMethod(Auditor::class, 'audit');
            $composer = self::composerResult(static function (BufferIO $io, RepositorySet $set, array $packages) use ($auditor, $audit, $ignoreList, $ignoreSeverity): int {
                $status = $audit->invokeArgs($auditor, [$io, $set, $packages, Auditor::FORMAT_JSON, true, $ignoreList, 'report', $ignoreSeverity]);

                return \is_int($status) ? $status : -1;
            });

            self::assertSame($composer, self::lockrotResult($config), $label);
        }
    }

    /**
     * What Composer 2.4 to 2.9's AuditCommand passes the Auditor: the lists AuditConfig keeps for
     * audit on 2.9.2 and later, else the raw `config.audit` lists.
     *
     * @return array{array<mixed>, array<mixed>}
     */
    private static function legacyLists(Config $config): array
    {
        if (class_exists(AuditConfig::class) && property_exists(AuditConfig::class, 'ignoreListForAudit')) {
            $auditConfig = (new \ReflectionMethod(AuditConfig::class, 'fromConfig'))->invoke(null, $config);
            $properties = \is_object($auditConfig) ? get_object_vars($auditConfig) : [];

            return [(array) ($properties['ignoreListForAudit'] ?? []), (array) ($properties['ignoreSeverityForAudit'] ?? [])];
        }
        $audit = $config->get('audit');
        $audit = \is_array($audit) ? $audit : [];
        // Composer 2.4 to 2.8 take severities from the command line only.
        $severities = class_exists(AuditConfig::class) && \is_array($audit['ignore-severity'] ?? null) ? $audit['ignore-severity'] : [];

        return [\is_array($audit['ignore'] ?? null) ? $audit['ignore'] : [], $severities];
    }

    /** @param array<string, mixed> $section */
    private static function config(array $section): Config
    {
        $config = new Config(false);
        $config->merge(['config' => $section]);

        return $config;
    }

    /** @return list<PackageInterface> each entry, and the package an alias wraps, as Composer's locked repository holds them */
    private static function packages(): array
    {
        $loader = new ArrayLoader();
        $packages = [];
        foreach (self::LOCK as $entry) {
            $package = $loader->load($entry);
            $packages[] = $package;
            if ($package instanceof AliasPackage) {
                $packages[] = $package->getAliasOf();
            }
        }

        return $packages;
    }

    /**
     * @param callable(BufferIO, RepositorySet, list<PackageInterface>): int $audit
     *
     * @return array{counted: array<string, list<string>>, ignored: array<string, list<string>>}
     */
    private static function composerResult(callable $audit): array
    {
        self::assertNotNull(self::$server);
        $set = new RepositorySet();
        foreach (self::$server->repositories() as $repository) {
            $set->addRepository($repository);
        }
        $io = new BufferIO();
        $audit($io, $set, self::packages());
        $json = json_decode($io->getOutput(), true);
        self::assertIsArray($json, $io->getOutput());

        return ['counted' => self::ids($json['advisories'] ?? []), 'ignored' => self::ids($json['ignored-advisories'] ?? [])];
    }

    /**
     * @param mixed $byPackage Composer's JSON: package name => advisories
     *
     * @return array<string, list<string>>
     */
    private static function ids($byPackage): array
    {
        $ids = [];
        foreach (\is_array($byPackage) ? $byPackage : [] as $name => $advisories) {
            foreach (\is_array($advisories) ? $advisories : [] as $advisory) {
                if (\is_array($advisory) && \is_string($advisory['advisoryId'] ?? null)) {
                    $ids[(string) $name][] = $advisory['advisoryId'];
                }
            }
        }

        return self::sorted($ids);
    }

    /** @return array{counted: array<string, list<string>>, ignored: array<string, list<string>>} */
    private static function lockrotResult(Config $config): array
    {
        self::assertNotNull(self::$server);
        $versions = [];
        $aliases = [];
        foreach (LockFile::fromArray(['packages' => self::LOCK])->packages(false) as $package) {
            $versions[$package->name()] = $package->version();
            $aliases[$package->name()] = $package->aliasVersions();
        }
        $batch = (new RepositoryAdvisoryLoader(self::$server->repositories(), false, null, AdvisoryIgnore::fromConfig($config)))->load($versions, ['acme/local'], $aliases);
        $counted = [];
        $ignored = [];
        foreach (array_keys($versions) as $name) {
            foreach ($batch->for($name) as $advisory) {
                $counted[$name][] = $advisory->id();
            }
            foreach ($batch->ignored($name) as $ignoredAdvisory) {
                $ignored[$name][] = $ignoredAdvisory->advisory()->id();
            }
        }

        return ['counted' => self::sorted($counted), 'ignored' => self::sorted($ignored)];
    }

    /**
     * @param array<string, list<string>> $ids
     *
     * @return array<string, list<string>>
     */
    private static function sorted(array $ids): array
    {
        ksort($ids);
        foreach ($ids as $name => $list) {
            sort($list);
            $ids[$name] = $list;
        }

        return $ids;
    }
}
