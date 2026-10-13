<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Clock;
use Lockrot\Data\Forge\ActivityClient;
use Lockrot\Data\Forge\ActivityFetchPlanner;
use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\RepoLocator;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Data\Http\RecordedHttpClient;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\RepositoryMetadataLoader;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Output\FormatContext;
use Lockrot\Output\Formatters;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Verdict\VerdictEngine;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * The pin of the formatter goldens: the github, gitlab, sarif, markdown and table output of four
 * fixture locks at --fail-on=high, byte for byte. A pull request that changes a golden lists the
 * file in CHANGED with its reason: tools/fixtures/dump-app-formats.php at the base and at the head
 * finds them.
 *
 * @coversNothing
 *
 * @group covers-nothing
 */
#[CoversNothing]
#[Group('covers-nothing')]
final class ChangedGoldensTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../fixtures/';
    private const GOLDEN = self::FIXTURES.'golden/formats/';
    private const DIRS = ['apps/wallabag_wallabag', 'apps/nextcloud_3rdparty', 'skeletons/laravel', 'apps/matomo-org_matomo'];
    private const FORMATS = ['github' => 'txt', 'gitlab' => 'json', 'sarif' => 'sarif', 'markdown' => 'md', 'table' => 'txt'];
    private const NOW = '2026-09-14T00:00:00+00:00';
    private const RUN_LEVEL = 'SPEC 6.2: the run-level advisories record. These runs have no advisory lookup, so the record is a warning';

    /** Each golden that a pull request of the 0.14 train changed, with its reason. Every other golden is unchanged. */
    private const CHANGED = [
        'laravel.github.txt' => self::RUN_LEVEL,
        'laravel.gitlab.json' => self::RUN_LEVEL,
        'laravel.sarif.sarif' => self::RUN_LEVEL,
        'matomo-org_matomo.github.txt' => self::RUN_LEVEL,
        'matomo-org_matomo.gitlab.json' => self::RUN_LEVEL,
        'matomo-org_matomo.sarif.sarif' => self::RUN_LEVEL,
        'nextcloud_3rdparty.github.txt' => self::RUN_LEVEL,
        'nextcloud_3rdparty.gitlab.json' => self::RUN_LEVEL,
        'nextcloud_3rdparty.sarif.sarif' => self::RUN_LEVEL,
        'wallabag_wallabag.github.txt' => self::RUN_LEVEL,
        'wallabag_wallabag.gitlab.json' => self::RUN_LEVEL,
        'wallabag_wallabag.sarif.sarif' => self::RUN_LEVEL,
    ];

    /**
     * Every fixture lock served at once, in a process of its own: that much metadata fills
     * Composer's static caches, which stay for every later test of a shared process.
     *
     * @runInSeparateProcess
     */
    #[RunInSeparateProcess]
    public function testEveryFormatterGoldenIsTheOutputByteForByte(): void
    {
        $server = FixtureRepositoryServer::fromLockFiles(array_map(static fn (string $dir): string => self::FIXTURES.$dir.'/composer.lock', self::DIRS));
        $server->start();
        $differ = [];
        $names = [];
        try {
            $clock = Clock::fixed(self::NOW);
            foreach (self::DIRS as $dir) {
                $auth = ForgeAuth::withTokens(new Tokens('recorded', null));
                $analyzer = new Analyzer(
                    new RepositoryMetadataLoader($server->repositories(), $clock),
                    new ActivityClient(new RecordedHttpClient(self::FIXTURES.'http/github'), $auth),
                    new ActivityFetchPlanner($auth),
                    new RepoLocator(),
                    BuiltinAllowlist::load(),
                    SignalSet::default($clock, new Thresholds(), '8.4', PhpReleaseDates::load()),
                    new VerdictEngine(),
                    $clock,
                    false
                );
                $report = $analyzer->analyze(LockFile::fromFile(self::FIXTURES.$dir.'/composer.lock'), ProjectConfig::fromFile(self::FIXTURES.$dir.'/composer.json'), false);
                foreach (self::FORMATS as $format => $extension) {
                    $name = basename($dir).'.'.$format.'.'.$extension;
                    $names[] = $name;
                    $text = Formatters::for($format, FormatContext::create(null, 'high'))->format($report);
                    if (getenv('LOCKROT_REWRITE_GOLDEN') === '1') {
                        file_put_contents(self::GOLDEN.$name, $text);
                    }
                    if (@file_get_contents(self::GOLDEN.$name) !== $text) {
                        $differ[] = $name;
                    }
                }
            }
        } finally {
            $server->stop();
        }

        self::assertSame([], $differ, 'rewrite with LOCKROT_REWRITE_GOLDEN=1 vendor/bin/phpunit --filter ChangedGoldensTest, review the diff, and give each changed file a row in CHANGED');
        self::assertSame([], array_values(array_diff(array_keys(self::CHANGED), $names)), 'CHANGED names a golden that no output writes');
    }
}
