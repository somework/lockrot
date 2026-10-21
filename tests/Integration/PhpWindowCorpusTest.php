<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Analyzer\PhpWindow;
use Lockrot\Clock;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The windows of the fixture locks, as gate G-A measured them with `composer install --dry-run`
 * (tests/fixtures/php-window-oracle/README.md).
 *
 * @phpstan-import-type Data from PhpWindow
 */
final class PhpWindowCorpusTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../fixtures/';
    /** A target outside the known minors reads every minor at X.Y.99, as G-A set `platform-overrides.php`. */
    private const NO_KNOWN_MINOR = '9.9';

    /** @var array<string, array{0: string, 1: string}> lock => [with require-dev, without], each `first to last` or `none` */
    private const WINDOWS = [
        'apps/BookStackApp_BookStack' => ['8.2 to 8.5', '8.2 to 8.5'],
        'apps/PrestaShop_PrestaShop' => ['8.1 to 8.2', '8.1 to 8.2'],
        'apps/drupal_drupal' => ['8.5 to 8.5', '8.5 to 8.5'],
        'apps/firefly-iii_firefly-iii' => ['8.5 to 8.5', '8.5 to 8.5'],
        'apps/grokability_snipe-it' => ['8.2 to 8.5', '8.2 to 8.5'],
        'apps/koel_koel' => ['8.3 to 8.5', '8.3 to 8.5'],
        'apps/magento_magento2' => ['8.3 to 8.5', '8.3 to 8.5'],
        'apps/matomo-org_matomo' => ['8.1 to 8.5', '8.1 to 8.5'],
        'apps/mautic_mautic' => ['8.2 to 8.3', '8.2 to 8.3'],
        'apps/monicahq_monica' => ['8.3 to 8.4', '8.3 to 8.4'],
        'apps/nextcloud_3rdparty' => ['8.3 to 8.5', '8.3 to 8.5'],
        'apps/wallabag_wallabag' => ['8.2 to 8.4', '8.2 to 8.4'],
        'sites/drupal-site' => ['7.3 to 8.1', '7.3 to 8.1'],
        'sites/wallabag-2.6.14' => ['7.4 to 7.4', '7.4 to 7.4'],
        'skeletons/bedrock' => ['8.4 to 8.5', '8.3 to 8.5'],
        'skeletons/cakephp' => ['8.4 to 8.5', '8.2 to 8.5'],
        'skeletons/drupal' => ['8.3 to 8.5', '8.3 to 8.5'],
        'skeletons/laminas' => ['none', '8.2 to 8.3'],
        'skeletons/laravel' => ['8.4 to 8.5', '8.4 to 8.5'],
        'skeletons/shopware' => ['8.4 to 8.5', '8.4 to 8.5'],
        'skeletons/sylius' => ['8.4 to 8.5', '8.4 to 8.5'],
        'skeletons/symfony-webapp' => ['8.4 to 8.5', '8.4 to 8.5'],
        'skeletons/yii2' => ['8.4 to 8.5', '8.4 to 8.5'],
    ];

    /** @return iterable<string, array{0: string, 1: string, 2: string}> */
    public static function locks(): iterable
    {
        foreach (self::WINDOWS as $lock => [$withDev, $withoutDev]) {
            yield $lock => [$lock, $withDev, $withoutDev];
        }
    }

    /**
     * @dataProvider locks
     */
    #[DataProvider('locks')]
    public function testTheWindowOfEachFixtureLockIsTheOneComposerInstalls(string $lock, string $withDev, string $withoutDev): void
    {
        $window = self::window($lock);
        $known = self::minors();

        foreach (['with_dev' => $withDev, 'without_dev' => $withoutDev] as $key => $expected) {
            $set = $window[$key];
            self::assertNotNull($set, $lock.' '.$key);
            self::assertSame($expected, $set['minors'] === [] ? 'none' : $set['minors'][0].' to '.$set['minors'][\count($set['minors']) - 1], $lock.' '.$key);
            self::assertFalse($set['gaps'], $lock.' '.$key);
            if ($set['minors'] === []) {
                self::assertNull($set['lower_edge'], $lock.' '.$key);
                self::assertNotNull($set['closest'], $lock.' '.$key);
                continue;
            }
            self::assertNotNull($set['lower_edge'], $lock.' '.$key);
            self::assertSame($set['minors'][0], PhpReleaseDates::minorOf($set['lower_edge']['php']), $lock.' '.$key.': the lower edge lies in the first minor');
            self::assertSame($set['minors'][\count($set['minors']) - 1] === $known[\count($known) - 1], $set['upper_edge'] === null, $lock.' '.$key.': no upper edge exactly when the newest minor is in');
            self::assertNotSame([], $set['upper_edge']['set_by'] ?? ['the newest minor is in'], $lock.' '.$key);
        }
    }

    public function testEveryFixtureLockIsPinned(): void
    {
        $locks = [];
        foreach (['apps', 'sites', 'skeletons'] as $group) {
            foreach (glob(self::FIXTURES.$group.'/*/composer.lock') ?: [] as $path) {
                $locks[] = $group.'/'.basename(\dirname($path));
            }
        }
        sort($locks);
        $pinned = array_keys(self::WINDOWS);
        sort($pinned);

        self::assertNotSame([], $locks);
        self::assertSame($pinned, $locks);
    }

    /** @return Data */
    private static function window(string $lock): array
    {
        $dir = self::FIXTURES.$lock;
        $window = PhpWindow::of(LockFile::fromFile($dir.'/composer.lock'), ProjectConfig::fromFile($dir.'/composer.json'), self::NO_KNOWN_MINOR, self::minors());
        self::assertNotNull($window, $lock);

        return $window->toArray();
    }

    /** @return list<string> */
    private static function minors(): array
    {
        return PhpReleaseDates::load()->minors(Clock::fixed('2026-10-21T00:00:00+00:00'));
    }
}
