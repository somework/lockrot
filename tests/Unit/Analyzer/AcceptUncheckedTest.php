<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Analyzer;

use Composer\Package\BasePackage;
use Composer\Package\Loader\ArrayLoader;
use Lockrot\Allowlist\Allowlist;
use Lockrot\Allowlist\AllowlistEntry;
use Lockrot\Allowlist\UncheckedAcceptance;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunSettings;
use Lockrot\Clock;
use Lockrot\Data\Advisory\AdvisoryBatch;
use Lockrot\Data\Advisory\AdvisoryCoverage;
use Lockrot\Data\Advisory\AdvisoryFeed;
use Lockrot\Data\Advisory\AdvisoryLoaderInterface;
use Lockrot\Data\Advisory\AdvisoryNameCoverage;
use Lockrot\Data\Advisory\AdvisoryRepository;
use Lockrot\Data\Forge\ActivityClient;
use Lockrot\Data\Forge\ActivityFetchPlanner;
use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\RepoLocator;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\MetadataBatch;
use Lockrot\Data\Repository\MetadataLoaderInterface;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Output\FormatContext;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\FakeHttpClient;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Verdict\FailOn;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `extra.lockrot.accept-unchecked` through the analyzer (SPEC-0.14.md §6.7, O9): which S10 entries
 * an entry accepts, what the finding writes for them, and that nothing but `unchecked` moves.
 * The four `‡` cases of `tests/fixtures/flags/cases.json` give the expected fields.
 */
final class AcceptUncheckedTest extends TestCase
{
    private const NOW = '2026-10-01T12:00:00+00:00';
    private const PACKAGIST = 'https://packagist.org/downloads/';
    private const MIRROR = 'https://mirror.example.org';
    private const HOST_REASON = "Forgejo has no API lockrot reads; we watch the repository by hand (\u{2021})";
    private const MIRROR_REASON = "the mirror has no advisory feed; composer audit runs against packagist.org in CI (\u{2021})";
    private const FORK_REASON = "joomla's backport fork; we follow its branch (\u{2021})";

    /** host-accepted‡: a host that lockrot cannot ask, accepted by its host. */
    public function testTheAcceptanceIsSetOnACopy(): void
    {
        $clock = Clock::fixed(self::NOW);
        $auth = ForgeAuth::withTokens(new Tokens(null, null));
        $analyzer = new Analyzer(self::loader([]), new ActivityClient(new FakeHttpClient([]), $auth), new ActivityFetchPlanner($auth), new RepoLocator(), new Allowlist([]), SignalSet::default($clock, new Thresholds(), '8.4', PhpReleaseDates::load()), $clock, false);
        $accepting = $analyzer->withUncheckedAcceptance(UncheckedAcceptance::fromConfig([['check' => 'advisories', 'package' => 'acme/pkg', 'reason' => 'r']]));

        self::assertSame([true, false], [$analyzer->uncheckedAcceptance()->isEmpty(), $accepting->uncheckedAcceptance()->isEmpty()]);
    }

    public function testAHostEntryAcceptsTheActivityOfAPackageOnThatHost(): void
    {
        $finding = self::written(self::codeberg('2027-03-31'), 'high,unchecked');

        self::assertSame(self::model('host-accepted‡', ['unchecked_accepted']), $finding['unchecked_accepted']);
        self::assertSame(self::model('host-accepted‡', ['checks_skipped']), $finding['checks_skipped']);
        self::assertNotContains('S10', JsonPath::column($finding, ['signals'], 'id'));
        self::assertFalse(JsonPath::boolAt($finding, ['gate', 'fails']), '--fail-on=high,unchecked passes');
    }

    /** host-accept-expired‡: an expired entry accepts nothing, so the S10 stays and the gate fails. */
    public function testAnExpiredEntryAcceptsNothing(): void
    {
        $finding = self::written(self::codeberg('2026-06-30'), 'high,unchecked');

        self::assertSame([], $finding['unchecked_accepted']);
        self::assertSame(self::model('host-accept-expired‡', ['checks_skipped']), $finding['checks_skipped']);
        self::assertContains('S10', JsonPath::column($finding, ['signals'], 'id'));
        self::assertTrue(JsonPath::boolAt($finding, ['gate', 'fails']));
    }

