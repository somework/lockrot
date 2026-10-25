<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Composer\Repository\AdvisoryProviderInterface;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\WallabagSite;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The move words of SPEC §5.6 on {@see WallabagSite}, whose lock records `config.platform.php`
 * 7.4.29: inside the minor of the target 7.4, outside that of 8.4.
 *
 * @coversNothing
 *
 * @group covers-nothing
 */
#[CoversNothing]
#[Group('covers-nothing')]
final class MoveWordsWallabagTest extends TestCase
{
    /** Composer refuses the lock itself at 8.4, and the target lies outside the lock's PHP window. */
    private const PLATFORM_STEP = 'First set config.platform.php to your PHP 8.4 release: the lock records 7.4.29. Composer still refuses the lock itself: the PHP line names the packages. ';

    /** @var array<string, array<mixed, mixed>> each report by its target PHP */
    private static array $reports = [];

    public static function setUpBeforeClass(): void
    {
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            self::markTestSkipped('Composer 2.2 has no AdvisoryProviderInterface: no move reaches a vulnerable package');
        }
        foreach (['7.4', '8.4'] as $target) {
            self::$reports[$target] = JsonPath::decoded(WallabagSite::analyze($target)->toArray());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function scheb(): iterable
    {
        foreach (['scheb/2fa-backup-code', 'scheb/2fa-bundle', 'scheb/2fa-email', 'scheb/2fa-google-authenticator', 'scheb/2fa-trusted-device'] as $package) {
            yield $package => [$package];
        }
    }

    /**
     * v6.13.0 requires `~8.2.0 || ~8.3.0 || ~8.4.0`, and v6.13.1, the newest release of 6.x, starts
     * at `~8.0.0` (the recorded metadata of scheb/2fa-bundle).
     *
     * @dataProvider scheb
     */
    #[DataProvider('scheb')]
    public function testTheRaiseReadsThePhpOfTheNewestReleaseOnTheBranch(string $package): void
    {
        $move = self::move('8.4', $package);

        self::assertSame(['raise-php', 'v6.13.1'], [$move['kind'], $move['newest']]);
        self::assertSame(['~8.0.0 || ~8.1.0 || ~8.2.0 || ~8.3.0 || ~8.4.0', '>=8.0'], [JsonPath::stringAt($move, ['php_check', 'requires']), JsonPath::stringAt($move, ['php_check', 'raise_to'])]);
        self::assertSame(['composer', 'require', 'php:>=8.0', '--no-update'], JsonPath::arrayAt($move, ['commands'])[0]);
    }

    /**
     * @dataProvider scheb
     */
    #[DataProvider('scheb')]
    public function testAMoveWithCommandsFirstSetsThePlatformPhpOutsideTheTargetMinor(string $package): void
    {
        self::assertStringStartsWith(self::PLATFORM_STEP.'raise require.php to >=8.0, then require '.$package.' ^6.13', JsonPath::stringAt(self::move('8.4', $package), ['text']));
    }

    public function testAMoveWithoutCommandsTakesNoPlatformStep(): void
    {
        $move = self::move('8.4', 'scheb/2fa-qr-code');

        self::assertSame([[], 'find a replacement (none named)'], [$move['commands'], $move['text']]);
    }

    public function testAPlatformPhpInsideTheTargetMinorTakesNoPlatformStep(): void
    {
        $texts = [];
        foreach (JsonPath::arrayAt(self::$reports['7.4'], ['findings']) as $finding) {
            if (\is_array($finding) && \is_array($finding['next_step'] ?? null)) {
                $texts[] = JsonPath::stringAt($finding, ['next_step', 'text']);
            }
        }

        self::assertNotSame([], $texts);
        self::assertSame([], array_values(array_filter($texts, static fn (string $text): bool => strpos($text, 'First set config.platform.php') !== false)));
    }

    /** symfony/mailer v5.4.52 is the release that the move to ^5.4.52 installs, and its own links hold the move: they follow the root holds. */
    public function testAHoldOfTheFindingsOwnPackageNamesItsOwnRelease(): void
    {
        $text = JsonPath::stringAt(self::move('7.4', 'symfony/mailer'), ['text']);

        self::assertStringContainsString('composer.json (requires symfony/mime ^4.4.47) and symfony/mailer v5.4.52, its own release (requires psr/event-dispatcher ^1) and symfony/mailer v5.4.52, its own release (requires symfony/mime ^5.2.6|^6.0) and symfony/http-foundation v4.4.49 (requires', $text);
        self::assertStringNotContainsString('symfony/mailer v5.4.52 (requires', $text);
    }

    /** composer.json lists symfony/var-dumper under `require-dev` only: its one root hold on the package names that section. */
    public function testARootHoldNamesTheSectionThatListsThePackageOnce(): void
    {
        $move = self::move('7.4', 'symfony/var-dumper');
        $own = array_values(array_filter(JsonPath::arrayAt($move, ['held_by']), static fn ($hold): bool => \is_array($hold) && $hold['source'] === 'root' && $hold['target'] === null));

        self::assertSame([['require-dev', '^4.4.47']], array_map(static fn (array $hold): array => [$hold['link'], $hold['constraint']], $own));
        self::assertStringContainsString('held by composer.json (requires ^4.4.47 in require-dev) and composer.json (requires symfony/debug-bundle', JsonPath::stringAt($move, ['text']));
    }

    /** twig/twig is locked at v3.11.3: its hold at a newer release is not that finding, so it carries no grade and no flags. */
    public function testAHoldAtAnotherVersionThanTheLockedOneNamesNoHolder(): void
    {
        $move = self::move('7.4', 'symfony/twig-bundle');
        $twig = array_values(array_filter(JsonPath::arrayAt($move, ['held_by']), static fn ($hold): bool => \is_array($hold) && $hold['package'] === 'twig/twig'));

        self::assertCount(1, $twig);
        self::assertNotSame('v3.11.3', $twig[0]['version']);
        self::assertNull($twig[0]['holder']);
        self::assertStringContainsString('twig/twig '.JsonPath::stringAt($twig[0], ['version']).' (requires php >=8.1.0)', JsonPath::stringAt($move, ['text']));
    }

    public function testAHoldAtTheLockedVersionNamesItsHolder(): void
    {
        $holds = array_values(array_filter(JsonPath::arrayAt(self::move('7.4', 'symfony/var-dumper'), ['held_by']), static fn ($hold): bool => \is_array($hold) && $hold['package'] === 'symfony/error-handler'));

        self::assertSame([['v4.4.44', 'high']], array_map(static fn (array $hold): array => [$hold['version'], JsonPath::stringAt($hold, ['holder', 'verdict'])], $holds));
    }

    /** @return array<mixed, mixed> */
    private static function move(string $target, string $package): array
    {
        foreach (JsonPath::arrayAt(self::$reports[$target], ['findings']) as $finding) {
            if (\is_array($finding) && $finding['package'] === $package) {
                return JsonPath::arrayAt($finding, ['next_step']);
            }
        }
        self::fail($package.' has no finding at '.$target);
    }
}
