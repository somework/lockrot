<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Composer\Package\CompletePackage;
use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Clock;
use Lockrot\Data\Advisory\RepositoryAdvisoryLoader;
use Lockrot\Data\Forge\ActivityClient;
use Lockrot\Data\Forge\ActivityFetchPlanner;
use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\RepoLocator;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Data\Http\RecordedHttpClient;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\MetadataBatch;
use Lockrot\Data\Repository\MetadataLoaderInterface;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Explain\Explanation;
use Lockrot\Json\Schemas;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Output\ExplainFormatter;
use Lockrot\Output\JsonFormatter;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\ValidatesJsonSchemas;
use Lockrot\Verdict\VerdictEngine;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * A replacement is linked on the registry that named it, and only on packagist.org: the one whose
 * metadata marked the package abandoned, or, where no metadata came, the one the lock entry came
 * from. Four abandoned packages naming `acme/new-lib`, two through metadata and two through the lock,
 * each pair once from packagist.org and once from Private Packagist.
 *
 * @coversNothing
 */
#[CoversNothing]
final class ReplacementUrlTest extends TestCase
{
    use ValidatesJsonSchemas;

    private const NOW = '2026-09-14T00:00:00+00:00';
    private const PACKAGIST = 'https://packagist.org/downloads/';
    private const PRIVATE_PACKAGIST = 'https://repo.packagist.com/acme-org/downloads/';
    private const PAGE = 'https://packagist.org/packages/acme/new-lib';

    private string $dir = '';

    protected function tearDown(): void
    {
        foreach (['composer.json', 'composer.lock'] as $file) {
            @unlink($this->dir.'/'.$file);
        }
        @rmdir($this->dir);
    }

    public function testAReplacementIsLinkedWhereTheRegistryThatNamedItKeepsItsPage(): void
    {
        $this->dir = sys_get_temp_dir().'/lockrot-replacement-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->dir));
        $entry = static fn (string $name, string $notificationUrl, ?string $abandonedInLock = null): array => array_filter([
            'name' => $name,
            'version' => '1.0.0',
            'notification-url' => $notificationUrl,
            'abandoned' => $abandonedInLock,
            'type' => 'library',
            'time' => '2020-01-01T00:00:00+00:00',
        ], static fn ($value): bool => $value !== null);
        file_put_contents($this->dir.'/composer.json', (string) json_encode(['name' => 'acme/app']));
        file_put_contents($this->dir.'/composer.lock', (string) json_encode(['content-hash' => 'x', 'packages' => [
            $entry('acme/metadata-from-packagist', self::PACKAGIST),
            // The entry came from packagist.org, but the metadata lockrot read, and the replacement in it, came from Private Packagist.
            $entry('acme/metadata-from-private-packagist', self::PACKAGIST),
            $entry('acme/lock-from-packagist', self::PACKAGIST, 'acme/new-lib'),
            $entry('acme/lock-from-private-packagist', self::PRIVATE_PACKAGIST, 'acme/new-lib'),
        ], 'packages-dev' => []]));

        $metadata = [
            'acme/metadata-from-packagist' => self::abandoned('acme/metadata-from-packagist', self::PACKAGIST),
            'acme/metadata-from-private-packagist' => self::abandoned('acme/metadata-from-private-packagist', self::PRIVATE_PACKAGIST),
        ];
        $loader = new class ($metadata) implements MetadataLoaderInterface {
            /** @var array<string, PackageMetadata> */
            private array $metadata;

            /** @param array<string, PackageMetadata> $metadata */
            public function __construct(array $metadata)
            {
                $this->metadata = $metadata;
            }

            public function load(array $names): MetadataBatch
            {
                return new MetadataBatch(array_intersect_key($this->metadata, array_flip($names)), array_values(array_diff($names, array_keys($this->metadata))), []);
            }
        };
        $clock = Clock::fixed(self::NOW);
        $auth = ForgeAuth::withTokens(new Tokens(null, null));
        $analyzer = new Analyzer(
            $loader,
            new ActivityClient(new RecordedHttpClient(__DIR__.'/../fixtures/http/github'), $auth),
            new ActivityFetchPlanner($auth),
            new RepoLocator(),
            BuiltinAllowlist::load(),
            SignalSet::default($clock, new Thresholds(), '8.4', PhpReleaseDates::load()),
            new VerdictEngine(),
            $clock,
            false,
            new RepositoryAdvisoryLoader([], false)
        );
        $lock = LockFile::fromFile($this->dir.'/composer.lock');
        $analysis = $analyzer->analyzeWithFacts($lock->packages(false), $lock, ProjectConfig::fromFile($this->dir.'/composer.json'), false);

        $json = (new JsonFormatter())->format($analysis->report());
        $this->assertValid(Schemas::REPORT, $json, 'the report', true);
        $document = json_decode($json, true);
        self::assertIsArray($document);
        $links = [];
        foreach (JsonPath::arrayAt($document, ['findings']) as $i => $finding) {
            $package = JsonPath::stringAt($document, ['findings', $i, 'package']);
            self::assertSame('acme/new-lib', JsonPath::stringAt($document, ['findings', $i, 'replacement']), $package);
            $links[$package] = JsonPath::arrayAt($document, ['findings', $i])['replacement_url'];
        }
        ksort($links);
        self::assertSame([
            'acme/lock-from-packagist' => self::PAGE,
            'acme/lock-from-private-packagist' => null,
            'acme/metadata-from-packagist' => self::PAGE,
            'acme/metadata-from-private-packagist' => null,
        ], $links);

        foreach ($analysis->report()->findings() as $finding) {
            $facts = $analysis->facts($finding->package());
            self::assertNotNull($facts);
            $explained = (new ExplainFormatter())->json(new Explanation($finding, $facts, new Thresholds(), '8.4', $analysis->report()));
            $this->assertValid(Schemas::EXPLAIN, $explained, $finding->package(), true);
            $decoded = json_decode($explained, true);
            self::assertIsArray($decoded);
            self::assertSame($links[$finding->package()], JsonPath::arrayAt($decoded, ['finding'])['replacement_url'], 'the explanation carries the same finding');
        }
    }

    private static function abandoned(string $name, string $notificationUrl): PackageMetadata
    {
        $version = new CompletePackage($name, '1.0.0.0', '1.0.0');
        $version->setAbandoned('acme/new-lib');
        $version->setNotificationUrl($notificationUrl);
        $version->setReleaseDate(new \DateTime('2020-01-01T00:00:00+00:00'));

        return PackageMetadata::fromPackages($name, [$version], new \DateTimeImmutable(self::NOW));
    }
}
