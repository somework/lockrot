<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Analyzer;

use Lockrot\Analyzer\PhpWindow;
use Lockrot\Clock;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type Data from PhpWindow
 * @phpstan-import-type Window from PhpWindow
 */
final class PhpWindowTest extends TestCase
{
    private const EVERY_MINOR = ['5.3', '5.4', '5.5', '5.6', '7.0', '7.1', '7.2', '7.3', '7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'];

    public function testTwoDisjointRequirementsLeaveNoMinorAndNameTheClosestMinors(): void
    {
        $window = self::window(['packages' => [self::package('acme/a', ['php' => '<8.0']), self::package('acme/b', ['php' => '>=8.1'])], 'packages-dev' => []]);

        $set = $window['without_dev'];
        self::assertSame([], $set['minors']);
        self::assertFalse($set['gaps']);
        self::assertNull($set['lower_edge']);
        self::assertNull($set['upper_edge']);
        self::assertNotNull($set['closest']);
        self::assertSame(array_values(array_diff(self::EVERY_MINOR, ['8.0'])), $set['closest']['minors'], '8.0 fails both links, every other minor one');
        self::assertSame(['acme/a', 'acme/b'], array_column($set['closest']['set_by'], 'name'));
        self::assertFalse($window['target']['inside']);
    }

    public function testThreeLinksThatOverlapInPairsLeaveNoMinor(): void
    {
        $window = self::window(['packages' => [
            self::package('acme/a', ['php' => '~7.4.0 || ~8.0.0']),
            self::package('acme/b', ['php' => '~8.0.0 || ~8.1.0']),
            self::package('acme/c', ['php' => '~7.4.0 || ~8.1.0']),
        ], 'packages-dev' => []]);

        self::assertSame([], $window['without_dev']['minors']);
        self::assertSame(['minors' => ['7.4', '8.0', '8.1'], 'names' => ['acme/a', 'acme/b', 'acme/c']], self::closest($window['without_dev']));
    }

    public function testAGapIsAMissingKnownMinor(): void
    {
        $lock = ['packages' => [self::package('acme/a', ['php' => '7.1.* || >=8.1'])], 'packages-dev' => []];

        $gap = self::window($lock, ['require' => ['php' => '>=7.1']])['without_dev'];
        $noGap = self::window($lock, ['require' => ['php' => '>=7.2']])['without_dev'];

        self::assertSame(['7.1', '8.1', '8.2', '8.3', '8.4', '8.5'], $gap['minors']);
        self::assertTrue($gap['gaps']);
        self::assertNull($gap['upper_edge']);
        self::assertSame(['8.1', '8.2', '8.3', '8.4', '8.5'], $noGap['minors']);
        self::assertFalse($noGap['gaps']);
    }

    public function testPhpSevenFiveToSevenNineAreNoGap(): void
    {
        $set = self::window(['packages' => [self::package('acme/a', ['php' => '~7.4.0 || ~8.0.0'])], 'packages-dev' => []])['without_dev'];

        self::assertSame(['7.4', '8.0'], $set['minors']);
        self::assertFalse($set['gaps']);
        self::assertNotNull($set['upper_edge']);
        self::assertSame('8.1', $set['upper_edge']['excludes']);
    }

    /** @return iterable<string, array{0: array<string, string>, 1: array<string, string>, 2: string, 3: string}> */
    public static function lowerEdges(): iterable
    {
        yield 'a patch floor' => [['php' => '>=8.4.1'], [], '8.4.1', 'require'];
        yield 'the second known minor' => [['php' => '>=5.4'], [], '5.4.0', 'require'];
        yield 'an exclusive minor' => [['php' => '>7.4'], [], '7.4.1', 'require'];
        yield 'an exclusive dev version' => [['php' => '>7.4.3-dev'], [], '7.4.3', 'require'];
        yield 'a conflict that ends inside the minor' => [['php' => '>=7.4'], ['php' => '7.4.0 - 7.4.1'], '7.4.2', 'conflict'];
        yield 'a conflict below a minor' => [[], ['php' => '<8.1'], '8.1.0', 'conflict'];
    }

