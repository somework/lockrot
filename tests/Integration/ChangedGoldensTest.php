<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Analyzer\RunSettings;
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
use Lockrot\Verdict\FailOn;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * The pin of the formatter goldens: the github, gitlab, sarif, markdown and table output of four
 * fixture locks at --fail-on=high, byte for byte. A golden whose bytes differ from RELEASED has a
 * row in CHANGED with its reason, and every row of CHANGED names such a golden.
 * tools/fixtures/dump-app-formats.php at the base and at the head finds a changed golden.
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
    private const CHANGELOG = __DIR__.'/../../CHANGELOG.md';
    private const DIRS = ['apps/wallabag_wallabag', 'apps/nextcloud_3rdparty', 'skeletons/laravel', 'apps/matomo-org_matomo'];
    private const FORMATS = ['github' => 'txt', 'gitlab' => 'json', 'sarif' => 'sarif', 'markdown' => 'md', 'table' => 'txt'];
    private const NOW = '2026-09-14T00:00:00+00:00';

    private const TEXT = 'SPEC 7.0 to 7.3: each graded finding prints its flags, score line, move and evidence under its grade; a vulnerable-only finding becomes a row; the footer adds flags:, depth:, security:, advisories: and gate:';
    private const RECORDS = 'SPEC 7.4, 7.5: one record per counted flag, and in SARIF per counted advisory, marked by gate.by; SARIF writes the invocation exit code and the run properties';

    /**
     * The sha256 of each golden as the newest release shipped it, empty while no release shipped
     * them. A release pull request pins the goldens here and empties CHANGED (CONTRIBUTING, "Cutting
     * a release").
     *
     * @var array<string, string>
     */
    private const RELEASED = [];

    /** The release whose goldens RELEASED pins, empty while no release shipped them. */
    private const RELEASED_AT = '';

    /** The first release that ships these goldens. */
    private const FIRST_RELEASE = '0.14.0';

    /** Each golden whose bytes differ from RELEASED, with its reason. */
    private const CHANGED = [
        'laravel.github.txt' => self::TEXT,
        'laravel.gitlab.json' => self::RECORDS,
        'laravel.markdown.md' => self::TEXT,
        'laravel.sarif.sarif' => self::RECORDS,
        'laravel.table.txt' => self::TEXT,
        'matomo-org_matomo.github.txt' => self::TEXT,
        'matomo-org_matomo.gitlab.json' => self::RECORDS,
        'matomo-org_matomo.markdown.md' => self::TEXT,
        'matomo-org_matomo.sarif.sarif' => self::RECORDS,
        'matomo-org_matomo.table.txt' => self::TEXT,
        'nextcloud_3rdparty.github.txt' => self::TEXT,
        'nextcloud_3rdparty.gitlab.json' => self::RECORDS,
        'nextcloud_3rdparty.markdown.md' => self::TEXT,
        'nextcloud_3rdparty.sarif.sarif' => self::RECORDS,
        'nextcloud_3rdparty.table.txt' => self::TEXT,
        'wallabag_wallabag.github.txt' => self::TEXT,
        'wallabag_wallabag.gitlab.json' => self::RECORDS,
        'wallabag_wallabag.markdown.md' => self::TEXT,
        'wallabag_wallabag.sarif.sarif' => self::RECORDS,
        'wallabag_wallabag.table.txt' => self::TEXT,
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
        $rewritten = [];
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
                    $clock,
                    false
                );
                $report = $analyzer->analyze(LockFile::fromFile(self::FIXTURES.$dir.'/composer.lock'), ProjectConfig::fromFile(self::FIXTURES.$dir.'/composer.json'), false)
                    ->withRun(new RunSettings(null, null, '8.4', RunSettings::SOURCE_OPTION, null, FailOn::parse('high'), RunSettings::SOURCE_OPTION, null));
                foreach (self::FORMATS as $format => $extension) {
                    $name = basename($dir).'.'.$format.'.'.$extension;
                    $names[] = $name;
                    $text = Formatters::for($format, FormatContext::create(null, 'high'))->format($report);
                    if (getenv('LOCKROT_REWRITE_GOLDEN') === '1') {
                        self::assertNotFalse(file_put_contents(self::GOLDEN.$name, $text), 'cannot write '.$name);
                        $rewritten[] = $name;
                    }
                    if (@file_get_contents(self::GOLDEN.$name) !== $text) {
                        $differ[] = $name;
                    }
                }
            }
        } finally {
            $server->stop();
        }

        if ($rewritten !== []) {
            self::fail('rewrote '.implode(', ', $rewritten).'; review the diff, give each changed file a row in CHANGED, and run again without LOCKROT_REWRITE_GOLDEN');
        }
        self::assertSame([], $differ, 'rewrite with LOCKROT_REWRITE_GOLDEN=1 vendor/bin/phpunit --filter ChangedGoldensTest, review the diff, and give each changed file a row in CHANGED');
        self::assertSame([], array_values(array_diff(array_keys(self::CHANGED), $names)), 'CHANGED names a golden that no output writes');
        $released = self::released();
        $moved = array_values(array_filter($names, static fn (string $name): bool => ($released[$name] ?? null) !== hash_file('sha256', self::GOLDEN.$name)));
        $listed = array_keys(self::CHANGED);
        sort($moved);
        sort($listed);
        self::assertSame($moved, $listed, 'a golden whose bytes differ from RELEASED needs a row in CHANGED, and a row in CHANGED needs such a golden');
    }

    /**
     * The release pull request dates the release in CHANGELOG.md, so it must pin RELEASED and name
     * the release in RELEASED_AT in the same change (CONTRIBUTING, "Cutting a release").
     */
    public function testTheGoldensArePinnedAsTheNewestReleaseShippedThem(): void
    {
        preg_match('/^## \[(\d+\.\d+\.\d+)\] - /m', (string) file_get_contents(self::CHANGELOG), $newest);
        $version = $newest[1] ?? '';
        self::assertNotSame('', $version, 'CHANGELOG.md names a release');
        $shipped = version_compare($version, self::FIRST_RELEASE, '>=');

        self::assertSame($shipped ? $version : '', self::releasedAt(), 'pin RELEASED and set RELEASED_AT to '.$version);
        $pinned = array_keys(self::released());
        sort($pinned);
        self::assertSame($shipped ? self::goldenNames() : [], $pinned, 'RELEASED pins every golden exactly when RELEASED_AT names a release');
        self::assertSame([], $shipped ? array_keys(self::CHANGED) : [], 'the release pull request empties CHANGED');
    }

    /** @return list<string> sorted */
    private static function goldenNames(): array
    {
        $names = [];
        foreach (self::DIRS as $dir) {
            foreach (self::FORMATS as $format => $extension) {
                $names[] = basename($dir).'.'.$format.'.'.$extension;
            }
        }
        sort($names);

        return $names;
    }

    private static function releasedAt(): string
    {
        return self::RELEASED_AT;
    }

    /** @return array<string, string> RELEASED, typed for PHPStan, which reads an empty constant as array{} */
    private static function released(): array
    {
        return self::RELEASED;
    }
}