    /** @return iterable<string, array{string, bool}> the run's clock, and whether an entry that expires on 2026-10-01 accepts */
    public static function expiryClocks(): iterable
    {
        yield 'the last second of the expiry date, UTC' => ['2026-10-01T23:59:59+00:00', true];
        yield 'the first second after it' => ['2026-10-02T00:00:00+00:00', false];
        yield 'the expiry date in UTC, the next day in Asia' => ['2026-10-02T05:00:00+08:00', true];
    }

    /** @dataProvider expiryClocks */
    #[DataProvider('expiryClocks')]
    public function testAnEntryThatExpiresTodayAcceptsUntilTheEndOfTheUtcDay(string $now, bool $accepts): void
    {
        $finding = self::written(self::codeberg('2026-10-01', $now), 'unchecked');

        self::assertSame($accepts, $finding['unchecked_accepted'] !== []);
    }

    /**
     * no-feed-accepted‡: a mirror with no advisory feed, accepted by its URL. The advisories stay
     * unknown, so `gate.not_applied` names the package and the run-level record stays a warning.
     */
    public function testARepositoryEntryAcceptsTheNoFeedOfAnUnnamedMirrorByItsUrl(): void
    {
        $report = self::report(self::mirrorPackage(self::MIRROR), 'high,unchecked');
        $finding = JsonPath::arrayAt(JsonPath::decoded($report->toArray()), ['findings', 0]);

        self::assertSame(self::model('no-feed-accepted‡', ['unchecked_accepted']), $finding['unchecked_accepted']);
        self::assertSame(self::model('no-feed-accepted‡', ['checks_skipped']), $finding['checks_skipped']);
        self::assertSame([['check' => 'advisories', 'packages' => 1, 'reason' => 'no_feed']], JsonPath::arrayAt(JsonPath::decoded($report->toArray()), ['gate', 'not_applied']));
        self::assertFalse(JsonPath::boolAt(JsonPath::decoded($report->toArray()), ['gate', 'fails']));
        self::assertSame(FormatContext::LEVEL_WARNING, FormatContext::create(null, 'high,unchecked')->advisoriesNotCheckedLevel($report));
    }

    public function testAnEntryForOneMirrorNeverAcceptsAPackageFromAnother(): void
    {
        $finding = self::written(self::mirrorPackage('https://other-mirror.example.org'), 'unchecked');

        self::assertSame([], $finding['unchecked_accepted']);
        self::assertTrue(JsonPath::boolAt($finding, ['gate', 'fails']));
    }

    /** A named registry is compared by its name, so the URL of the repository that answered does not match. */
    /** SPEC §6.7: a named registry matches by its name and by the URL that served the package, never another organisation's URL. */
    public function testARepositoryEntryMatchesANamedRegistryByItsNameOrTheUrlThatAnswered(): void
    {
        self::assertNotSame([], self::written(self::registryPackage('https://repo.packagist.com'), 'unchecked')['unchecked_accepted']);
        self::assertNotSame([], self::written(self::registryPackage('https://repo.packagist.com/acme'), 'unchecked')['unchecked_accepted']);
        self::assertSame([], self::written(self::registryPackage('https://repo.packagist.com/other'), 'unchecked')['unchecked_accepted']);
    }