    /**
     * @dataProvider lowerEdges
     *
     * @param array<string, string> $require
     * @param array<string, string> $conflict
     */
    #[DataProvider('lowerEdges')]
    public function testTheLowerEdgeIsTheLowestPointAndTheLinkThatFailsJustBelowIt(array $require, array $conflict, string $php, string $link): void
    {
        $set = self::window(['packages' => [self::package('acme/a', $require, $conflict)], 'packages-dev' => []])['without_dev'];

        self::assertNotNull($set['lower_edge']);
        self::assertSame($php, $set['lower_edge']['php']);
        self::assertSame([$link], array_column($set['lower_edge']['set_by'], 'link'));
    }

    public function testAConflictWithAnOpenEndSetsTheUpperEdge(): void
    {
        $set = self::window(['packages' => [self::package('acme/a', ['php' => '>=8.0'], ['php' => '>=8.5'])], 'packages-dev' => []])['without_dev'];

        self::assertSame(['8.0', '8.1', '8.2', '8.3', '8.4'], $set['minors']);
        self::assertSame('8.0.0', $set['lower_edge']['php'] ?? null);
        self::assertSame(['excludes' => '8.5', 'links' => ['conflict']], self::upper($set));
    }

    public function testPhp64bitIsReadAsAVersion(): void
    {
        $set = self::window(['packages' => [self::package('acme/a', ['php' => '>=8.0', 'php-64bit' => '<8.3'])], 'packages-dev' => []])['without_dev'];

        self::assertSame(['8.0', '8.1', '8.2'], $set['minors']);
        self::assertNotNull($set['upper_edge']);
        self::assertSame('php-64bit', $set['upper_edge']['set_by'][0]['target']);
    }

    public function testAHostFeatureIsListedAndLeavesTheWindowAlone(): void
    {
        $window = self::window(
            ['packages' => [self::package('acme/a', ['php' => '>=8.2', 'php-zts' => '*']), self::package('acme/b', [], ['php-debug' => '*'])], 'packages-dev' => []],
            ['require' => ['php-ipv6' => '*']]
        );

        self::assertSame(['php-ipv6', 'php-zts'], $window['host_requires'], 'a conflict names no host feature');
        self::assertSame(['8.2', '8.3', '8.4', '8.5'], $window['without_dev']['minors']);
    }

    public function testTheHostFeaturesOfBothSetsAreListedOnceInNameOrder(): void
    {
        $window = self::window(
            ['packages' => [self::package('acme/a', ['php-ipv6' => '*']), self::package('acme/b', ['php-zts' => '*'])], 'packages-dev' => [self::package('acme/tool', ['php-debug' => '*'])]],
            ['require' => ['php-zts' => '*']]
        );

        self::assertSame(['php-debug', 'php-ipv6', 'php-zts'], $window['host_requires'], 'php-debug comes from require-dev only, php-zts from the root and acme/b');
    }

    public function testEachRootLinkFromTheLockKeepsTheSideItComesFrom(): void
    {
        $window = self::window(
            ['packages' => [], 'packages-dev' => [], 'platform' => ['php' => '>=8.1'], 'platform-dev' => ['php-64bit' => '<8.5']],
            ['conflict' => ['php' => '>=8.5']]
        );
        $platform = ['name' => null, 'version' => null, 'link' => 'require', 'target' => 'php', 'constraint' => '>=8.1', 'dev' => false];
        $conflict = ['name' => null, 'version' => null, 'link' => 'conflict', 'target' => 'php', 'constraint' => '>=8.5', 'dev' => false];
        $platformDev = ['name' => null, 'version' => null, 'link' => 'require', 'target' => 'php-64bit', 'constraint' => '<8.5', 'dev' => true];

        $withDev = $window['with_dev'];
        self::assertNotNull($withDev);
        self::assertSame(['8.1', '8.2', '8.3', '8.4'], $withDev['minors'], 'platform-dev adds to platform');
        self::assertSame(['php' => '8.1.0', 'set_by' => [$platform]], $withDev['lower_edge']);
        self::assertSame(['excludes' => '8.5', 'set_by' => [$conflict, $platformDev]], $withDev['upper_edge']);
        self::assertSame(['php' => '8.1.0', 'set_by' => [$platform]], $window['without_dev']['lower_edge']);
        self::assertSame(['excludes' => '8.5', 'set_by' => [$conflict]], $window['without_dev']['upper_edge']);
    }

