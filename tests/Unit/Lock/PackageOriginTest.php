<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Lock;

use Lockrot\Lock\ConfiguredRepositories;
use Lockrot\Lock\OriginFacts;
use Lockrot\Lock\PackageOrigin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PackageOriginTest extends TestCase
{
    private const PACKAGIST = 'https://packagist.org/downloads/';

    /** @return iterable<string, array{string, string, ?string, ?string}> notification-url, kind, registry, package_url */
    public static function notified(): iterable
    {
        yield 'packagist.org' => [self::PACKAGIST, 'packagist', 'packagist.org', 'https://packagist.org/packages/acme/lib'];
        yield 'a mirror that reports to packagist.org' => ['https://packagist.org/downloads/?mirror=aliyun', 'packagist', 'packagist.org', 'https://packagist.org/packages/acme/lib'];
        yield 'packagist.org in capitals' => ['HTTPS://PACKAGIST.ORG/downloads/', 'packagist', 'packagist.org', 'https://packagist.org/packages/acme/lib'];
        yield 'Private Packagist' => ['https://repo.packagist.com/acme-org/downloads/', 'composer', 'repo.packagist.com', null];
        yield 'WP Packages' => ['https://wp-packages.org/downloads', 'composer', 'wp-packages.org', 'https://wp-packages.org/packages/acme/lib'];
        yield 'Drupal' => ['https://packages.drupal.org/8/downloads', 'composer', 'packages.drupal.org', null];
        yield 'a registry lockrot does not know' => ['https://satis.internal.acme.test/downloads', 'composer', null, null];
        yield 'a subdomain of packagist.org' => ['https://repo.packagist.org/downloads/', 'composer', null, null];
        yield 'packagist.org as a login' => ['https://packagist.org@evil.example/downloads/', 'composer', null, null];
        yield 'a host that ends like packagist.org' => ['https://packagist.org.evil.example/downloads/', 'composer', null, null];
        yield 'no host at all' => ['/downloads/', 'composer', null, null];
    }

    /** @dataProvider notified */
    #[DataProvider('notified')]
    public function testANotificationUrlDecidesTheKindAndItsHostTheRegistry(string $url, string $kind, ?string $registry, ?string $packageUrl): void
    {
        $origin = PackageOrigin::of('acme/lib', '1.0.0', new OriginFacts($url, 'zip', 'https://example.test/lib.zip', 'git', 'https://github.com/acme/lib.git'), ConfiguredRepositories::none());

        self::assertSame(['kind' => $kind, 'registry' => $registry, 'package_url' => $packageUrl, 'local' => false], $origin->toArray());
        self::assertTrue($origin->isComposerRepository());
    }

    /** A manifest cannot move an entry that reports its downloads somewhere: the notification-url decides first. */
    public function testTheManifestNeverOverridesANotificationUrl(): void
    {
        $repositories = ConfiguredRepositories::fromManifest([
            ['type' => 'package', 'package' => ['name' => 'acme/lib', 'version' => '1.0.0', 'dist' => ['type' => 'path', 'url' => 'lib']]],
            ['type' => 'vcs', 'url' => 'https://github.com/acme/lib'],
        ]);

        $copied = PackageOrigin::of('acme/lib', '1.0.0', new OriginFacts(self::PACKAGIST, 'path', 'lib', 'git', 'https://github.com/acme/lib.git'), $repositories);

        self::assertSame('packagist', $copied->kind());
    }

    public function testAPathDistIsAPathUnlessAnInlineDefinitionMatchesIt(): void
    {
        $facts = new OriginFacts(null, 'path', 'packages/lib', null, null);
        $inline = ConfiguredRepositories::fromManifest([['type' => 'package', 'package' => ['name' => 'acme/lib', 'version' => '1.0.0', 'dist' => ['type' => 'path', 'url' => 'packages/lib']]]]);

        self::assertSame(['kind' => 'path', 'registry' => null, 'package_url' => null, 'local' => true], PackageOrigin::of('acme/lib', '1.0.0', $facts, ConfiguredRepositories::none())->toArray());
        self::assertSame('package', PackageOrigin::of('acme/lib', '1.0.0', $facts, $inline)->kind());
        self::assertSame('path', PackageOrigin::of('acme/lib', '2.0.0', $facts, $inline)->kind(), 'another version is not what the definition gives');
    }

    public function testAnEmptyNotificationUrlIsNone(): void
    {
        self::assertSame('unknown', PackageOrigin::of('acme/lib', '1.0.0', new OriginFacts('', null, null, null, null), ConfiguredRepositories::none())->kind());
    }

    public function testAnEntryNoConfiguredRepositoryServedIsUnknown(): void
    {
        $facts = new OriginFacts(null, 'zip', 'https://api.github.com/repos/jquery/jquery-dist/zipball/abc', 'git', 'git@github.com:jquery/jquery-dist.git');

        self::assertSame(['kind' => 'unknown', 'registry' => null, 'package_url' => null, 'local' => false], PackageOrigin::of('bower-asset/jquery', '3.7.1', $facts, ConfiguredRepositories::none())->toArray());
        self::assertSame('unknown', PackageOrigin::of('bower-asset/jquery', '3.7.1', $facts, ConfiguredRepositories::fromManifest([['type' => 'composer', 'url' => 'https://asset-packagist.org']]))->kind());
        self::assertSame('vcs', PackageOrigin::of('bower-asset/jquery', '3.7.1', $facts, ConfiguredRepositories::fromManifest([['type' => 'vcs', 'url' => 'https://github.com/jquery/jquery-dist']]))->kind());
    }

    /** @return iterable<string, array{string}> */
    public static function namesNoUrlIsBuiltFrom(): iterable
    {
        yield 'a path that climbs' => ['a/../../login?next=//evil.example#x'];
        yield 'an attribute' => ['x" onmouseover="alert(1)'];
        yield 'a scheme' => ['javascript:alert(1)//'];
        yield 'a platform package' => ['php'];
        yield 'three segments' => ['acme/lib/extra'];
        yield 'a space' => ['acme/my lib'];
    }

    /** @dataProvider namesNoUrlIsBuiltFrom */
    #[DataProvider('namesNoUrlIsBuiltFrom')]
    public function testAPackagePageIsBuiltOnlyFromAComposerPackageName(string $name): void
    {
        $origin = PackageOrigin::of($name, '1.0.0', new OriginFacts(self::PACKAGIST, null, null, null, null), ConfiguredRepositories::none());

        self::assertSame(['kind' => 'packagist', 'registry' => 'packagist.org', 'package_url' => null, 'local' => false], $origin->toArray());
    }

    public function testAPackagePageReadsTheNameAsComposerDoes(): void
    {
        $origin = PackageOrigin::of('Acme/Lib.Extra--x', '1.0.0', new OriginFacts(self::PACKAGIST, null, null, null, null), ConfiguredRepositories::none());

        self::assertSame('https://packagist.org/packages/acme/lib.extra--x', $origin->packageUrl());
    }

    /** What the entry itself carries — a login, an organisation, a machine path — never reaches the document. */
    public function testNothingOfTheEntrysUrlsIsWritten(): void
    {
        $origins = [
            PackageOrigin::of('acme/lib', '1.0.0', new OriginFacts('https://ci-user:s3cr3t@packagist.org/downloads/', 'zip', '/home/ci-user/artifacts/lib.zip', 'git', 'https://ci-user:s3cr3t@git.acme.test/lib.git'), ConfiguredRepositories::none()),
            PackageOrigin::of('acme/lib', '1.0.0', new OriginFacts('https://repo.packagist.com/secret-org/downloads/', 'zip', 'https://repo.packagist.com/secret-org/dists/acme/lib.zip', null, null), ConfiguredRepositories::none()),
            PackageOrigin::of('acme/lib', '1.0.0', new OriginFacts('https://token@satis.acme.test/downloads', null, null, null, null), ConfiguredRepositories::none()),
        ];
        foreach ($origins as $origin) {
            $json = (string) json_encode($origin->toArray());
            foreach (['ci-user', 's3cr3t', 'secret-org', 'token', 'satis', 'acme.test', 'home'] as $leak) {
                self::assertStringNotContainsString($leak, $json);
            }
        }
    }

    public function testOnlyTheTwoRepositoryKindsAreFromAComposerRepository(): void
    {
        $asked = [];
        foreach (PackageOrigin::KINDS as $kind) {
            if (PackageOrigin::isComposerRepositoryKind($kind)) {
                $asked[] = $kind;
            }
        }

        self::assertSame(['packagist', 'composer'], $asked);
        self::assertFalse(PackageOrigin::isComposerRepositoryKind('acme:mirror'), 'a kind lockrot does not write says nothing');
    }

    public function testTheListsTheSchemasCarry(): void
    {
        self::assertSame(['packagist', 'composer', 'path', 'vcs', 'artifact', 'package', 'unknown'], PackageOrigin::KINDS);
        self::assertSame(['packagist.org', 'repo.packagist.com', 'wp-packages.org', 'packages.drupal.org'], PackageOrigin::REGISTRIES);
    }

    /** The finding's default when a caller passes none: from a Composer repository lockrot does not name, as the old flag's `true` said. */
    public function testTheUnattributedOriginIsAComposerRepositoryWithoutAName(): void
    {
        $origin = PackageOrigin::unattributed();

        self::assertSame(['kind' => 'composer', 'registry' => null, 'package_url' => null, 'local' => false], $origin->toArray());
        self::assertTrue($origin->isComposerRepository());
        self::assertNull($origin->registry());
    }

    /**
     * @dataProvider installs
     */
    #[DataProvider('installs')]
    public function testAnOriginSaysWhetherComposerInstalledFromTheMachine(OriginFacts $facts, ConfiguredRepositories $repositories, bool $local): void
    {
        self::assertSame($local, PackageOrigin::of('acme/lib', '1.0.0', $facts, $repositories)->isLocal());
    }

    /** @return iterable<string, array{OriginFacts, ConfiguredRepositories, bool}> */
    public static function installs(): iterable
    {
        yield 'from packagist.org' => [new OriginFacts(self::PACKAGIST, 'zip', 'https://api.github.com/repos/acme/lib/zipball/x', 'git', 'https://github.com/acme/lib.git'), ConfiguredRepositories::none(), false];
        yield 'a path dist, relative' => [new OriginFacts(null, 'path', 'packages/lib', null, null), ConfiguredRepositories::none(), true];
        yield 'a vcs repository on the disk' => [new OriginFacts(null, null, null, 'git', '/Users/igor/client/lib'), ConfiguredRepositories::none(), true];
        yield 'a vcs repository by a file url' => [new OriginFacts(null, null, null, 'git', 'file:///srv/git/lib.git'), ConfiguredRepositories::none(), true];
        yield 'a dist on a Windows drive' => [new OriginFacts(null, 'zip', 'C:\\artifacts\\lib.zip', null, null), ConfiguredRepositories::none(), true];
        yield 'a vcs repository on a server' => [new OriginFacts(null, null, null, 'git', 'git@github.com:acme/lib.git'), ConfiguredRepositories::none(), false];
        yield 'an artifact repository' => [new OriginFacts(null, 'zip', 'artifacts/lib-1.0.0.zip', null, null), ConfiguredRepositories::fromManifest([['type' => 'artifact', 'url' => 'artifacts/']]), true];
        yield 'nothing to tell by' => [new OriginFacts(null, null, null, null, null), ConfiguredRepositories::none(), false];
    }
}