    /** SPEC §6.7: `https://repo.packagist.org` matches every package that lockrot attributes to packagist.org. */
    public function testTheRepoPackagistOrgEntryMatchesAPackagistOrgPackage(): void
    {
        $inputs = self::registryPackage('https://repo.packagist.org');
        $inputs['lock'] = ['name' => 'acme/private', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/'];
        $inputs['meta'] = self::metadata('acme/private', '1.0.0', null, 'https://repo.packagist.org');

        self::assertNotSame([], self::written($inputs, 'unchecked')['unchecked_accepted']);
        $inputs['accept'] = [['check' => 'advisories', 'repository' => 'https://packagist.org', 'reason' => 'by the registry name']];
        self::assertNotSame([], self::written($inputs, 'unchecked')['unchecked_accepted'], 'https://packagist.org');
        $inputs['accept'] = [['check' => 'advisories', 'repository' => 'https://repo.packagist.org', 'reason' => 'by the registry name']];
        $inputs['meta'] = self::metadata('acme/private', '1.0.0', null, null);
        self::assertNotSame([], self::written($inputs, 'unchecked')['unchecked_accepted'], 'by the registry name alone');
    }

    /** vcs-accepted‡: a vcs fork whose two checks are accepted by its package and by its repository. */
    public function testAPackageEntryAndARepositoryEntryAcceptTheChecksOfAVcsFork(): void
    {
        $finding = self::written(self::vcsFork([
            ['check' => 'release_metadata', 'package' => 'tobscure/*', 'reason' => self::FORK_REASON],
            ['check' => 'repository_activity', 'repository' => 'https://github.com/joomla-backports/json-api-php.git', 'reason' => "we watch the fork's commits on GitHub by hand (\u{2021})"],
        ]), 'high,unchecked');

        self::assertSame(self::model('vcs-accepted‡', ['unchecked_accepted']), $finding['unchecked_accepted']);
        self::assertContains('pinned', JsonPath::column($finding, ['flags'], 'id'), 'pinned still counts');
        self::assertNotContains('S10', JsonPath::column($finding, ['signals'], 'id'));
        self::assertNotContains('unchecked', JsonPath::column($finding, ['gate', 'by'], 'value'), 'unchecked no longer fails it');
    }

    /** The first matching entry is the one the finding echoes. */
    public function testTheFirstMatchingEntryWins(): void
    {
        $finding = self::written(self::vcsFork([
            ['check' => 'repository_activity', 'package' => 'tobscure/json-api', 'reason' => 'first'],
            ['check' => 'repository_activity', 'repository' => 'https://github.com/joomla-backports/json-api-php', 'reason' => 'second'],
        ]), 'unchecked');

        self::assertSame(['first'], array_column(JsonPath::column($finding, ['unchecked_accepted'], 'accepted_by'), 'reason'));
    }

    /** A reason a rerun can close is never accepted: the S10 stays. */
    public function testAnOfflineGapStaysUnchecked(): void
    {
        $finding = self::written(self::mirrorPackage(self::MIRROR, 'offline'), 'unchecked');

        self::assertSame([], $finding['unchecked_accepted']);
        self::assertContains('S10', JsonPath::column($finding, ['signals'], 'id'));
    }

    /**
     * An unknown-origin package (a bower-asset package from asset-packagist.org) has no Composer
     * repository to compare: a `package` entry accepts it, a `repository` entry for that registry never does.
     */
    public function testAnUnknownOriginPackageIsAcceptedByItsNameOnly(): void
    {
        self::assertSame([], self::written(self::bowerAsset(['check' => 'advisories', 'repository' => 'https://asset-packagist.org', 'reason' => 'no feed']), 'unchecked')['unchecked_accepted']);
        self::assertNotSame([], self::written(self::bowerAsset(['check' => 'advisories', 'package' => 'bower-asset/*', 'reason' => 'no feed']), 'unchecked')['unchecked_accepted']);
    }

    /** A partial `ignore[]` entry leaves the S10, `accept-unchecked` removes it, and no flag or score moves. */
    public function testOnlyAcceptUncheckedRemovesTheGapAndNothingElseMoves(): void
    {
        $ignored = self::vcsFork([], [new AllowlistEntry('tobscure/json-api', null, 'kept', null, AllowlistEntry::BY_PROJECT, ['pinned'])]);
        $accepted = self::vcsFork([['check' => 'repository_activity', 'package' => 'tobscure/*', 'reason' => 'fork'], ['check' => 'release_metadata', 'package' => 'tobscure/*', 'reason' => 'fork']]);
        $plain = self::vcsFork([]);

        self::assertContains('S10', JsonPath::column(self::written($ignored, 'unchecked'), ['signals'], 'id'));
        $before = self::written($plain, 'high');
        $after = self::written($accepted, 'high');
        self::assertNotContains('S10', JsonPath::column($after, ['signals'], 'id'));
        $facts = static fn (array $f): array => [JsonPath::arrayAt($f, ['flags']), JsonPath::intAt($f, ['score', 'total']), JsonPath::stringAt($f, ['priority']), JsonPath::boolAt($f, ['gate', 'fails'])];
        self::assertSame($facts($before), $facts($after));
    }

    /**
     * SPEC-0.14.md §2.3: a vcs package whose S1 is raised under `ignore[] {flags: [abandoned]}`
     * loses its maintenance S10 to the filter, which reads the raised S1, never a counted flag.
     */
    public function testARaisedS1DropsTheMaintenanceGapEvenWhenAnEntryAcceptsIt(): void
    {
        $fork = self::vcsFork([], [new AllowlistEntry('tobscure/json-api', null, 'replaced next sprint', null, AllowlistEntry::BY_PROJECT, ['abandoned'])]);
        $lock = $fork['lock'];
        self::assertIsArray($lock);
        $fork['lock'] = ['abandoned' => true] + $lock;
        $finding = self::written($fork, 'unchecked');

        self::assertContains('S1', JsonPath::column($finding, ['signals'], 'id'));
        self::assertNotContains('S10', JsonPath::column($finding, ['signals'], 'id'));
        self::assertSame([], $finding['unchecked_accepted']);
    }

    /**
     * The analyzer inputs of one package: the lock entry, its metadata or null, the advisory lookup's
     * gap or null, the `accept-unchecked` rows, the run's clock and the allowlist entries.
     *
     * @return array<string, mixed>
     */
    private static function codeberg(string $expires, string $now = self::NOW): array
    {
        return [
            'lock' => ['name' => 'ssddanbrown/htmldiff', 'version' => 'v2.0.0', 'notification-url' => self::PACKAGIST, 'source' => ['type' => 'git', 'url' => 'https://codeberg.org/danb/HtmlDiff.git', 'reference' => 'x']],
            'meta' => self::metadata('ssddanbrown/htmldiff', 'v2.0.0', 'https://codeberg.org/danb/HtmlDiff.git', null),
            'gap' => null,
            'accept' => [['check' => 'repository_activity', 'host' => 'codeberg.org', 'expires' => $expires, 'reason' => self::HOST_REASON]],
            'now' => $now,
            'allowlist' => [],
        ];
    }

    /** @return array<string, mixed> */
    private static function mirrorPackage(string $entryUrl, string $gap = 'no_feed'): array
    {
        return [
            'lock' => ['name' => 'composer/ca-bundle', 'version' => '1.5.0', 'notification-url' => self::MIRROR.'/downloads/', 'source' => ['type' => 'git', 'url' => 'https://github.com/composer/ca-bundle.git', 'reference' => 'x']],
            'meta' => self::metadata('composer/ca-bundle', '1.5.0', 'https://github.com/composer/ca-bundle.git', self::MIRROR),
            'gap' => $gap,
            'accept' => [['check' => 'advisories', 'repository' => $entryUrl, 'reason' => self::MIRROR_REASON]],
            'now' => self::NOW,
            'allowlist' => [],
        ];
    }

    /** @return array<string, mixed> */
    private static function registryPackage(string $entryUrl): array
    {
        return [
            'lock' => ['name' => 'acme/private', 'version' => '1.0.0', 'notification-url' => 'https://repo.packagist.com/acme/downloads/'],
            'meta' => self::metadata('acme/private', '1.0.0', null, 'https://repo.packagist.com/acme/'),
            'gap' => 'no_feed',
            'accept' => [['check' => 'advisories', 'repository' => $entryUrl, 'reason' => 'private packagist']],
            'now' => self::NOW,
            'allowlist' => [],
        ];
    }

    /**
     * @param list<array<string, string>> $accept
     * @param list<AllowlistEntry>        $allowlist
     *
     * @return array<string, mixed>
     */
    private static function vcsFork(array $accept, array $allowlist = []): array
    {
        return [
            'lock' => ['name' => 'tobscure/json-api', 'version' => 'dev-joomla-backports', 'source' => ['type' => 'git', 'url' => 'https://github.com/joomla-backports/json-api-php.git', 'reference' => 'x']],
            'meta' => null,
            'gap' => 'not_from_composer_repository',
            'accept' => $accept,
            'now' => self::NOW,
            'allowlist' => $allowlist,
        ];
    }

    /**
     * @param array<string, string> $accept
     *
     * @return array<string, mixed>
     */
    private static function bowerAsset(array $accept): array
    {
        return [
            'lock' => ['name' => 'bower-asset/jquery', 'version' => '3.7.1', 'dist' => ['type' => 'zip', 'url' => 'https://asset-packagist.org/jquery.zip']],
            'meta' => self::metadata('bower-asset/jquery', '3.7.1', null, 'https://asset-packagist.org'),
            'gap' => 'no_feed',
            'accept' => [$accept],
            'now' => self::NOW,
            'allowlist' => [],
        ];
    }

    private static function metadata(string $name, string $version, ?string $source, ?string $composerUrl): PackageMetadata
    {
        $loaded = (new ArrayLoader())->load(['name' => $name, 'version' => $version, 'time' => '2026-06-01T00:00:00+00:00'] + ($source === null ? [] : ['source' => ['type' => 'git', 'url' => $source, 'reference' => 'x']]));
        self::assertInstanceOf(BasePackage::class, $loaded);

        return PackageMetadata::fromPackages($name, [$loaded], new \DateTimeImmutable(self::NOW))->withComposerRepositoryUrl($composerUrl);
    }

    /**
     * The model's value of a case's finding field.
     *
     * @param list<string> $path
     *
     * @return array<mixed, mixed>
     */
    private static function model(string $id, array $path): array
    {
        foreach (JsonPath::arrayAt(JsonPath::decodeFile(__DIR__.'/../../fixtures/flags/cases.json'), ['cases']) as $case) {
            if (\is_array($case) && $case['id'] === $id) {
                return JsonPath::arrayAt($case, array_merge(['finding'], $path));
            }
        }
        self::fail('no case '.$id);
    }

    /**
     * @param array<string, mixed> $inputs
     *
     * @return array<mixed, mixed> the package's finding as report-2 writes it
     */
    private static function written(array $inputs, string $failOn): array
    {
        return JsonPath::arrayAt(JsonPath::decoded(self::report($inputs, $failOn)->toArray()), ['findings', 0]);
    }

    /** @param array<string, mixed> $inputs */
    private static function report(array $inputs, string $failOn): Report
    {
        /** @var array<string, mixed> $lock */
        $lock = $inputs['lock'];
        self::assertIsString($lock['name']);
        self::assertIsString($inputs['now']);
        $name = $lock['name'];
        $clock = Clock::fixed($inputs['now']);
        $auth = ForgeAuth::withTokens(new Tokens('t', null));
        $meta = $inputs['meta'];
        $github = 'https://api.github.com/repos/composer/ca-bundle';
        /** @var list<AllowlistEntry> $allowlist */
        $allowlist = $inputs['allowlist'];
        $analyzer = (new Analyzer(
            self::loader($meta instanceof PackageMetadata ? [$name => $meta] : []),
            new ActivityClient(new FakeHttpClient([$github => FakeHttpClient::ok($github, '{"archived":false,"pushed_at":"2026-09-01T00:00:00Z"}')]), $auth),
            new ActivityFetchPlanner($auth),
            new RepoLocator(),
            new Allowlist($allowlist),
            SignalSet::default($clock, new Thresholds(), '8.4', PhpReleaseDates::load()),
            $clock,
            false,
            self::advisories($name, \is_string($inputs['gap']) ? $inputs['gap'] : null)
        ))->withUncheckedAcceptance(UncheckedAcceptance::fromConfig($inputs['accept']));
        $report = $analyzer->analyze(LockFile::fromArray(['packages' => [$lock]]), ProjectConfig::fromArray(['require' => [$name => '*']]), false);

        return $report->withRun(new RunSettings(null, null, '8.4', RunSettings::SOURCE_OPTION, null, FailOn::parse($failOn), RunSettings::SOURCE_OPTION, new Thresholds()));
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

    /** The advisory lookup of one package: answered with no advisory, or not checked for $gap. */
    private static function advisories(string $package, ?string $gap): AdvisoryLoaderInterface
    {
        $repository = 'composer repo ('.self::MIRROR.')';
        $feeds = $gap === null ? [AdvisoryFeed::answered($repository, 0)] : [];
        $repositories = [$gap === null ? AdvisoryRepository::of($repository, AdvisoryRepository::ANSWERED)->withRecords(0, 0) : AdvisoryRepository::of($repository, AdvisoryRepository::NOT_ASKED, $gap)];
        $coverage = new AdvisoryCoverage(AdvisoryCoverage::SCOPE_ALL, AdvisoryCoverage::SOURCE_DEFAULT, $repositories, 0, [$package => new AdvisoryNameCoverage($feeds, $gap === null ? 0 : null, $gap)]);
        $batch = new AdvisoryBatch([], [], [], [], $coverage, $gap === null);

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
}