    public function testTheRootRequireDevPhpReplacesTheRequirePhpWithRequireDevOnly(): void
    {
        $window = self::window(
            ['packages' => [self::package('acme/a', ['php' => '>=7.0'])], 'packages-dev' => [self::package('acme/tool', ['php' => '>=7.4'])]],
            ['require' => ['php' => '>=8.1'], 'require-dev' => ['php' => '>=7.4']]
        );

        self::assertNotNull($window['with_dev']);
        self::assertSame('7.4', $window['with_dev']['minors'][0], 'require-dev widens the window');
        self::assertSame('8.1', $window['without_dev']['minors'][0]);
        self::assertNotNull($window['with_dev']['lower_edge']);
        self::assertSame([
            ['name' => null, 'version' => null, 'link' => 'require', 'target' => 'php', 'constraint' => '>=7.4', 'dev' => true],
            ['name' => 'acme/tool', 'version' => '1.0.0', 'link' => 'require', 'target' => 'php', 'constraint' => '>=7.4', 'dev' => true],
        ], $window['with_dev']['lower_edge']['set_by']);
    }

    public function testALockOnlyRunReadsPlatformDevInPlaceOfPlatformWithRequireDev(): void
    {
        $window = self::window(['packages' => [], 'packages-dev' => [], 'platform' => ['php' => '>=8.2'], 'platform-dev' => ['php' => '>=8.0']], null);

        self::assertNotNull($window['with_dev']);
        self::assertSame('8.0', $window['with_dev']['minors'][0]);
        self::assertSame('8.2', $window['without_dev']['minors'][0]);
    }

    public function testTheLockPlatformFillsInOnlyTheNamesThatComposerJsonLacks(): void
    {
        $set = self::window(
            ['packages' => [], 'packages-dev' => [], 'platform' => ['php' => '>=7.0', 'php-64bit' => '<8.3']],
            ['require' => ['php' => '>=8.0']]
        )['without_dev'];

        self::assertSame(['8.0', '8.1', '8.2'], $set['minors']);
        self::assertSame('>=8.0', $set['lower_edge']['set_by'][0]['constraint'] ?? null);
        self::assertSame(['excludes' => '8.3', 'links' => ['require']], self::upper($set));
    }

    public function testALockWithoutPackagesDevHasNoSetWithRequireDev(): void
    {
        $window = self::window(['packages' => [self::package('acme/a', ['php' => '>=8.0'])]], ['require' => ['php' => '>=8.1']]);

        self::assertNull($window['with_dev']);
        self::assertSame('8.1', $window['without_dev']['minors'][0]);
        self::assertTrue($window['target']['inside']);
    }

    public function testAnEmptySetWithRequireDevNamesTheLinksThatAloneExcludeTheClosestMinors(): void
    {
        $window = self::window(
            ['packages' => [self::package('acme/installer', ['php' => '<8.4'])], 'packages-dev' => [self::package('symfony/string', ['php' => '>=8.4.1'], [], 'v8.1.2')]],
            ['require' => ['php' => '~8.1.0 || ~8.2.0 || ~8.3.0']]
        );

        self::assertNotNull($window['with_dev']);
        self::assertSame([], $window['with_dev']['minors']);
        self::assertSame(['minors' => ['8.1', '8.2', '8.3'], 'names' => ['symfony/string']], self::closest($window['with_dev']));
        self::assertSame(['8.1', '8.2', '8.3'], $window['without_dev']['minors']);
        self::assertFalse($window['target']['inside'], 'the set with require-dev decides');
    }

    public function testTheTargetMinorIsReadAtConfigPlatformPhp(): void
    {
        $window = self::window(['packages' => [self::package('acme/a', ['php' => '<8.5'], ['php' => '8.4.0'])], 'packages-dev' => []], ['config' => ['platform' => ['php' => '8.4.0']]]);

        self::assertSame(['php' => '8.4.0', 'inside' => false], $window['target']);
        self::assertSame(['excludes' => '8.4', 'links' => ['conflict']], self::upper($window['without_dev']), 'the conflict matches 8.4.0, not 8.4.99');
    }

    public function testConfigPlatformPhpBelowAPatchFloorLeavesTheTargetMinorOut(): void
    {
        $window = self::window(['packages' => [self::package('acme/a', ['php' => '>=8.2.1'])], 'packages-dev' => []], ['config' => ['platform' => ['php' => '8.2.0']]], '8.2');

        self::assertSame(['8.3', '8.4', '8.5'], $window['without_dev']['minors']);
        self::assertSame(['php' => '8.2.0', 'inside' => false], $window['target']);
        self::assertSame('>=8.2.1', $window['without_dev']['lower_edge']['set_by'][0]['constraint'] ?? null, 'read at the target point below the edge');
    }

