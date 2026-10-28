<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Analyzer;

use Composer\Config;
use Composer\Policy\AbandonedPolicyConfig;
use Composer\Semver\VersionParser;
use Composer\Util\Platform;
use Lockrot\Allowlist\Allowlist;
use Lockrot\Allowlist\AllowlistEntry;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunNote;
use Lockrot\Clock;
use Lockrot\Data\Abandoned\AbandonedIgnore;
use Lockrot\Data\Abandoned\AbandonedIgnoreMatch;
use Lockrot\Data\Abandoned\AbandonedIgnoreRule;
use Lockrot\Data\Advisory\Advisory;
use Lockrot\Data\Advisory\AdvisoryBatch;
use Lockrot\Data\Advisory\AdvisoryCoverage;
use Lockrot\Data\Advisory\AdvisoryLoaderInterface;
use Lockrot\Data\Advisory\RepositoryAdvisoryLoader;
use Lockrot\Data\Forge\ActivityClient;
use Lockrot\Data\Forge\ActivityFetchPlanner;
use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\RepoLocator;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Data\Http\HttpClientInterface;
use Lockrot\Data\Http\HttpResult;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\MetadataBatch;
use Lockrot\Data\Repository\MetadataLoaderInterface;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which lookups the analyzer runs for a package: the repository activity (Composer's abandoned ignore list, partial
 * allowlist entries) and the advisory names.
 */
final class AnalyzerLookupTest extends TestCase
{
    private const GITHUB = 'https://api.github.com/repos/vendor/pkg';
    private const ARCHIVED = '{"archived":true,"pushed_at":"2026-08-01T00:00:00Z"}';
    private const LIVE = '{"archived":false,"pushed_at":"2026-08-01T00:00:00Z"}';

    /**
     * @param array<string, array{int, string}> $map       url => [status, body]
     * @param \ArrayObject<int, string>         $requested
     */
    private static function http(array $map, \ArrayObject $requested): HttpClientInterface
    {
        return new class ($map, $requested) implements HttpClientInterface {
            /** @var array<string, array{int, string}> */
            private array $map;
            /** @var \ArrayObject<int, string> */
            private \ArrayObject $requested;

            /**
             * @param array<string, array{int, string}> $map
             * @param \ArrayObject<int, string>         $requested
             */
            public function __construct(array $map, \ArrayObject $requested)
            {
                $this->map = $map;
                $this->requested = $requested;
            }

            public function fetchAll(array $urls, array $headers = []): array
            {
                $out = [];
                foreach ($urls as $url) {
                    $this->requested->append($url);
                    [$status, $body] = $this->map[$url] ?? [404, ''];
                    $out[$url] = new HttpResult($url, $status, $body, new \DateTimeImmutable(F::NOW));
                }

                return $out;
            }
        };
    }

