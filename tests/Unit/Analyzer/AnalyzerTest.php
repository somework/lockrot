<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Analyzer;

use Composer\Downloader\TransportException;
use Lockrot\Allowlist\Allowlist;
use Lockrot\Allowlist\AllowlistEntry;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Analyzer\Libyears;
use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunNote;
use Lockrot\Clock;
use Lockrot\Config\LockrotConfig;
use Lockrot\Data\Advisory\Advisory;
use Lockrot\Data\Advisory\AdvisoryBatch;
use Lockrot\Data\Advisory\AdvisoryLoaderInterface;
use Lockrot\Data\Cache\ArrayCache;
use Lockrot\Data\Forge\ActivityClient;
use Lockrot\Data\Forge\ActivityFetchPlanner;
use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\RepoLocator;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Data\Http\CachingHttpClient;
use Lockrot\Data\Http\HttpClientInterface;
use Lockrot\Data\Http\HttpResult;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\MetadataBatch;
use Lockrot\Data\Repository\MetadataLoaderInterface;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Deadline;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\PackageOrigin;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Output\FormatContext;
use Lockrot\Output\TableFormatter;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\RecordedMoveChecker;
use Lockrot\Tests\Support\Summaries;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use Lockrot\Verdict\MoveChecks;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnalyzerTest extends TestCase
{
    /**
     * @param array<string, array{int, string}> $map       url => [status, body]
     * @param \ArrayObject<int, string>|null    $requested receives every URL asked for, in order
     */
    private function http(array $map, ?\ArrayObject $requested = null): HttpClientInterface
    {
        return new class ($map, $requested) implements HttpClientInterface {
            /** @var array<string, array{0: int, 1: string, 2?: list<string>}> */
            private array $map;
            /** @var \ArrayObject<int, string>|null */
            private ?\ArrayObject $requested;

            /**
             * @param array<string, array{0: int, 1: string, 2?: list<string>}> $map
             * @param \ArrayObject<int, string>|null    $requested
             */
            public function __construct(array $map, ?\ArrayObject $requested)
            {
                $this->map = $map;
                $this->requested = $requested;
            }
            public function fetchAll(array $urls, array $headers = []): array
            {
                $out = [];
                foreach ($urls as $url) {
                    if ($this->requested !== null) {
                        $this->requested->append($url);
                    }
                    [$status, $body, $headers] = ($this->map[$url] ?? [404, '']) + [2 => []];
                    $out[$url] = new HttpResult($url, $status, $body, new \DateTimeImmutable('2026-09-14T06:00:00+00:00'), null, $headers);
                }
                return $out;
            }
        };
    }

    /**
     * Ignores the requested names and returns the same prepared batch on every call.
     *
     * @param array<string, PackageMetadata> $metadata
     * @param list<string>                   $notFound
     * @param array<string, string>          $failed
     */
    private function loader(array $metadata = [], array $notFound = [], array $failed = []): MetadataLoaderInterface
    {
        $batch = new MetadataBatch($metadata, $notFound, $failed);

        return new class ($batch) implements MetadataLoaderInterface {
            private MetadataBatch $batch;

            public function __construct(MetadataBatch $batch)
            {
                $this->batch = $batch;
            }

            public function load(array $installedByName): MetadataBatch
            {
                return $this->batch;
            }
        };
    }

    private function analyzer(MetadataLoaderInterface $metadata, HttpClientInterface $http, bool $token, Allowlist $allowlist, bool $offline = false, int $noTokenBudget = ActivityFetchPlanner::DEFAULT_ANONYMOUS_BUDGET, ?ForgeAuth $auth = null, ?AdvisoryLoaderInterface $advisories = null, string $targetPhp = '8.4'): Analyzer
    {
        $clock = Clock::fixed(F::NOW);
        $auth ??= ForgeAuth::withTokens(new Tokens($token ? 't' : null, null));
        return new Analyzer(
            $metadata,
            new ActivityClient($http, $auth),
            new ActivityFetchPlanner($auth, $noTokenBudget),
            new RepoLocator(),
            $allowlist,
            SignalSet::default($clock, new Thresholds(), $targetPhp, PhpReleaseDates::load()),
            $clock,
            $offline,
            $advisories
        );
    }

    /**
     * @param \ArrayObject<string, string>|null $asked
     */
    private function advisories(AdvisoryBatch $batch, ?\ArrayObject $asked = null): AdvisoryLoaderInterface
    {
        return new class ($batch, $asked) implements AdvisoryLoaderInterface {
            private AdvisoryBatch $batch;
            /** @var \ArrayObject<string, string>|null */
            private ?\ArrayObject $asked;

            /** @param \ArrayObject<string, string>|null $asked */
            public function __construct(AdvisoryBatch $batch, ?\ArrayObject $asked)
            {
                $this->batch = $batch;
                $this->asked = $asked;
            }

            public function load(array $versionByName, array $notFromComposerRepository = [], array $aliasVersionsByName = []): AdvisoryBatch
            {
                if ($this->asked !== null) {
                    $this->asked->exchangeArray($versionByName);
                }

                return $this->batch;
            }
        };
    }

    /** Case `move-check‡`, whose lock records a platform PHP outside the minor of its target 8.4. */
    public function testAMoveWithCommandsFirstSetsThePlatformPhpThatTheLockRecords(): void
    {
        $case = CaseHydrator::case('move-check‡');
        $inputs = JsonPath::arrayAt($case, ['inputs']);
        $inputs['php_lock'] = ['platform-overrides' => ['php' => '7.4.29']];
        $case['inputs'] = $inputs;

        $document = JsonPath::decoded(CaseHydrator::report($case)->toArray());

        self::assertNotSame([], JsonPath::arrayAt($document, ['findings', 0, 'next_step', 'commands']));
        self::assertStringStartsWith('First set config.platform.php to your PHP 8.4 release: the lock records 7.4.29. ', JsonPath::stringAt($document, ['findings', 0, 'next_step', 'text']));
    }

    /** @return iterable<string, array{list<string>, bool, string}> the lock's php of each package, whether Composer refuses the lock itself, and the step */
    public static function refusedBaselines(): iterable
    {
        $step = 'First set config.platform.php to your PHP 8.4 release: the lock records 7.4.29.';
        $refused = $step.' Composer still refuses the lock itself: the moves_not_checked note names the first cause. ';
        yield 'a lock that Composer installs' => [[], false, $step.' '];
        yield 'a refused lock with the target inside the window' => [[], true, $refused];
        yield 'a refused lock with the target outside the window' => [['^7.2 || ~8.0.0'], true, $refused];
        yield 'a refused lock with two packages that exclude the target' => [['^7.2 || ~8.0.0', '^7.0'], true, $refused];
    }

    /**
     * Case `move-check‡` under a platform PHP outside the target minor: while Composer refuses the
     * lock itself, the step points at the moves_not_checked note, which the run writes.
     *
     * @param list<string> $phps
     *
     * @dataProvider refusedBaselines
     */
    #[DataProvider('refusedBaselines')]
    public function testThePlatformStepOfARefusedLockPointsAtTheNote(array $phps, bool $refused, string $step): void
    {
        $case = CaseHydrator::case('move-check‡');
        $inputs = JsonPath::arrayAt($case, ['inputs']);
        $inputs['php_lock'] = ['platform-overrides' => ['php' => '7.4.29']];
        $lock = JsonPath::arrayAt($inputs, ['lock']);
        $first = JsonPath::arrayAt($lock, [0]);
        foreach ($phps as $index => $php) {
            $lock[$index] = ['php' => $php] + ($index === 0 ? $first : ['name' => 'acme/old-php'] + $first);
        }
        $inputs['lock'] = $lock;
        if ($refused) {
            $inputs['checks'] = ['baseline' => ['result' => 'refused', 'form' => null, 'reason' => null, 'holds' => [], 'problem' => 'Problem 1']] + JsonPath::arrayAt($inputs, ['checks']);
        }
        $case['inputs'] = $inputs;

        $document = JsonPath::decoded(CaseHydrator::report($case)->toArray());

        self::assertSame([$refused ? 'refused' : 'resolves', $phps === []], [JsonPath::stringAt($document, ['run', 'move_check', 'baseline']), JsonPath::boolAt($document, ['run', 'php_window', 'target', 'inside'])]);
        self::assertStringStartsWith($step.'composer update', JsonPath::stringAt($document, ['findings', 0, 'next_step', 'text']));
        self::assertSame($refused, \in_array('moves_not_checked', array_column(JsonPath::arrayAt($document, ['note_details']), 'code'), true));
    }

    public function testAnalyzeWithFactsKeepsWhatEachFindingWasDecidedOn(): void
    {
        $lock = LockFile::fromArray(['packages' => [
            ['name' => 'vendor/direct', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/'],
            ['name' => 'vendor/local', 'version' => '1.0.0', 'dist' => ['type' => 'path', 'url' => '../local']],
        ]]);
        $project = ProjectConfig::fromArray(['require' => ['vendor/direct' => '^1.0', 'vendor/local' => '*']]);
        $meta = new PackageMetadata('vendor/direct', true, null, true, new \DateTimeImmutable('2024-01-10T10:00:00+00:00'), '1.0.0', 1, null, 'library', new \DateTimeImmutable(F::NOW));
        $advisory = new Advisory('PKSA-1', 'CVE-2024-0001', 'Title', null, 'high', null);
        $analyzer = $this->analyzer($this->loader(['vendor/direct' => $meta]), $this->http([]), true, new Allowlist([]), false, ActivityFetchPlanner::DEFAULT_ANONYMOUS_BUDGET, null, $this->advisories(new AdvisoryBatch(['vendor/direct' => [$advisory]])));

        $analysis = $analyzer->analyzeWithFacts($lock->packages(false), $lock, $project, false);

        self::assertSame(['vendor/direct', 'vendor/local'], array_map(static fn ($f) => $f->package(), $analysis->report()->findings()));
        self::assertEquals($analyzer->analyze($lock, $project, false)->toArray(), $analysis->report()->toArray(), 'the same run as analyze()');
        $direct = $analysis->facts('vendor/direct');
        self::assertNotNull($direct);
        self::assertSame($meta, $direct->metadata());
        self::assertSame([$advisory], $direct->advisories());
        self::assertSame('1.0.0', $direct->package()->version());
        $local = $analysis->facts('vendor/local');
        self::assertNotNull($local);
        self::assertNull($local->metadata(), 'a path package has no repository metadata');
        self::assertNull($analysis->facts('vendor/absent'));
    }

    /** The release scan reads why a package has no metadata. */
    public function testTheFactsSayWhetherTheMetadataFailedOrWasNotFound(): void
    {
        $lock = LockFile::fromArray(['packages' => [
            ['name' => 'vendor/failed', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/'],
            ['name' => 'vendor/absent', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/'],
        ]]);
        $analyzer = $this->analyzer($this->loader([], ['vendor/absent'], ['vendor/failed' => 'HTTP 500']), $this->http([]), true, new Allowlist([]));

        $analysis = $analyzer->analyzeWithFacts($lock->packages(false), $lock, ProjectConfig::empty(), false);

        $failed = $analysis->facts('vendor/failed');
        $absent = $analysis->facts('vendor/absent');
        self::assertNotNull($failed);
        self::assertNotNull($absent);
        self::assertSame(PackageFacts::METADATA_UNAVAILABLE, $failed->metadataStatus());
        self::assertSame(PackageFacts::METADATA_NOT_FOUND, $absent->metadataStatus());
    }

    public function testAFindingTakesItsOriginFromTheRepositoriesOfTheProject(): void
    {
        $lock = LockFile::fromArray(['packages' => [
            ['name' => 'acme/private', 'version' => '1.0.0', 'source' => ['type' => 'git', 'url' => 'https://github.com/acme/private.git', 'reference' => 'a1b2c3d']],
        ]]);
        $project = ProjectConfig::fromArray(['repositories' => [['type' => 'vcs', 'url' => 'https://github.com/acme/private.git']]]);

        $report = $this->analyzer($this->loader(), $this->http([]), true, new Allowlist([]))->analyze($lock, $project, false);

        self::assertSame(PackageOrigin::VCS, $report->findings()[0]->origin()->kind());
    }

    public function testAdvisoriesReachTheFindingAsS9AndRaiseAnAbandonedPackage(): void
    {
        $lock = LockFile::fromArray(['packages' => [
            ['name' => 'vendor/direct', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/'],
            ['name' => 'vendor/local', 'version' => '1.0.0', 'dist' => ['type' => 'path', 'url' => '../local']],
        ]]);
        $project = ProjectConfig::fromArray(['require' => ['vendor/direct' => '^1.0', 'vendor/local' => '*']]);
        $metadata = ['vendor/direct' => new PackageMetadata('vendor/direct', true, null, true, new \DateTimeImmutable('2024-01-10T10:00:00+00:00'), '1.0.0', 1, null, 'library', new \DateTimeImmutable(F::NOW))];
        /** @var \ArrayObject<string, string> $asked */
        $asked = new \ArrayObject();
        $advisory = new Advisory('PKSA-1', 'CVE-2024-0001', 'Title', null, 'high', null);
        $analyzer = $this->analyzer($this->loader($metadata), $this->http([]), true, new Allowlist([]), false, ActivityFetchPlanner::DEFAULT_ANONYMOUS_BUDGET, null, $this->advisories(new AdvisoryBatch(['vendor/direct' => [$advisory]]), $asked));

        $report = $analyzer->analyze($lock, $project, false);

        self::assertSame(['vendor/direct' => '1.0.0.0', 'vendor/local' => '1.0.0.0'], $asked->getArrayCopy(), 'every package the run checks, whatever its origin, by its normalised version');
        $finding = $report->findings()[0];
        self::assertSame('vendor/direct', $finding->package());
        self::assertSame(Verdict::ABANDONED, $finding->lead());
        self::assertSame('marked abandoned by its repository; 1 security advisory affects 1.0.0 (CVE-2024-0001)', Summaries::of($finding));
        self::assertSame(['1 package is not from a Composer repository: its release metadata and repository activity were not read'], $report->notes(), 'no advisory note on a complete answer');
        self::assertFalse($report->hadNetworkFailures());
    }

    public function testAdvisoryNotesAndNetworkFailuresReachTheReport(): void
    {
        $lock = LockFile::fromArray(['packages' => [['name' => 'vendor/direct', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/']]]);
        $batch = new AdvisoryBatch([], [RunNote::advisoriesUnavailable('packagist.org', new TransportException('HTTP 503'))]);
        $analyzer = $this->analyzer($this->loader(['vendor/direct' => $this->ancient()]), $this->http([]), true, new Allowlist([]), false, ActivityFetchPlanner::DEFAULT_ANONYMOUS_BUDGET, null, $this->advisories($batch));

        $report = $analyzer->analyze($lock, ProjectConfig::empty(), false);

        self::assertContains('security advisories unavailable from packagist.org: HTTP 503', $report->notes());
        self::assertTrue($report->hadNetworkFailures());
    }

    public function testWithoutAnAdvisorySourceNothingIsAskedAndNothingIsNoted(): void
    {
        $lock = LockFile::fromArray(['packages' => [['name' => 'vendor/direct', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/']]]);

        $report = $this->analyzer($this->loader(['vendor/direct' => $this->ancient()]), $this->http([]), true, new Allowlist([]))->analyze($lock, ProjectConfig::empty(), false);

        foreach ($report->notes() as $note) {
            self::assertStringNotContainsString('advisor', $note);
        }
        foreach ($report->findings()[0]->signals() as $signal) {
            self::assertNotSame('S9', $signal->id());
        }
    }

    public function testOfflineNoteAppearsOnlyWhenOfflineFlagIsSet(): void
    {
        $lock = LockFile::fromArray(['packages' => []]);
        $offlineReport = $this->analyzer($this->loader(), $this->http([]), true, new Allowlist([]), true)
            ->analyze($lock, ProjectConfig::empty(), false);
        $onlineReport = $this->analyzer($this->loader(), $this->http([]), true, new Allowlist([]), false)
            ->analyze($lock, ProjectConfig::empty(), false);

        self::assertContains("offline: repository metadata served from Composer's cache", $offlineReport->notes());
        self::assertNotContains("offline: repository metadata served from Composer's cache", $onlineReport->notes());
    }

    public function testMiniProjectVerdicts(): void
    {
        $dataDate = new \DateTimeImmutable('2026-09-14T06:00:00+00:00');
        $metadata = [
            'vendor/direct' => new PackageMetadata('vendor/direct', false, null, true, new \DateTimeImmutable('2024-01-10T10:00:00+00:00'), '1.2.3', 1, 'https://github.com/vendor/direct.git', 'library', $dataDate),
            'vendor/transitive' => new PackageMetadata('vendor/transitive', false, null, true, new \DateTimeImmutable('2015-11-16T16:30:51+00:00'), '2.0.0', 1, 'https://github.com/vendor/transitive.git', 'library', $dataDate),
            'vendor/snapshot' => new PackageMetadata('vendor/snapshot', false, null, false, null, null, 1, null, 'library', $dataDate),
        ];
        $http = $this->http([
            'https://api.github.com/repos/vendor/transitive' => [200, '{"archived":false,"pushed_at":"2015-11-16T16:31:37Z"}'],
        ]);
        $report = $this->analyzer($this->loader($metadata), $http, false, new Allowlist([]))->analyze(
            LockFile::fromFile(__DIR__.'/../../fixtures/mini/composer.lock'),
            ProjectConfig::fromFile(__DIR__.'/../../fixtures/mini/composer.json'),
            false
        );
        $byName = [];
        foreach ($report->findings() as $finding) {
            $byName[$finding->package()] = $finding;
        }
        // vendor/direct is released 2024-01-10 with "php": ">=7.4", after PHP 8.0's GA (2020-11-26), the
        // line S5 reads for a target of 8.4. A release of the PHP 8 era is no old promise, so S5 stays
        // quiet and the verdict is OK. vendor/transitive (2015, ">=5.3.0") is the old promise here, but
        // `silent` outranks it. LockFileTest, DependencyGraphTest and ProjectConfigTest read the same
        // fixture: change this assertion, not the fixture.
        self::assertNull($byName['vendor/direct']->lead());
        self::assertSame(Verdict::SILENT, $byName['vendor/transitive']->lead());
        self::assertSame(['vendor/direct', 'vendor/transitive'], $byName['vendor/transitive']->chain());
        self::assertNotNull($byName['vendor/transitive']->dataDate());
        self::assertSame('2026-09-14T06:00:00+00:00', $byName['vendor/transitive']->dataDate()->format(\DATE_ATOM));
        self::assertSame(Verdict::PINNED, $byName['vendor/snapshot']->lead());
        self::assertNull($byName['private/thing']->lead());
        self::assertSame('not from a Composer repository, not checked', $byName['private/thing']->note());
        self::assertStringStartsWith('release metadata not checked (lockrot cannot tell which repository serves it)', Summaries::of($byName['private/thing']), 'an unknown origin raises S10');
        // Only private/thing carries no notification-url.
        self::assertFalse($byName['private/thing']->isFromComposerRepository());
        foreach (['vendor/direct', 'vendor/transitive', 'vendor/snapshot'] as $asked) {
            self::assertTrue($byName[$asked]->isFromComposerRepository(), $asked);
        }
        self::assertSame(4, $report->packagesChecked());
        self::assertSame(1, $report->notFromComposerRepository());
        self::assertFalse($report->hadNetworkFailures());
        self::assertSame([], $byName['vendor/snapshot']->chain());
        self::assertSame(['vendor/transitive', 'vendor/snapshot', 'private/thing', 'vendor/direct'], array_map(static fn ($f) => $f->package(), $report->sorted()));
        self::assertNotEmpty(array_filter($report->notes(), static fn (string $n): bool => strpos($n, 'GitHub token not set') !== false));
        // vendor/direct is the one root and pulls in the silent package, so it carries S7, with no
        // change of verdict or priority.
        self::assertSame(['vendor/direct'], $byName['vendor/transitive']->directDependents());
        self::assertSame(['vendor/direct'], $byName['vendor/direct']->directDependents());
        self::assertSame([], $byName['vendor/snapshot']->directDependents());
        // S10 too: the run has no token, so this package's repository was never asked about, and
        // the finding says so rather than reading as "checked, nothing found".
        self::assertSame(['S7', 'S10'], array_map(static fn ($s) => $s->id(), $byName['vendor/direct']->signals()));
        $notChecked = $byName['vendor/direct']->signals()[1];
        self::assertSame(['S3', 'S4'], JsonPath::decoded($notChecked->data())['blocks']);
        self::assertStringContainsString('repository activity not checked', $notChecked->summary());
        self::assertSame('pulls in 1 flagged package: vendor/transitive (silent · old-promise); repository activity not checked (no token for the repository host), so S3 and S4 could not be read', Summaries::of($byName['vendor/direct']), 'nothing observed about the package itself: what it pulls in, and what was not checked');
        self::assertSame(['vendor/direct' => 1], $report->exposure());
    }

    public function testADevPackageCarriesTheDevFlagAndGetsALowerPriorityThanTheSameProdPackage(): void
    {
        $dataDate = new \DateTimeImmutable('2026-09-14T06:00:00+00:00');
        $silent = static fn (string $name): PackageMetadata => new PackageMetadata($name, false, null, true, new \DateTimeImmutable('2015-11-16T16:30:51+00:00'), '2.0.0', 1, 'https://github.com/'.$name.'.git', 'library', $dataDate);
        $metadata = ['vendor/prod' => $silent('vendor/prod'), 'vendor/devtool' => $silent('vendor/devtool')];
        $http = $this->http([
            'https://api.github.com/repos/vendor/prod' => [200, '{"archived":false,"pushed_at":"2015-11-16T16:31:37Z"}'],
            'https://api.github.com/repos/vendor/devtool' => [200, '{"archived":false,"pushed_at":"2015-11-16T16:31:37Z"}'],
        ]);
        $lock = LockFile::fromArray([
            'packages' => [['name' => 'vendor/prod', 'version' => '2.0.0', 'notification-url' => 'https://packagist.org/downloads/']],
            'packages-dev' => [['name' => 'vendor/devtool', 'version' => '2.0.0', 'notification-url' => 'https://packagist.org/downloads/']],
        ]);
        $project = ProjectConfig::fromArray(['require' => ['vendor/prod' => '^2.0'], 'require-dev' => ['vendor/devtool' => '^2.0']]);

        $report = $this->analyzer($this->loader($metadata), $http, false, new Allowlist([]))->analyze($lock, $project, true);
        $byName = [];
        foreach ($report->findings() as $finding) {
            $byName[$finding->package()] = $finding;
        }

        self::assertSame(Verdict::SILENT, $byName['vendor/prod']->lead());
        self::assertSame(Verdict::SILENT, $byName['vendor/devtool']->lead());
        self::assertFalse($byName['vendor/prod']->isDev());
        self::assertTrue($byName['vendor/devtool']->isDev());
        // The score orders the report: the prod row comes first, because packages-dev halves the other.
        self::assertSame(['vendor/prod', 'vendor/devtool'], array_map(static fn ($f) => $f->package(), $report->sorted()));
        // A dev root is a parent like any other once --dev brings it in.
        self::assertSame(['vendor/devtool'], $byName['vendor/devtool']->directDependents());
        self::assertSame(['vendor/prod'], $byName['vendor/prod']->directDependents());
    }

    public function testAllowlistedIsFinishedAndNotFetchedFromGitHub(): void
    {
        $metadata = [
            'vendor/transitive' => new PackageMetadata('vendor/transitive', false, null, true, new \DateTimeImmutable('2015-11-16T16:30:51+00:00'), '2.0.0', 1, 'https://github.com/vendor/transitive.git', 'library', new \DateTimeImmutable('2026-09-14T06:00:00+00:00')),
        ];
        $lock = LockFile::fromArray(['packages' => [['name' => 'vendor/transitive', 'version' => '2.0.0', 'notification-url' => 'https://packagist.org/downloads/']]]);
        $report = $this->analyzer($this->loader($metadata), $this->http([]), false, new Allowlist([new AllowlistEntry('vendor/*', null, 'complete', null, 'builtin')]))->analyze($lock, ProjectConfig::empty(), false);
        self::assertNull($report->findings()[0]->lead());
        self::assertSame('complete', $report->findings()[0]->allowlistReason());
    }

    /**
     * S10 only where the missing check could have changed the verdict: each of the three packages
     * has undated newest releases, and only the one neither allowlisted nor marked abandoned keeps it.
     */
    public function testS10IsLeftOffAnAllowlistedPackageAndAnAbandonedOne(): void
    {
        $undated = static fn (string $name, bool $abandoned): PackageMetadata => new PackageMetadata($name, $abandoned, null, true, null, '1.0.0', 1, null, 'library', new \DateTimeImmutable(F::NOW));
        $metadata = ['vendor/allowlisted' => $undated('vendor/allowlisted', false), 'vendor/abandoned' => $undated('vendor/abandoned', true), 'vendor/plain' => $undated('vendor/plain', false)];
        $allowlist = new Allowlist([new AllowlistEntry('vendor/allowlisted', null, 'complete', null, 'builtin')]);

        $byName = self::byName($this->analyzeLock([self::locked('vendor/allowlisted'), self::locked('vendor/abandoned'), self::locked('vendor/plain')], $this->loader($metadata), $this->http([]), true, $allowlist));
        $ids = static fn (string $name): array => array_map(static fn ($s) => $s->id(), $byName[$name]->signals());

        self::assertSame(['S10'], $ids('vendor/plain'), 'the control: undated releases raise S10');
        self::assertNull($byName['vendor/allowlisted']->lead());
        self::assertSame([], $ids('vendor/allowlisted'));
        self::assertSame(Verdict::ABANDONED, $byName['vendor/abandoned']->lead());
        self::assertSame(['S1'], $ids('vendor/abandoned'));
    }

    public function testRepositoryMetadataFailureIsUnknownWithNoteAndNetworkFlag(): void
    {
        $lock = LockFile::fromArray(['packages' => [['name' => 'vendor/direct', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/']]]);
        $report = $this->analyzer($this->loader([], [], ['vendor/direct' => 'HTTP 503']), $this->http([]), true, new Allowlist([]))->analyze($lock, ProjectConfig::empty(), false);
        self::assertNull($report->findings()[0]->lead());
        self::assertTrue($report->hadNetworkFailures());
        self::assertContains('Repository metadata unavailable for 1 package: HTTP 503', $report->notes());
        self::assertSame('Repository metadata unavailable: HTTP 503', Summaries::of($report->findings()[0]));
        self::assertTrue($report->findings()[0]->isFromComposerRepository(), 'a note, and still a package a repository was asked about');
        self::assertNull($report->findings()[0]->libyears());
        self::assertSame(Libyears::UNAVAILABLE, $report->findings()[0]->libyearsUnmeasured());
        self::assertSame(1, $report->libyears()->unmeasured()[Libyears::UNAVAILABLE]);
    }

    public function testTwoFailedPackagesAreCountedInThePlural(): void
    {
        $lock = LockFile::fromArray(['packages' => [
            ['name' => 'vendor/a', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/'],
            ['name' => 'vendor/b', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/'],
        ]]);
        $failed = ['vendor/a' => 'HTTP 503', 'vendor/b' => 'HTTP 503'];

        $report = $this->analyzer($this->loader([], [], $failed), $this->http([]), true, new Allowlist([]))->analyze($lock, ProjectConfig::empty(), false);

        self::assertContains('Repository metadata unavailable for 2 packages: HTTP 503', $report->notes());
    }

    public function testMultipleFailureReasonsAreGroupedWithCountsInsteadOfOnlyNamingTheFirst(): void
    {
        $lock = LockFile::fromArray(['packages' => [
            ['name' => 'vendor/a', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/'],
            ['name' => 'vendor/b', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/'],
            ['name' => 'vendor/c', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/'],
            ['name' => 'vendor/d', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/'],
        ]]);
        $failed = [
            'vendor/a' => MetadataLoaderInterface::BUDGET_REASON,
            'vendor/b' => MetadataLoaderInterface::BUDGET_REASON,
            'vendor/c' => MetadataLoaderInterface::BUDGET_REASON,
            'vendor/d' => 'connection refused',
        ];

        $report = $this->analyzer($this->loader([], [], $failed), $this->http([]), true, new Allowlist([]))->analyze($lock, ProjectConfig::empty(), false);

        self::assertContains(
            'Repository metadata unavailable for 4 packages: '.MetadataLoaderInterface::BUDGET_REASON.' (3); connection refused (1)',
            $report->notes()
        );
    }

    public function testTheOfflineReasonIsReportedWithoutASecondPrefix(): void
    {
        // OFFLINE_NOT_FOUND_REASON already reads as a complete statement, and a prefix gives
        // "Repository metadata unavailable: offline: not present in Composer's cache".
        $lock = LockFile::fromArray(['packages' => [['name' => 'vendor/direct', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/']]]);
        $failed = ['vendor/direct' => MetadataLoaderInterface::OFFLINE_NOT_FOUND_REASON];

        $report = $this->analyzer($this->loader([], [], $failed), $this->http([]), true, new Allowlist([]), true)->analyze($lock, ProjectConfig::empty(), false);

        self::assertSame(MetadataLoaderInterface::OFFLINE_NOT_FOUND_REASON, Summaries::of($report->findings()[0]));
    }

    /**
     * @param array{0: int, 1: string, 2?: list<string>}|null $github status, body and headers for api.github.com/repos/vendor/pkg
     */
    private function singlePackageReport(PackageMetadata $meta, bool $token, ?array $github = null): Report
    {
        $map = [];
        if ($github !== null) {
            $map['https://api.github.com/repos/vendor/pkg'] = $github;
        }
        $lock = LockFile::fromArray(['packages' => [['name' => 'vendor/pkg', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/']]]);

        return $this->analyzer($this->loader(['vendor/pkg' => $meta]), $this->http($map), $token, new Allowlist([]))->analyze($lock, ProjectConfig::empty(), false);
    }

    /** The lock's `time` and the newest stable release are two years apart: a swapped or dropped argument of {@see \Lockrot\Analyzer\Libyears::measure()} reads as zero or null. */
    public function testAFindingCarriesTheLibyearsBetweenItsLockTimeAndTheNewestStableRelease(): void
    {
        $lock = LockFile::fromArray(['packages' => [
            ['name' => 'vendor/pkg', 'version' => '1.0.0', 'time' => '2024-01-10T10:00:00+00:00', 'notification-url' => 'https://packagist.org/downloads/'],
            ['name' => 'vendor/pin', 'version' => 'dev-main', 'time' => '2024-01-10T10:00:00+00:00', 'notification-url' => 'https://packagist.org/downloads/'],
        ]]);
        $metadata = [
            'vendor/pkg' => $this->metadataFor('2026-01-10T10:00:00+00:00'),
            'vendor/pin' => new PackageMetadata('vendor/pin', false, null, true, new \DateTimeImmutable('2026-01-10T10:00:00+00:00'), '1.0.0', 1, null, 'library', new \DateTimeImmutable(F::NOW)),
        ];
        $report = $this->analyzer($this->loader($metadata), $this->http([]), true, new Allowlist([]))->analyze($lock, ProjectConfig::empty(), false);
        $byName = [];
        foreach ($report->findings() as $finding) {
            $byName[$finding->package()] = $finding;
        }

        self::assertEqualsWithDelta(2.0, (float) $byName['vendor/pkg']->libyears(), 0.01);
        self::assertNull($byName['vendor/pin']->libyears(), 'a branch snapshot is not measured');
        self::assertNull($byName['vendor/pkg']->libyearsUnmeasured());
        self::assertSame(Libyears::BRANCH_SNAPSHOT, $byName['vendor/pin']->libyearsUnmeasured());
        self::assertEqualsWithDelta(2.0, $report->libyears()->total(), 0.01);
        self::assertSame(1, $report->libyears()->unmeasured()['branch_snapshot']);
    }

    private function metadataFor(?string $releasedAt): PackageMetadata
    {
        $releaseAt = $releasedAt === null ? null : new \DateTimeImmutable($releasedAt);

        return new PackageMetadata('vendor/pkg', false, null, true, $releaseAt, '1.0.0', 1, 'https://github.com/vendor/pkg.git', 'library', new \DateTimeImmutable(F::NOW));
    }

    /** @return PackageMetadata a release recent enough that S2 never fires (not a candidate) */
    private function recent(): PackageMetadata
    {
        return $this->metadataFor('2026-08-01T00:00:00+00:00');
    }

    /** @return PackageMetadata a release old enough that S2 fires (a candidate for the activity check) */
    private function ancient(): PackageMetadata
    {
        return $this->metadataFor('2015-01-01T00:00:00+00:00');
    }

    public function testWithoutTokenAndNoCandidatesTheSkippedPackagesAreStillReported(): void
    {
        // The package has a GitHub repo but is not a candidate (recent release), so nothing is
        // fetched at all — the note must still say the check did not run.
        $report = $this->singlePackageReport($this->recent(), false);
        self::assertStringContainsString(
            'GitHub token not set: repository activity checked for 0 candidate packages, 1 package skipped (set LOCKROT_GITHUB_TOKEN or GITHUB_TOKEN to a github.com token to check all)',
            implode("\n", $report->notes())
        );
    }

    public function testWithTokenNoTokenNoteIsEmitted(): void
    {
        self::assertSame([], array_values(array_filter(
            $this->singlePackageReport($this->recent(), true)->notes(),
            static fn (string $n): bool => strpos($n, 'GitHub token not set') !== false
        )));
    }

    public function testRateLimitedGitHubIsReportedAsANote(): void
    {
        $report = $this->singlePackageReport($this->ancient(), true, [403, '{"message":"API rate limit exceeded"}', ['x-ratelimit-remaining: 0']]);
        self::assertStringContainsString('GitHub API rate limit reached; repository activity missing for 1 repositories', implode("\n", $report->notes()));
        self::assertTrue($report->hadNetworkFailures());
    }

    public function testUnreachableGitHubIsReportedSeparatelyFromRateLimiting(): void
    {
        $report = $this->singlePackageReport($this->ancient(), true, [500, '']);
        $notes = implode("\n", $report->notes());
        self::assertStringContainsString('GitHub unreachable for 1 repositories: HTTP 500', $notes);
        self::assertStringNotContainsString('rate limit', $notes);
        self::assertTrue($report->hadNetworkFailures());
    }


    public function testAllowlistAccessorReturnsConstructorValue(): void
    {
        $allowlist = new Allowlist([new AllowlistEntry('vendor/*', null, 'complete', null, 'builtin')]);
        $analyzer = $this->analyzer($this->loader(), $this->http([]), true, $allowlist);
        self::assertSame($allowlist, $analyzer->allowlist());
    }

    /**
     * Install time checks only the packages in the transaction, but the chain still resolves
     * through the whole lock — the mini fixture's vendor/transitive is pulled in by
     * vendor/direct, which is not part of the subset handed to analyzePackages().
     */
    public function testAnalyzePackagesChecksOnlyTheSubsetButResolvesChainsThroughTheFullLock(): void
    {
        $lock = LockFile::fromFile(__DIR__.'/../../fixtures/mini/composer.lock');
        $project = ProjectConfig::fromFile(__DIR__.'/../../fixtures/mini/composer.json');
        $transitive = $lock->find('vendor/transitive');
        self::assertNotNull($transitive);
        $metadata = [
            'vendor/transitive' => new PackageMetadata('vendor/transitive', false, null, true, new \DateTimeImmutable('2015-11-16T16:30:51+00:00'), '2.0.0', 1, 'https://github.com/vendor/transitive.git', 'library', new \DateTimeImmutable(F::NOW)),
        ];
        $http = $this->http([
            'https://api.github.com/repos/vendor/transitive' => [200, '{"archived":false,"pushed_at":"2015-11-16T16:31:37Z"}'],
        ]);

        $report = $this->analyzer($this->loader($metadata), $http, true, new Allowlist([]))
            ->analyzePackages([$transitive], $lock, $project, false);

        self::assertSame(1, $report->packagesChecked());
        self::assertCount(1, $report->findings());
        self::assertSame('vendor/transitive', $report->findings()[0]->package());
        self::assertSame(['vendor/direct', 'vendor/transitive'], $report->findings()[0]->chain());
        // The parents are resolved through the full lock too, and the exposure line names the
        // root even though it is not in the checked subset — the S7 signal, which needs a finding
        // to sit on, is not invented for it.
        self::assertSame(['vendor/direct'], $report->findings()[0]->directDependents());
        self::assertSame(['vendor/direct' => 1], $report->exposure());
    }

    public function testAnExhaustedDeadlineSkipsTheGitHubCallAndSaysSo(): void
    {
        $lock = LockFile::fromArray(['packages' => [['name' => 'vendor/pkg', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/']]]);
        $http = new class () implements HttpClientInterface {
            public int $calls = 0;

            public function fetchAll(array $urls, array $headers = []): array
            {
                ++$this->calls;

                return [];
            }
        };
        $analyzer = $this->analyzer($this->loader(['vendor/pkg' => $this->ancient()]), $http, true, new Allowlist([]))
            ->withDeadline(Deadline::inSeconds(0.0, static fn (): float => 0.0));

        $report = $analyzer->analyze($lock, ProjectConfig::empty(), false);

        self::assertSame(0, $http->calls);
        self::assertContains('repository activity not checked: install-time budget exhausted', $report->notes());
    }

    public function testWithDeadlineReturnsAClonedInstance(): void
    {
        $analyzer = $this->analyzer($this->loader(), $this->http([]), true, new Allowlist([]));

        self::assertNotSame($analyzer, $analyzer->withDeadline(Deadline::inSeconds(5.0)));
    }

    public function testTheBudgetReasonIsReportedWithoutASecondPrefix(): void
    {
        $lock = LockFile::fromArray(['packages' => [['name' => 'vendor/direct', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/']]]);
        $failed = ['vendor/direct' => MetadataLoaderInterface::BUDGET_REASON];

        $report = $this->analyzer($this->loader([], [], $failed), $this->http([]), true, new Allowlist([]))->analyze($lock, ProjectConfig::empty(), false);

        self::assertSame(MetadataLoaderInterface::BUDGET_REASON, Summaries::of($report->findings()[0]));
    }

    public function testWithAllowlistReturnsClonedInstanceLeavingOriginalUnchanged(): void
    {
        $original = new Allowlist([]);
        $replacement = new Allowlist([new AllowlistEntry('vendor/*', null, 'complete', null, 'builtin')]);
        $analyzer = $this->analyzer($this->loader(), $this->http([]), true, $original);

        $withReplacement = $analyzer->withAllowlist($replacement);

        self::assertNotSame($analyzer, $withReplacement);
        self::assertSame($replacement, $withReplacement->allowlist());
        self::assertSame($original, $analyzer->allowlist());
    }

    public function testThePhpWindowReadsTheTargetAtTheSolvesPhpOfTheMoveCheck(): void
    {
        // Composer's merged config, COMPOSER_HOME/config.json included, sets the solve's php: composer.json sets none here.
        $lock = LockFile::fromArray(['packages' => [self::locked('vendor/pkg') + ['require' => ['php' => '>=8.2.15']]], 'packages-dev' => []]);
        $analyzer = $this->analyzer($this->loader(['vendor/pkg' => $this->ancient()]), $this->http([]), true, new Allowlist([]), false, ActivityFetchPlanner::DEFAULT_ANONYMOUS_BUDGET, null, null, '8.2')
            ->withMoveChecks(MoveChecks::of(new RecordedMoveChecker([], ['php' => '8.2.10'])));

        $window = $analyzer->analyze($lock, ProjectConfig::empty(), false)->phpWindow();

        self::assertNotNull($window);
        self::assertSame(['php' => '8.2.10', 'inside' => false], $window->toArray()['target']);
    }

    public function testWithMoveChecksReturnsAClonedInstanceLeavingOriginalWithoutChecks(): void
    {
        $lock = LockFile::fromArray(['packages' => [self::locked('vendor/pkg')]]);
        $analyzer = $this->analyzer($this->loader(['vendor/pkg' => $this->ancient()]), $this->http([]), true, new Allowlist([]));

        $checked = $analyzer->withMoveChecks(MoveChecks::of(new RecordedMoveChecker([], [])));

        self::assertNull($analyzer->analyze($lock, ProjectConfig::empty(), false)->moveCheck());
        self::assertNotNull($checked->analyze($lock, ProjectConfig::empty(), false)->moveCheck());
    }

    /** @return array<string, mixed> a lock entry that a Composer repository serves, with the lock's own source URL and support.source when given */
    private static function locked(string $name, ?string $sourceUrl = null, ?string $supportSource = null): array
    {
        $entry = ['name' => $name, 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/'];
        if ($sourceUrl !== null) {
            $entry['source'] = ['type' => 'git', 'url' => $sourceUrl, 'reference' => 'a'];
        }
        if ($supportSource !== null) {
            $entry['support'] = ['source' => $supportSource];
        }

        return $entry;
    }

    /** @return array<string, mixed> a lock entry from a VCS repository, so not checked */
    private static function unlocked(string $name, string $sourceUrl): array
    {
        return ['name' => $name, 'version' => '1.0.0', 'source' => ['type' => 'git', 'url' => $sourceUrl, 'reference' => 'a']];
    }

    private function metadataNamed(string $name, ?string $sourceUrl, string $releasedAt = '2015-01-01T00:00:00+00:00', string $dataDate = F::NOW): PackageMetadata
    {
        return new PackageMetadata($name, false, null, true, new \DateTimeImmutable($releasedAt), '1.0.0', 1, $sourceUrl, 'library', new \DateTimeImmutable($dataDate));
    }

    /** @return array<string, \Lockrot\Verdict\Finding> */
    private static function byName(Report $report): array
    {
        $byName = [];
        foreach ($report->findings() as $finding) {
            $byName[$finding->package()] = $finding;
        }

        return $byName;
    }

    /** @param list<array<string, mixed>> $packages */
    private function analyzeLock(array $packages, MetadataLoaderInterface $loader, HttpClientInterface $http, bool $token, ?Allowlist $allowlist = null, int $noTokenBudget = ActivityFetchPlanner::DEFAULT_ANONYMOUS_BUDGET, ?ForgeAuth $auth = null): Report
    {
        return $this->analyzer($loader, $http, $token, $allowlist ?? new Allowlist([]), false, $noTokenBudget, $auth)
            ->analyze(LockFile::fromArray(['packages' => $packages]), ProjectConfig::empty(), false);
    }

    public function testThePhpWindowIsReadAtTheTargetPhpAndNotAtTheRuntimePhp(): void
    {
        // No PHP that the CI matrix runs on is 7.1.
        $report = $this->analyzer($this->loader(), $this->http([]), true, new Allowlist([]), false, ActivityFetchPlanner::DEFAULT_ANONYMOUS_BUDGET, null, null, '7.1')
            ->analyze(LockFile::fromArray(['packages' => []]), ProjectConfig::empty(), false);

        $window = $report->phpWindow();
        self::assertNotNull($window);
        self::assertSame('7.1', $window->targetMinor());
        self::assertSame('7.1.99', $window->toArray()['target']['php']);
    }

    public function testPackagesNotFromAComposerRepositoryAreCountedInOneNote(): void
    {
        $one = self::unlocked('private/one', 'https://git.example.com/private/one.git');
        $two = self::unlocked('private/two', 'https://git.example.com/private/two.git');

        self::assertSame([], $this->analyzeLock([], $this->loader(), $this->http([]), true)->notes());
        self::assertSame(
            ['1 package is not from a Composer repository: its release metadata and repository activity were not read'],
            $this->analyzeLock([$one], $this->loader(), $this->http([]), true)->notes()
        );
        $report = $this->analyzeLock([$one, $two], $this->loader(), $this->http([]), true);
        self::assertSame(['2 packages are not from a Composer repository: their release metadata and repository activity were not read'], $report->notes());
        self::assertSame(2, $report->notFromComposerRepository());
    }

    public function testAPackageTheRepositoryDoesNotKnowIsUnknownWithANoteAndAKnownQuietOneHasNoEvidence(): void
    {
        $metadata = ['vendor/fine' => $this->metadataNamed('vendor/fine', null, '2026-08-01T00:00:00+00:00')];
        $report = $this->analyzeLock([self::locked('vendor/gone'), self::locked('vendor/fine')], $this->loader($metadata, ['vendor/gone']), $this->http([]), true);
        $byName = self::byName($report);

        self::assertNull($byName['vendor/gone']->lead());
        self::assertSame('not found in the repository', Summaries::of($byName['vendor/gone']));
        self::assertNull($byName['vendor/fine']->lead());
        self::assertSame('', Summaries::of($byName['vendor/fine']));
        self::assertFalse($report->hadNetworkFailures());
        self::assertSame([], $report->notes());
    }

    public function testAnAllowlistedOrNonRepositoryPackageIsNeverLookedUpOnGitHub(): void
    {
        $requested = new \ArrayObject();
        $packages = [
            self::locked('vendor/allowlisted', 'https://github.com/vendor/allowlisted.git'),
            self::unlocked('private/mirror', 'https://github.com/private/mirror.git'),
        ];
        $metadata = ['vendor/allowlisted' => $this->metadataNamed('vendor/allowlisted', 'https://github.com/vendor/allowlisted.git')];
        $allowlist = new Allowlist([new AllowlistEntry('vendor/allowlisted', null, 'complete', null, 'builtin')]);

        $byName = self::byName($this->analyzeLock($packages, $this->loader($metadata), $this->http([], $requested), true, $allowlist));

        self::assertSame([], $requested->getArrayCopy());
        self::assertNull($byName['vendor/allowlisted']->lead());
        self::assertNull($byName['private/mirror']->lead());
    }

    public function testTheRepositorysSourceUrlOutranksTheLocksAndTheLocksIsTheFallback(): void
    {
        $packages = [self::locked('vendor/pkg', 'https://github.com/old-owner/pkg.git')];
        $answer = [200, '{"archived":false,"pushed_at":"2026-08-01T00:00:00Z"}'];

        $requested = new \ArrayObject();
        $loader = $this->loader(['vendor/pkg' => $this->metadataNamed('vendor/pkg', 'https://github.com/new-owner/pkg.git')]);
        $report = $this->analyzeLock($packages, $loader, $this->http(['https://api.github.com/repos/new-owner/pkg' => $answer], $requested), true);
        self::assertSame(['https://api.github.com/repos/new-owner/pkg'], $requested->getArrayCopy());
        self::assertFalse($report->hadNetworkFailures());

        $requested = new \ArrayObject();
        $loader = $this->loader(['vendor/pkg' => $this->metadataNamed('vendor/pkg', null)]);
        $report = $this->analyzeLock($packages, $loader, $this->http(['https://api.github.com/repos/old-owner/pkg' => $answer], $requested), true);
        self::assertSame(['https://api.github.com/repos/old-owner/pkg'], $requested->getArrayCopy());
        self::assertFalse($report->hadNetworkFailures());
    }

    public function testTheLocksSupportSourceIsTheLastResortAndNoRepositoryMeansNoRequest(): void
    {
        $answer = [200, '{"archived":true,"pushed_at":"2015-01-01T00:00:00Z"}'];

        $requested = new \ArrayObject();
        $packages = [self::locked('vendor/pkg', null, 'https://github.com/vendor/pkg-src')];
        $loader = $this->loader(['vendor/pkg' => $this->metadataNamed('vendor/pkg', null)]);
        $report = $this->analyzeLock($packages, $loader, $this->http(['https://api.github.com/repos/vendor/pkg-src' => $answer], $requested), true);
        self::assertSame(['https://api.github.com/repos/vendor/pkg-src'], $requested->getArrayCopy());
        self::assertSame(Verdict::ABANDONED, self::byName($report)['vendor/pkg']->lead());

        $requested = new \ArrayObject();
        $packages = [self::locked('vendor/pkg')];
        $report = $this->analyzeLock($packages, $loader, $this->http([], $requested), true);
        self::assertSame([], $requested->getArrayCopy());
        self::assertFalse($report->hadNetworkFailures());
    }

    public function testWithoutATokenTheCandidatesBeyondTheBudgetCountAsSkippedToo(): void
    {
        $packages = [];
        $metadata = [];
        $answers = [];
        foreach (['vendor/one', 'vendor/two', 'vendor/three'] as $name) {
            $packages[] = self::locked($name);
            $metadata[$name] = $this->metadataNamed($name, 'https://github.com/'.$name.'.git');
            $answers['https://api.github.com/repos/'.$name] = [200, '{"archived":false,"pushed_at":"2015-01-01T00:00:00Z"}'];
        }

        // Three candidates (released 2015, so S2 fires for each), a budget of two: one is skipped for
        // the budget, none for the token, and the note counts both kinds together.
        $report = $this->analyzeLock($packages, $this->loader($metadata), $this->http($answers), false, null, 2);

        self::assertContains(
            'GitHub token not set: repository activity checked for 2 candidate packages, 1 package skipped (set LOCKROT_GITHUB_TOKEN or GITHUB_TOKEN to a github.com token to check all)',
            $report->notes()
        );
    }

    private const GL_COMMITS = 'https://gitlab.com/api/v4/projects/group%2Fpkg/repository/commits?all=true&per_page=1';
    private const GL_PROJECT = 'https://gitlab.com/api/v4/projects/group%2Fpkg';
    private const BB_COMMITS = 'https://api.bitbucket.org/2.0/repositories/workspace/pkg/commits?pagelen=1';

    /** GitLab's anonymous limit needs no cap: every GitLab package is checked, candidate or not, and no note says otherwise. */
    public function testGitlabPackagesAreCheckedAnonymouslyWithoutACapOrANote(): void
    {
        $requested = new \ArrayObject();
        $packages = [self::locked('vendor/recent'), self::locked('vendor/old')];
        $metadata = [
            'vendor/recent' => $this->metadataNamed('vendor/recent', 'https://gitlab.com/group/recent.git', '2026-08-01T00:00:00+00:00'),
            'vendor/old' => $this->metadataNamed('vendor/old', 'https://gitlab.com/group/old.git'),
        ];
        $answers = [
            'https://gitlab.com/api/v4/projects/group%2Frecent/repository/commits?all=true&per_page=1' => [200, '[{"committed_date":"2026-08-01T00:00:00Z"}]'],
            'https://gitlab.com/api/v4/projects/group%2Fold/repository/commits?all=true&per_page=1' => [200, '[{"committed_date":"2015-01-01T00:00:00Z"}]'],
        ];

        $report = $this->analyzeLock($packages, $this->loader($metadata), $this->http($answers, $requested), false, null, 1);

        self::assertSame(array_reverse(array_keys($answers)), $requested->getArrayCopy(), 'both fetched (in package-name order), with a budget of one and no token');
        self::assertSame([], $report->notes());
        $byName = self::byName($report);
        self::assertSame(Verdict::SILENT, $byName['vendor/old']->lead());
        self::assertNull($byName['vendor/recent']->lead());
        self::assertStringContainsString('last commit 2015-01-01', Summaries::of($byName['vendor/old']));
    }

    /** Anonymously a GitLab repository can be silent but never archived: the flag needs credentials. */
    public function testAnAuthenticatedGitlabRunReadsTheArchivedFlag(): void
    {
        $packages = [self::locked('vendor/pkg')];
        $loader = $this->loader(['vendor/pkg' => $this->metadataNamed('vendor/pkg', 'https://gitlab.com/group/pkg.git')]);
        $answers = [
            self::GL_COMMITS => [200, '[{"committed_date":"2015-01-01T00:00:00Z"}]'],
            self::GL_PROJECT => [200, '{"archived":true,"last_activity_at":"2026-09-01T00:00:00Z"}'],
        ];

        $requested = new \ArrayObject();
        $anonymous = self::byName($this->analyzeLock($packages, $loader, $this->http($answers, $requested), false))['vendor/pkg'];
        self::assertSame([self::GL_COMMITS], $requested->getArrayCopy());
        self::assertSame(Verdict::SILENT, $anonymous->lead());

        $requested = new \ArrayObject();
        $auth = ForgeAuth::withTokens(new Tokens(null, 'glpat-x'));
        $withToken = self::byName($this->analyzeLock($packages, $loader, $this->http($answers, $requested), false, null, ActivityFetchPlanner::DEFAULT_ANONYMOUS_BUDGET, $auth))['vendor/pkg'];
        self::assertSame([self::GL_COMMITS, self::GL_PROJECT], $requested->getArrayCopy());
        self::assertSame(Verdict::ABANDONED, $withToken->lead());
        self::assertStringContainsString('repository archived on GitLab', Summaries::of($withToken));
    }

    /** Each repository host reports its own cap and its own failures, GitHub first, then GitLab, then Bitbucket. */
    public function testEveryForgeGetsItsOwnNotes(): void
    {
        $packages = [self::locked('vendor/gh'), self::locked('vendor/gl'), self::locked('vendor/bb')];
        $metadata = [
            'vendor/gh' => $this->metadataNamed('vendor/gh', 'https://github.com/owner/pkg.git'),
            'vendor/gl' => $this->metadataNamed('vendor/gl', 'https://gitlab.com/group/pkg.git'),
            'vendor/bb' => $this->metadataNamed('vendor/bb', 'https://bitbucket.org/workspace/pkg.git'),
        ];
        $answers = [
            'https://api.github.com/repos/owner/pkg' => [500, ''],
            self::GL_COMMITS => [429, ''],
            self::BB_COMMITS => [403, ''],
        ];

        $report = $this->analyzeLock($packages, $this->loader($metadata), $this->http($answers), false);

        self::assertSame([
            'GitHub token not set: repository activity checked for 1 candidate package, 0 packages skipped (set LOCKROT_GITHUB_TOKEN or GITHUB_TOKEN to a github.com token to check all)',
            'Bitbucket credentials not set: repository activity checked for 1 candidate package, 0 packages skipped (add bitbucket.org credentials to auth.json to check all)',
            'GitHub unreachable for 1 repositories: HTTP 500',
            'GitLab API rate limit reached; repository activity missing for 1 repositories',
            'Bitbucket unreachable for 1 repositories: HTTP 403',
        ], $report->notes());
        self::assertTrue($report->hadNetworkFailures());
    }

    /** A private repository answers 404 anonymously. Without the note it looks like a healthy one. */
    public function testARepositoryTheHostDoesNotAnswerForIsCountedInANote(): void
    {
        $packages = [self::locked('vendor/one'), self::locked('vendor/two'), self::locked('vendor/gl')];
        $metadata = [
            'vendor/one' => $this->metadataNamed('vendor/one', 'https://bitbucket.org/workspace/one.git'),
            'vendor/two' => $this->metadataNamed('vendor/two', 'https://bitbucket.org/workspace/two.git'),
            'vendor/gl' => $this->metadataNamed('vendor/gl', 'https://gitlab.com/group/pkg.git'),
        ];

        $report = $this->analyzeLock($packages, $this->loader($metadata), $this->http([]), true);

        self::assertContains('Bitbucket did not answer for 2 repositories (private, renamed or removed); repository activity missing', $report->notes());
        self::assertContains('GitLab did not answer for 1 repositories (private, renamed or removed); repository activity missing', $report->notes());
        self::assertFalse($report->hadNetworkFailures(), 'a 404 is an answer, not a failure');
        self::assertSame(Verdict::STALE, self::byName($report)['vendor/one']->lead(), 'S2 alone, no S4');
    }

    /** The Bitbucket credential exchange is planning-time network work, so it must wait behind the budget check too. */
    public function testAnExhaustedDeadlineNeverExchangesBitbucketCredentials(): void
    {
        $exchanges = 0;
        $auth = new ForgeAuth(Tokens::none(), static fn (string $host): bool => false, static function () use (&$exchanges): bool {
            ++$exchanges;

            return true;
        });
        $packages = [self::locked('vendor/pkg')];
        $loader = $this->loader(['vendor/pkg' => $this->metadataNamed('vendor/pkg', 'https://bitbucket.org/workspace/pkg.git')]);
        $analyzer = $this->analyzer($loader, $this->http([]), false, new Allowlist([]), false, ActivityFetchPlanner::DEFAULT_ANONYMOUS_BUDGET, $auth)
            ->withDeadline(Deadline::inSeconds(0.0, static fn (): float => 0.0));

        $report = $analyzer->analyze(LockFile::fromArray(['packages' => $packages]), ProjectConfig::empty(), false);

        self::assertSame(0, $exchanges);
        self::assertContains('repository activity not checked: install-time budget exhausted', $report->notes());
    }

    public function testBitbucketActivityFeedsS4WithItsOwnWording(): void
    {
        $packages = [self::locked('vendor/pkg')];
        $loader = $this->loader(['vendor/pkg' => $this->metadataNamed('vendor/pkg', 'https://bitbucket.org/workspace/pkg.git')]);
        $answers = [self::BB_COMMITS => [200, '{"values":[{"date":"2015-01-01T00:00:00+00:00"}]}']];

        $finding = self::byName($this->analyzeLock($packages, $loader, $this->http($answers), true))['vendor/pkg'];

        self::assertSame(Verdict::SILENT, $finding->lead());
        self::assertStringContainsString('last commit 2015-01-01 (11.7 years ago)', Summaries::of($finding));
        self::assertSame('bitbucket.org', JsonPath::decoded($finding->signals()[1]->data())['host']);
    }

    public function testTheDataDateIsTheNewerOfTheMetadataAndTheRepositoryActivity(): void
    {
        // The HTTP fake stamps every answer 2026-09-14T06:00:00+00:00: that is when the activity was fetched.
        $answer = [200, '{"archived":false,"pushed_at":"2026-08-01T00:00:00Z"}'];
        $github = 'https://github.com/vendor/pkg.git';

        $activityOnly = $this->analyzeLock([self::locked('vendor/pkg', $github)], $this->loader([], ['vendor/pkg']), $this->http(['https://api.github.com/repos/vendor/pkg' => $answer]), true);
        self::assertSame('2026-09-14T06:00:00+00:00', self::dataDateOf($activityOnly));

        $metadataOnly = $this->singlePackageReport($this->metadataNamed('vendor/pkg', null, '2026-08-01T00:00:00+00:00', '2026-09-14T12:00:00+00:00'), true);
        self::assertSame('2026-09-14T12:00:00+00:00', self::dataDateOf($metadataOnly));

        $activityNewer = $this->singlePackageReport($this->metadataNamed('vendor/pkg', $github, '2026-08-01T00:00:00+00:00', '2026-09-14T00:00:00+00:00'), true, $answer);
        self::assertSame('2026-09-14T06:00:00+00:00', self::dataDateOf($activityNewer));

        $metadataNewer = $this->singlePackageReport($this->metadataNamed('vendor/pkg', $github, '2026-08-01T00:00:00+00:00', '2026-09-14T12:00:00+00:00'), true, $answer);
        self::assertSame('2026-09-14T12:00:00+00:00', self::dataDateOf($metadataNewer));
    }

    public function testTheReportCarriesTheOldestCachedActivityAnswer(): void
    {
        $packages = [self::locked('vendor/a'), self::locked('vendor/b'), self::locked('vendor/c')];
        $metadata = [];
        foreach (['a', 'b', 'c'] as $n) {
            $metadata['vendor/'.$n] = $this->metadataNamed('vendor/'.$n, 'https://github.com/vendor/'.$n.'.git');
        }
        $body = '{"archived":false,"pushed_at":"2015-01-01T00:00:00Z"}';
        $answer = static fn (string $n, string $fetchedAt, bool $cached): HttpResult => $cached
            ? (new HttpResult('https://api.github.com/repos/vendor/'.$n, 200, $body, new \DateTimeImmutable($fetchedAt)))->asCached()
            : new HttpResult('https://api.github.com/repos/vendor/'.$n, 200, $body, new \DateTimeImmutable($fetchedAt));
        $mixed = $this->analyzeLock($packages, $this->loader($metadata), $this->httpAnswering([
            'https://api.github.com/repos/vendor/a' => $answer('a', '2026-09-13T22:00:00+00:00', true),
            'https://api.github.com/repos/vendor/b' => $answer('b', '2026-09-13T04:00:00+00:00', true),
            'https://api.github.com/repos/vendor/c' => $answer('c', '2026-09-12T00:00:00+00:00', false),
        ]), true);
        $oldest = $mixed->activityCacheOldestAt();
        self::assertNotNull($oldest);
        self::assertSame('2026-09-13T04:00:00+00:00', $oldest->format(\DATE_ATOM), 'the oldest cached one; the older fresh answer does not count');
        self::assertSame("package repositories; repository activity from lockrot's cache, up to 20 h old", $mixed->dataSourcesClause());

        $fresh = $this->analyzeLock($packages, $this->loader($metadata), $this->httpAnswering([
            'https://api.github.com/repos/vendor/a' => $answer('a', '2026-09-14T00:00:00+00:00', false),
            'https://api.github.com/repos/vendor/b' => $answer('b', '2026-09-14T00:00:00+00:00', false),
            'https://api.github.com/repos/vendor/c' => $answer('c', '2026-09-14T00:00:00+00:00', false),
        ]), true);
        self::assertNull($fresh->activityCacheOldestAt());
        self::assertSame('package repositories, repository hosts', $fresh->dataSourcesClause());
    }

    public function testACachedActivityAnswerReachesTheTableFooterThroughTheRealCache(): void
    {
        $clock = Clock::fixed(F::NOW);
        $cache = new ArrayCache();
        $url = 'https://api.github.com/repos/vendor/pkg';
        $cache->set($url, new HttpResult($url, 200, '{"archived":false,"pushed_at":"2015-01-01T00:00:00Z"}', new \DateTimeImmutable('2026-09-13T04:30:00+00:00')));
        $never = new class () implements HttpClientInterface {
            public function fetchAll(array $urls, array $headers = []): array
            {
                throw new \LogicException('the cache should have answered');
            }
        };
        $http = new CachingHttpClient($never, $cache, ActivityClient::CACHE_TTL, $clock);
        $loader = $this->loader(['vendor/pkg' => $this->metadataNamed('vendor/pkg', 'https://github.com/vendor/pkg.git')]);

        $report = $this->analyzeLock([self::locked('vendor/pkg')], $loader, $http, true);

        self::assertSame('2026-09-13T04:30:00+00:00', self::atomOf($report->activityCacheOldestAt()));
        $table = (new TableFormatter(FormatContext::create(null, LockrotConfig::FAIL_ON_NONE, '0.4.0', 200)))->format($report);
        self::assertStringContainsString("Data as of 2026-09-14 (package repositories; repository activity from lockrot's cache, up to 20 h old) · score model 1.", $table);
        self::assertSame('2026-09-13T04:30:00+00:00', $report->toArray()['activity_cache_oldest_at']);
    }

    private static function atomOf(?\DateTimeImmutable $date): ?string
    {
        return $date === null ? null : $date->format(\DATE_ATOM);
    }

    /**
     * Hands back the prepared results as they are, cache flags included. A URL without a prepared
     * result gets a failure, as the real clients answer.
     *
     * @param array<string, HttpResult> $results
     */
    private function httpAnswering(array $results): HttpClientInterface
    {
        return new class ($results) implements HttpClientInterface {
            /** @var array<string, HttpResult> */
            private array $results;

            /** @param array<string, HttpResult> $results */
            public function __construct(array $results)
            {
                $this->results = $results;
            }

            public function fetchAll(array $urls, array $headers = []): array
            {
                $answers = [];
                foreach ($urls as $url) {
                    $answers[$url] = $this->results[$url] ?? HttpResult::failure($url, 'not prepared: '.$url, new \DateTimeImmutable('@0'));
                }

                return $answers;
            }
        };
    }

    private static function dataDateOf(Report $report): string
    {
        $date = $report->findings()[0]->dataDate();
        self::assertNotNull($date);

        return $date->format(\DATE_ATOM);
    }
}