    public function testACapInsideAMinorLeavesThatMinorOut(): void
    {
        $set = self::window(['packages' => [self::package('acme/a', ['php' => '<8.4.5'])], 'packages-dev' => []], [], '8.3')['without_dev'];

        self::assertSame('8.3', $set['minors'][\count($set['minors']) - 1]);
        self::assertSame(['excludes' => '8.4', 'links' => ['require']], self::upper($set));
    }

    public function testACapAboveTheNewestMinorLeavesNoUpperEdge(): void
    {
        $set = self::window(['packages' => [self::package('acme/a', ['php' => '<8.6'])], 'packages-dev' => []])['without_dev'];

        self::assertSame(self::EVERY_MINOR, $set['minors']);
        self::assertNull($set['upper_edge']);
    }

    public function testNoPhpLinkAdmitsEveryKnownMinor(): void
    {
        $window = self::window(['packages' => [self::package('acme/a')], 'packages-dev' => []]);

        self::assertSame(self::EVERY_MINOR, $window['without_dev']['minors']);
        self::assertSame(['php' => '5.3.0', 'set_by' => []], $window['without_dev']['lower_edge'], 'no known minor comes before');
        self::assertSame(['php' => '8.4.99', 'inside' => true], $window['target']);
        self::assertNull($window['platform_override']);
    }

    public function testARootConflictMakesAGap(): void
    {
        $set = self::window(['packages' => [], 'packages-dev' => []], ['require' => ['php' => '>=8.0'], 'conflict' => ['php' => '8.3.*']])['without_dev'];

        self::assertSame(['8.0', '8.1', '8.2', '8.4', '8.5'], $set['minors']);
        self::assertTrue($set['gaps']);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: ?array<string, mixed>}> */
    public static function unknowable(): iterable
    {
        yield 'a package that provides php' => [['packages' => [self::package('acme/a') + ['provide' => ['php' => '8.4.0']]]], null];
        yield 'a package that replaces php-64bit' => [['packages' => [self::package('acme/a') + ['replace' => ['php-64bit' => '*']]]], null];
        yield 'a root that replaces php' => [['packages' => []], ['replace' => ['PHP' => '*']]];
        yield 'a root php link that does not parse' => [['packages' => []], ['require' => ['php' => '>=8.0 <<<']]];
        yield 'a lock platform link that does not parse' => [['packages' => [], 'platform' => ['php-64bit' => 'not a constraint']], null];
    }

    /**
     * @dataProvider unknowable
     *
     * @param array<string, mixed>  $lock
     * @param ?array<string, mixed> $composer
     */
    #[DataProvider('unknowable')]
    public function testTheWindowIsNullWhenItIsUnknowable(array $lock, ?array $composer): void
    {
        self::assertNull(PhpWindow::of(LockFile::fromArray($lock), $composer === null ? ProjectConfig::empty() : ProjectConfig::fromArray($composer), '8.4', self::minors()));
    }

    public function testAProvidedExtensionKeepsTheWindow(): void
    {
        self::assertNotNull(PhpWindow::of(LockFile::fromArray(['packages' => [self::package('acme/a') + ['provide' => ['ext-json' => '*']]]]), ProjectConfig::empty(), '8.4', self::minors()));
        self::assertNotNull(PhpWindow::of(LockFile::fromArray(['packages' => []]), ProjectConfig::fromArray(['provide' => ['ext-json' => '*'], 'replace' => ['ext-mbstring' => '*']]), '8.4', self::minors()), 'the root');
    }

    public function testATargetPointPastTheNewestPatchIsTheLowerEdgeOfItsMinor(): void
    {
        $window = self::window(
            ['packages' => [self::package('acme/a', ['php' => '>=8.4.100']), self::package('acme/b', ['php' => '>=8.4.50'])], 'packages-dev' => []],
            ['config' => ['platform' => ['php' => '8.4.100']]]
        );
        $set = $window['without_dev'];

        self::assertSame(['8.4', '8.5'], $set['minors']);
        self::assertNotNull($set['lower_edge']);
        self::assertSame('8.4.100', $set['lower_edge']['php']);
        self::assertSame(['acme/a'], array_column($set['lower_edge']['set_by'], 'name'), 'read at 8.4.99, where acme/b admits');
    }