    /** @param array<string, PackageMetadata> $metadata */
    private static function loader(array $metadata): MetadataLoaderInterface
    {
        return new class (new MetadataBatch($metadata, [], [])) implements MetadataLoaderInterface {
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

    private static function analyzer(MetadataLoaderInterface $metadata, HttpClientInterface $http, bool $token, Allowlist $allowlist, ?AbandonedIgnore $abandonedIgnore = null, ?AdvisoryLoaderInterface $advisories = null): Analyzer
    {
        $clock = Clock::fixed(F::NOW);
        $auth = ForgeAuth::withTokens(new Tokens($token ? 't' : null, null));

        return new Analyzer(
            $metadata,
            new ActivityClient($http, $auth),
            new ActivityFetchPlanner($auth),
            new RepoLocator(),
            $allowlist,
            SignalSet::default($clock, new Thresholds(), '8.4', PhpReleaseDates::load()),
            $clock,
            false,
            $advisories,
            null,
            $abandonedIgnore
        );
    }

    private static function advisories(AdvisoryBatch $batch): AdvisoryLoaderInterface
    {
        return new class ($batch) implements AdvisoryLoaderInterface {
            private AdvisoryBatch $batch;

            public function __construct(AdvisoryBatch $batch)
            {
                $this->batch = $batch;
            }

            public function load(array $versionByName, array $notFromComposerRepository = [], array $aliasVersionsByName = []): AdvisoryBatch
            {
                return $this->batch;
            }
        };
    }

    private static function metadata(bool $abandoned, string $releasedAt): PackageMetadata
    {
        return new PackageMetadata('vendor/pkg', $abandoned, null, true, new \DateTimeImmutable($releasedAt), '1.0.0', 1, 'https://github.com/vendor/pkg.git', 'library', new \DateTimeImmutable(F::NOW));
    }

    private static function finding(Report $report): Finding
    {
        self::assertCount(1, $report->findings());

        return $report->findings()[0];
    }

    /** @return list<string> */
    private static function ids(Finding $finding): array
    {
        return array_map(static fn (Signal $signal): string => $signal->id(), $finding->signals());
    }

    private static function analyzeOne(Analyzer $analyzer): Report
    {
        $lock = LockFile::fromArray(['packages' => [['name' => 'vendor/pkg', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/']]]);

        return $analyzer->analyze($lock, ProjectConfig::empty(), false);
    }

    private static function listed(): AbandonedIgnore
    {
        return AbandonedIgnore::of(AbandonedIgnoreMatch::BY_POLICY, [new AbandonedIgnoreRule('vendor/pkg', 'migration planned', [])]);
    }

    /** An entry that accepts `stale` only cannot hide an archived repository: the lookup runs. */
    public function testAnEntryForSomeFlagsStillAsksTheRepositoryHost(): void
    {
        /** @var \ArrayObject<int, string> $requested */
        $requested = new \ArrayObject();
        $entry = new AllowlistEntry('vendor/pkg', null, 'stale is fine', null, 'config', ['stale']);

        $finding = self::finding(self::analyzeOne(self::analyzer(self::loader(['vendor/pkg' => self::metadata(false, '2015-01-01T00:00:00+00:00')]), self::http([self::GITHUB => [200, self::ARCHIVED]], $requested), true, new Allowlist([$entry]))));

        self::assertSame([self::GITHUB], $requested->getArrayCopy());
        self::assertContains(Signal::S3, self::ids($finding));
    }

    public function testAnEntryForEveryFlagSkipsTheRepositoryHost(): void
    {
        /** @var \ArrayObject<int, string> $requested */
        $requested = new \ArrayObject();
        $entry = new AllowlistEntry('vendor/pkg', null, 'finished', null, 'config');

        $finding = self::finding(self::analyzeOne(self::analyzer(self::loader(['vendor/pkg' => self::metadata(false, '2015-01-01T00:00:00+00:00')]), self::http([self::GITHUB => [200, self::ARCHIVED]], $requested), true, new Allowlist([$entry]))));

        self::assertSame([], $requested->getArrayCopy());
        self::assertNull($finding->lead());
    }

    /** Tokenless runs ask only candidates. An ignored marking leaves S3 as the only abandonment fact, so the package is one. */
    public function testATokenlessRunAsksAboutAPackageWhoseMarkingIsIgnored(): void
    {
        /** @var \ArrayObject<int, string> $requested */
        $requested = new \ArrayObject();
        $metadata = ['vendor/pkg' => self::metadata(true, '2026-08-01T00:00:00+00:00')];

        $finding = self::finding(self::analyzeOne(self::analyzer(self::loader($metadata), self::http([self::GITHUB => [200, self::ARCHIVED]], $requested), false, new Allowlist([]), self::listed())));

        self::assertSame([self::GITHUB], $requested->getArrayCopy(), 'no S2, and still a candidate');
        self::assertNotContains(Signal::S1, self::ids($finding));
        self::assertContains(Signal::S3, self::ids($finding), 'the archived repository still counts');
        self::assertSame(Verdict::ABANDONED, $finding->lead());
    }

    /** The finding keeps the marking that the list removed, and one run note names the package. */
    public function testAnIgnoredMarkingIsWrittenOnTheFindingAndInANote(): void
    {
        $metadata = ['vendor/pkg' => self::metadata(true, '2026-08-01T00:00:00+00:00')];

        $report = self::analyzeOne(self::analyzer(self::loader($metadata), self::http([self::GITHUB => [200, self::ARCHIVED]], new \ArrayObject()), false, new Allowlist([]), self::listed()));
        $written = JsonPath::decoded(self::finding($report)->toArray());

        self::assertSame(
            ['by' => 'policy.abandoned', 'rules' => [['pattern' => 'vendor/pkg', 'reason' => 'migration planned', 'constraints' => []]], 'marking' => ['marked_by' => 'repository', 'replacement' => null, 'replacement_url' => null]],
            $written['abandoned_ignored']
        );
        $notes = array_values(array_filter($report->runNotes(), static fn (RunNote $note): bool => $note->code() === RunNote::ABANDONED_MARKINGS_IGNORED));
        self::assertCount(1, $notes);
        self::assertSame(['by' => 'policy.abandoned', 'count' => 1, 'packages' => [['package' => 'vendor/pkg', 'patterns' => ['vendor/pkg']]]], $notes[0]->data());
    }

    /** The note names the packages in report order: the graded one first, whatever the lock order. */
    public function testTheIgnoredMarkingsNoteFollowsTheReportOrder(): void
    {
        $lock = LockFile::fromArray(['packages' => [
            ['name' => 'a/new', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/'],
            ['name' => 'z/old', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/'],
        ]]);
        $metadata = ['a/new' => self::metadata(true, '2026-08-01T00:00:00+00:00'), 'z/old' => self::metadata(true, '2015-01-01T00:00:00+00:00')];
        $everything = AbandonedIgnore::of(AbandonedIgnoreMatch::BY_POLICY, [new AbandonedIgnoreRule('*', null, [])]);

        $report = self::analyzer(self::loader($metadata), self::http([self::GITHUB => [200, self::LIVE]], new \ArrayObject()), true, new Allowlist([]), $everything)->analyze($lock, ProjectConfig::empty(), false);
        $notes = array_values(array_filter($report->runNotes(), static fn (RunNote $note): bool => $note->code() === RunNote::ABANDONED_MARKINGS_IGNORED));

        self::assertCount(1, $notes);
        self::assertSame(['z/old', 'a/new'], array_column(JsonPath::arrayAt($notes[0]->data(), ['packages']), 'package'));
    }

    /** The note names the `ignore[]` entries in report order: the graded one first, whatever the lock order. */
    public function testTheIgnoreEntriesNoteFollowsTheReportOrder(): void
    {
        $lock = LockFile::fromArray(['packages' => [
            ['name' => 'a/new', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/'],
            ['name' => 'z/old', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/'],
        ]]);
        $metadata = ['a/new' => self::metadata(false, '2026-08-01T00:00:00+00:00'), 'z/old' => self::metadata(false, '2015-01-01T00:00:00+00:00')];
        $allowlist = new Allowlist([
            new AllowlistEntry('a/new', null, 'kept', null, AllowlistEntry::BY_PROJECT, ['left-behind']),
            new AllowlistEntry('z/old', null, 'kept', null, AllowlistEntry::BY_PROJECT, ['left-behind']),
        ]);
        $advisories = new AdvisoryBatch([
            'a/new' => [new Advisory('PKSA-new', null, 'Title', null, 'low', null, (new VersionParser())->parseConstraints('<2.0.0'))],
            'z/old' => [new Advisory('PKSA-old', null, 'Title', null, 'high', null, (new VersionParser())->parseConstraints('<2.0.0'))],
        ]);

        $report = self::analyzer(self::loader($metadata), self::http([], new \ArrayObject()), true, $allowlist, null, self::advisories($advisories))->analyze($lock, ProjectConfig::empty(), false);
        $notes = array_values(array_filter($report->runNotes(), static fn (RunNote $note): bool => $note->code() === RunNote::IGNORE_DOES_NOT_COVER_ADVISORIES));

        self::assertCount(1, $notes);
        self::assertSame(['z/old', 'a/new'], JsonPath::column(JsonPath::decoded($notes[0]->data()), ['entries'], 'package'));
    }

    /** @return iterable<string, array{array<string, mixed>, bool, ?string}> the config, whether the package is marked, and the note that the run writes */
    public static function abandonedSettings(): iterable
    {
        yield 'the audit set to ignore, beside a raised S1' => [['policy' => ['abandoned' => ['audit' => 'ignore']]], true, RunNote::ABANDONED_NOT_AUDITED_BY_COMPOSER];
        yield 'the audit set to ignore, no S1' => [['policy' => ['abandoned' => ['audit' => 'ignore']]], false, null];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @dataProvider abandonedSettings
     */
    #[DataProvider('abandonedSettings')]
    public function testTheAuditSettingIsNotedOnlyBesideARaisedS1(array $config, bool $marked, ?string $note): void
    {
        $composer = new Config(false);
        $composer->merge(['config' => $config]);
        $report = self::analyzeOne(self::analyzer(self::loader(['vendor/pkg' => self::metadata($marked, '2026-08-01T00:00:00+00:00')]), self::http([self::GITHUB => [200, self::LIVE]], new \ArrayObject()), true, new Allowlist([]), AbandonedIgnore::fromConfig($composer)));
        $codes = array_map(static fn (RunNote $n): string => $n->code(), $report->runNotes());

        self::assertSame($note !== null, \in_array(RunNote::ABANDONED_NOT_AUDITED_BY_COMPOSER, $codes, true));
    }

    /** A run where the list removes one marking and Composer's audit is off writes both notes. */
    public function testTheIgnoredMarkingsNoteAndTheAuditNoteAreWrittenTogether(): void
    {
        $lock = LockFile::fromArray(['packages' => [
            ['name' => 'a/listed', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/'],
            ['name' => 'z/marked', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/'],
        ]]);
        $metadata = ['a/listed' => self::metadata(true, '2026-08-01T00:00:00+00:00'), 'z/marked' => self::metadata(true, '2026-08-01T00:00:00+00:00')];
        $composer = new Config(false);
        $composer->merge(['config' => ['audit' => ['abandoned' => 'ignore', 'ignore-abandoned' => ['a/listed' => 'migration planned']]]]);

        $report = self::analyzer(self::loader($metadata), self::http([self::GITHUB => [200, self::LIVE]], new \ArrayObject()), true, new Allowlist([]), AbandonedIgnore::fromConfig($composer))->analyze($lock, ProjectConfig::empty(), false);
        $codes = array_map(static fn (RunNote $n): string => $n->code(), $report->runNotes());

        self::assertSame(
            [RunNote::ABANDONED_MARKINGS_IGNORED, RunNote::ABANDONED_NOT_AUDITED_BY_COMPOSER],
            array_values(array_intersect($codes, [RunNote::ABANDONED_MARKINGS_IGNORED, RunNote::ABANDONED_NOT_AUDITED_BY_COMPOSER, RunNote::ABANDONED_IGNORE_UNREADABLE])),
            implode(', ', $codes)
        );
    }

    public function testAnUnreadableListIsNoted(): void
    {
        if (!class_exists(AbandonedPolicyConfig::class)) {
            self::markTestSkipped('Composer without the policy API (2.10 and later)');
        }
        Platform::putEnv('COMPOSER_POLICY', 'maybe');
        try {
            $ignore = AbandonedIgnore::fromConfig(new Config(false));
        } finally {
            Platform::clearEnv('COMPOSER_POLICY');
        }
        $report = self::analyzeOne(self::analyzer(self::loader(['vendor/pkg' => self::metadata(true, '2026-08-01T00:00:00+00:00')]), self::http([self::GITHUB => [200, self::LIVE]], new \ArrayObject()), true, new Allowlist([]), $ignore));

        self::assertContains(RunNote::ABANDONED_IGNORE_UNREADABLE, array_map(static fn (RunNote $n): string => $n->code(), $report->runNotes()));
    }

    public function testANotListedPackageWritesNoIgnoredMarking(): void
    {
        $report = self::analyzeOne(self::analyzer(self::loader(['vendor/pkg' => self::metadata(true, '2026-08-01T00:00:00+00:00')]), self::http([self::GITHUB => [200, self::ARCHIVED]], new \ArrayObject()), false, new Allowlist([])));

        self::assertNull(JsonPath::decoded(self::finding($report)->toArray())['abandoned_ignored']);
        self::assertSame([], array_filter($report->runNotes(), static fn (RunNote $note): bool => $note->code() === RunNote::ABANDONED_MARKINGS_IGNORED));
    }

    public function testAnIgnoredMarkingOnALiveRepositoryLeavesNoAbandonment(): void
    {
        /** @var \ArrayObject<int, string> $requested */
        $requested = new \ArrayObject();
        $metadata = ['vendor/pkg' => self::metadata(true, '2026-08-01T00:00:00+00:00')];

        $finding = self::finding(self::analyzeOne(self::analyzer(self::loader($metadata), self::http([self::GITHUB => [200, self::LIVE]], $requested), false, new Allowlist([]), self::listed())));

        self::assertSame([], self::ids($finding));
        self::assertNull($finding->lead());
    }

    /** The S10 filter reads the raised S1, not the metadata's abandoned bit. */
    public function testAFailedLookupOnAPackageWhoseMarkingIsIgnoredKeepsS10(): void
    {
        /** @var \ArrayObject<int, string> $requested */
        $requested = new \ArrayObject();
        $metadata = ['vendor/pkg' => self::metadata(true, '2026-08-01T00:00:00+00:00')];

        $finding = self::finding(self::analyzeOne(self::analyzer(self::loader($metadata), self::http([self::GITHUB => [500, '']], $requested), true, new Allowlist([]), self::listed())));

        self::assertSame([Signal::S10], self::ids($finding));
        self::assertSame([['check' => 'repository_activity', 'reason' => 'fetch_failed', 'blocks' => [Signal::S3, Signal::S4]]], JsonPath::decoded($finding->signals()[0]->data())['unchecked']);
    }

    /** Where S1 counts, a tokenless run asks nothing about the package and raises no S10 for it. */
    public function testACountedMarkingKeepsItsBehaviour(): void
    {
        /** @var \ArrayObject<int, string> $requested */
        $requested = new \ArrayObject();
        $metadata = ['vendor/pkg' => self::metadata(true, '2026-08-01T00:00:00+00:00')];

        $finding = self::finding(self::analyzeOne(self::analyzer(self::loader($metadata), self::http([self::GITHUB => [200, self::ARCHIVED]], $requested), false, new Allowlist([]))));

        self::assertSame([], $requested->getArrayCopy());
        self::assertSame([Signal::S1], self::ids($finding), 'the activity gap of a raised S1 is no S10');
        self::assertSame(Verdict::ABANDONED, $finding->lead());
    }

    public function testAMarkingInTheLockAloneAlsoDropsTheActivityGap(): void
    {
        /** @var \ArrayObject<int, string> $requested */
        $requested = new \ArrayObject();
        $lock = LockFile::fromArray(['packages' => [['name' => 'vendor/pkg', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/', 'abandoned' => true, 'source' => ['type' => 'git', 'url' => 'https://github.com/vendor/pkg.git', 'reference' => 'a']]]]);

        $report = self::analyzer(self::loader([]), self::http([self::GITHUB => [500, '']], $requested), true, new Allowlist([]))->analyze($lock, ProjectConfig::empty(), false);

        self::assertSame([Signal::S1], self::ids(self::finding($report)));
    }

    /** @return array{AdvisoryLoaderInterface, \ArrayObject<string, mixed>} */
    private static function recordingAdvisories(): array
    {
        /** @var \ArrayObject<string, mixed> $asked */
        $asked = new \ArrayObject();
        $loader = new class ($asked) implements AdvisoryLoaderInterface {
            /** @var \ArrayObject<string, mixed> */
            private \ArrayObject $asked;

            /** @param \ArrayObject<string, mixed> $asked */
            public function __construct(\ArrayObject $asked)
            {
                $this->asked = $asked;
            }

            public function load(array $versionByName, array $notFromComposerRepository = [], array $aliasVersionsByName = []): AdvisoryBatch
            {
                $this->asked['names'] = $versionByName;
                $this->asked['outside'] = $notFromComposerRepository;

                return AdvisoryBatch::empty();
            }
        };

        return [$loader, $asked];
    }

    public function testEveryCheckedPackageIsGivenToTheAdvisoryLookupWithItsOrigin(): void
    {
        [$advisories, $asked] = self::recordingAdvisories();
        $lock = LockFile::fromArray([
            'packages' => [
                ['name' => 'vendor/pkg', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/'],
                ['name' => 'acme/fork', 'version' => '2.0.0', 'source' => ['type' => 'git', 'url' => 'https://git.example.com/acme/fork.git', 'reference' => 'a']],
            ],
            'packages-dev' => [['name' => 'vendor/devtool', 'version' => '3.0.0', 'notification-url' => 'https://packagist.org/downloads/']],
        ]);
        /** @var \ArrayObject<int, string> $requested */
        $requested = new \ArrayObject();
        $analyzer = self::analyzer(self::loader([]), self::http([], $requested), true, new Allowlist([]), null, $advisories);

        $analyzer->analyze($lock, ProjectConfig::empty(), false);

        self::assertSame(['vendor/pkg' => '1.0.0.0', 'acme/fork' => '2.0.0.0'], $asked['names'], 'a packages-dev name only with --dev');
        self::assertSame(['acme/fork'], $asked['outside']);

        $analyzer->analyze($lock, ProjectConfig::empty(), true);

        self::assertSame(['vendor/pkg' => '1.0.0.0', 'acme/fork' => '2.0.0.0', 'vendor/devtool' => '3.0.0.0'], $asked['names']);
    }

    public function testThePackagesOutsideEveryComposerRepositoryAreNotedUnderBothScopes(): void
    {
        $lock = LockFile::fromArray(['packages' => [['name' => 'acme/fork', 'version' => '2.0.0', 'source' => ['type' => 'git', 'url' => 'https://git.example.com/acme/fork.git', 'reference' => 'a']]]]);
        foreach ([AdvisoryCoverage::SCOPE_ALL, AdvisoryCoverage::SCOPE_COMPOSER_REPOSITORIES] as $scope) {
            /** @var \ArrayObject<int, string> $requested */
            $requested = new \ArrayObject();
            $advisories = new RepositoryAdvisoryLoader([], false, null, null, $scope, AdvisoryCoverage::SOURCE_CONFIG);

            $report = self::analyzer(self::loader([]), self::http([], $requested), true, new Allowlist([]), null, $advisories)->analyze($lock, ProjectConfig::empty(), false);

            $codes = array_map(static fn (RunNote $note): string => $note->code(), $report->runNotes());
            self::assertContains(RunNote::NOT_FROM_COMPOSER_REPOSITORY, $codes, $scope);
            self::assertSame(1, $report->notFromComposerRepository(), $scope);
        }
    }
}
