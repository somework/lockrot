<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Security;

use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Security\Holder;
use Lockrot\Security\LinkIndex;
use PHPUnit\Framework\TestCase;

final class LinkIndexTest extends TestCase
{
    /** Composer compares package names in lower case. */
    public function testItFindsTheLinksOfANameInAnyCase(): void
    {
        $index = LinkIndex::of(
            LockFile::fromArray(['packages' => [['name' => 'c/c', 'version' => '1.0.0', 'require' => ['A/B' => '^1.0']]]]),
            ProjectConfig::empty()
        );

        $holders = $index->excluding('A/B', '2.0.0.0');
        self::assertCount(1, $holders);
        self::assertSame([Holder::PACKAGE, 'c/c', Holder::REQUIRE], [$holders[0]->source(), $holders[0]->package(), $holders[0]->link()]);
        self::assertSame([], $index->excluding('a/b', '1.5.0.0'));
    }

    /** Composer reads `self.version` as the holding package's own version. */
    public function testASelfVersionRequirementPinsTheReleaseToTheHoldersVersion(): void
    {
        $index = LinkIndex::of(LockFile::fromArray(['packages' => [
            ['name' => 'scheb/2fa-bundle', 'version' => 'v5.13.2'],
            ['name' => 'scheb/2fa-email', 'version' => 'v5.13.2', 'require' => ['scheb/2fa-bundle' => 'self.version']],
        ]]), ProjectConfig::empty());

        self::assertSame(
            [['source' => Holder::PACKAGE, 'package' => 'scheb/2fa-email', 'version' => 'v5.13.2', 'link' => Holder::REQUIRE, 'constraint' => 'self.version']],
            array_map(static fn (Holder $holder): array => $holder->toArray(), $index->excluding('scheb/2fa-bundle', '5.13.3.0'))
        );
        self::assertSame([], $index->excluding('scheb/2fa-bundle', '5.13.2.0'));
    }

    /** A link on a name that the lock satisfies with a replacer holds the replacer, once per holder and link. */
    public function testALinkOnAReplacedNameHoldsTheReplacer(): void
    {
        $index = LinkIndex::of(
            LockFile::fromArray(['packages' => [
                ['name' => 'laravel/framework', 'version' => 'v12.68.0', 'replace' => ['illuminate/support' => 'self.version', 'illuminate/database' => 'self.version']],
                ['name' => 'x/scim', 'version' => '1.0.0', 'require' => ['illuminate/support' => '^10.0|^11.0|^12.0', 'illuminate/database' => '^12.0']],
            ]]),
            ProjectConfig::fromArray(['name' => 'acme/app', 'require' => ['illuminate/support' => '^12.0']])
        );

        $holders = $index->excluding('laravel/framework', '13.0.0.0');
        self::assertSame(
            [[Holder::ROOT, 'acme/app', '^12.0'], [Holder::PACKAGE, 'x/scim', '^10.0|^11.0|^12.0']],
            array_map(static fn (Holder $holder): array => [$holder->source(), $holder->package(), $holder->constraint()], $holders)
        );
        self::assertSame([], $index->excluding('laravel/framework', '12.70.0.0'));
    }

    /** Composer applies a conflict to the names a package has and replaces, not to the names it provides. */
    public function testAConflictOnAProvidedNameHoldsNothing(): void
    {
        $index = LinkIndex::of(LockFile::fromArray(['packages' => [
            ['name' => 'v/p', 'version' => '2.0.0', 'provide' => ['x/virtual' => 'self.version']],
            ['name' => 'v/q', 'version' => '1.0.0', 'conflict' => ['x/virtual' => '>=2.1']],
            ['name' => 'v/r', 'version' => '1.0.0', 'require' => ['x/virtual' => '^2.0']],
        ]]), ProjectConfig::empty());

        self::assertSame([], $index->excluding('v/p', '2.1.0.0'));
        self::assertSame(['v/r'], array_map(static fn (Holder $holder): ?string => $holder->package(), $index->excluding('v/p', '3.0.0.0')));
    }

    /** Composer satisfies a name with the package of that name when the lock carries one. */
    public function testALockedPackageKeepsTheLinksOnItsOwnName(): void
    {
        $index = LinkIndex::of(LockFile::fromArray(['packages' => [
            ['name' => 'laravel/framework', 'version' => 'v12.68.0', 'replace' => ['illuminate/support' => 'self.version']],
            ['name' => 'illuminate/support', 'version' => 'v12.68.0'],
            ['name' => 'x/scim', 'version' => '1.0.0', 'require' => ['illuminate/support' => '^12.0']],
        ]]), ProjectConfig::empty());

        self::assertSame([], $index->excluding('laravel/framework', '13.0.0.0'));
        self::assertCount(1, $index->excluding('illuminate/support', '13.0.0.0'));
    }
}