    public function testTheLockOverrideIsReportedAndDoesNotMoveTheWindow(): void
    {
        $window = self::window(['packages' => [self::package('acme/a', ['php' => '>=8.0'])], 'packages-dev' => [], 'platform-overrides' => ['php' => '7.4.29']]);

        self::assertSame('7.4.29', $window['platform_override']);
        self::assertSame('8.0', $window['without_dev']['minors'][0]);
    }

    public function testTheRootAliasOfALockedPackageAddsNoLink(): void
    {
        $window = self::window([
            'packages' => [self::package('acme/a', ['php' => '<8.0'], [], 'dev-main')],
            'packages-dev' => [],
            'aliases' => [['package' => 'acme/a', 'version' => 'dev-main', 'alias' => '1.0.x-dev', 'alias_normalized' => '1.0.9999999.9999999-dev']],
        ]);

        self::assertNotNull($window['without_dev']['upper_edge']);
        self::assertSame(['dev-main'], array_column($window['without_dev']['upper_edge']['set_by'], 'version'));
    }

    public function testWallabagsUpperEdgeIsSetByTheGuzzleAdapterAndItsLowerEdgeByAConflict(): void
    {
        $dir = __DIR__.'/../../fixtures/sites/wallabag-2.6.14';
        $lock = LockFile::fromFile($dir.'/composer.lock');
        $window = PhpWindow::of($lock, ProjectConfig::fromFile($dir.'/composer.json'), '7.4', self::minors());

        self::assertNotNull($window);
        $data = $window->toArray();
        $set = $data['with_dev'];
        self::assertNotNull($set);
        self::assertSame(['7.4'], $set['minors']);
        self::assertSame(['excludes' => '8.0', 'links' => ['require']], self::upper($set));
        self::assertSame('php-http/guzzle5-adapter', $set['upper_edge']['set_by'][0]['name'] ?? null);
        self::assertNotNull($set['lower_edge']);
        self::assertSame('7.4.2', $set['lower_edge']['php']);
        self::assertSame([['php-amqplib/php-amqplib', 'conflict']], array_map(static fn (array $req): array => [$req['name'], $req['link']], $set['lower_edge']['set_by']));
        self::assertSame('7.4.29', $data['platform_override']);
        $adapter = $lock->find('php-http/guzzle5-adapter');
        self::assertNotNull($adapter);
        self::assertSame('^7.0', $adapter->requirePhp(), 'the installed php link stays the package\'s own');
    }

    /** @return list<string> */
    private static function minors(): array
    {
        return PhpReleaseDates::load()->minors(Clock::fixed('2026-10-01T00:00:00+00:00'));
    }

    /**
     * @param array<string, mixed>  $lock
     * @param ?array<string, mixed> $composer null for a lock-only run
     *
     * @return Data
     */
    private static function window(array $lock, ?array $composer = [], string $target = '8.4'): array
    {
        $window = PhpWindow::of(LockFile::fromArray($lock), $composer === null ? ProjectConfig::empty() : ProjectConfig::fromArray($composer), $target, self::minors());
        self::assertNotNull($window);

        return $window->toArray();
    }

    /**
     * @param array<string, string> $require
     * @param array<string, string> $conflict
     *
     * @return array<string, mixed>
     */
    private static function package(string $name, array $require = [], array $conflict = [], string $version = '1.0.0'): array
    {
        return ['name' => $name, 'version' => $version] + ($require === [] ? [] : ['require' => $require]) + ($conflict === [] ? [] : ['conflict' => $conflict]);
    }

    /**
     * @param Window $set
     *
     * @return array{excludes: string, links: list<string>}
     */
    private static function upper(array $set): array
    {
        self::assertNotNull($set['upper_edge']);

        return ['excludes' => $set['upper_edge']['excludes'], 'links' => array_column($set['upper_edge']['set_by'], 'link')];
    }

    /**
     * @param Window $set
     *
     * @return array{minors: list<string>, names: list<?string>}
     */
    private static function closest(array $set): array
    {
        self::assertNotNull($set['closest']);

        return ['minors' => $set['closest']['minors'], 'names' => array_column($set['closest']['set_by'], 'name')];
    }
}
