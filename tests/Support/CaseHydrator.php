<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Composer\Package\BasePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Semver\VersionParser;
use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Analyzer\Report;
use Lockrot\Clock;
use Lockrot\Data\Advisory\Advisory;
use Lockrot\Data\Advisory\AdvisoryBatch;
use Lockrot\Data\Advisory\AdvisoryCoverage;
use Lockrot\Data\Advisory\AdvisoryLoaderInterface;
use Lockrot\Data\Advisory\AdvisoryNameCoverage;
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
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Verdict\VerdictEngine;

/**
 * Turns a case of tests/fixtures/flags/cases.json into the report that the real {@see Analyzer}
 * writes for it. The case's `inputs` block (tools/schema/project_fixtures.py) holds the root
 * `composer.json`, the lock entries with the case's package first, that package's releases with
 * their dates and `php`, its advisories, its repository activity and the run settings. The loaders
 * answer from the block: a package other than the case's own has no metadata.
 */
final class CaseHydrator
{
    public const CASES = __DIR__.'/../fixtures/flags/cases.json';
    private const PROVENANCE = __DIR__.'/../fixtures/flags/provenance.json';
    private const PACKAGIST = 'https://packagist.org/downloads/';

    /**
     * @return array<mixed, mixed> the case with that id
     */
    public static function case(string $id): array
    {
        foreach (JsonPath::arrayAt(JsonPath::decodeFile(self::CASES), ['cases']) as $case) {
            if (\is_array($case) && ($case['id'] ?? null) === $id) {
                return $case;
            }
        }
        throw new \InvalidArgumentException('no case '.$id.' in '.self::CASES);
    }

    /**
     * @param array<mixed, mixed> $case
     *
     * @throws \InvalidArgumentException for a case without an `inputs` block
     */
    public static function report(array $case): Report
    {
        $inputs = $case['inputs'] ?? null;
        if (!\is_array($inputs)) {
            throw new \InvalidArgumentException('case '.JsonPath::stringAt($case, ['id']).' has no inputs block: check it for invariants only');
        }
        $run = JsonPath::arrayAt($inputs, ['run']);
        $entries = JsonPath::arrayAt($inputs, ['lock']);
        $package = JsonPath::stringAt($entries, [0, 'name']);
        $activity = \is_array($inputs['activity'] ?? null) ? $inputs['activity'] : null;
        $lock = self::lock($entries, $activity);
        /** @var array<string, mixed> $root */
        $root = JsonPath::arrayAt($inputs, ['root']);
        $project = ProjectConfig::fromArray($root);
        $clock = Clock::fixed(JsonPath::stringAt(JsonPath::decodeFile(self::PROVENANCE), ['source', 'model_run_date']).'T00:00:00+00:00');
        $locked = $lock->find($package);
        if ($locked === null) {
            throw new \InvalidArgumentException('the lock has no '.$package);
        }
        $metadata = PackageMetadata::fromPackages($package, self::releases($package, JsonPath::arrayAt($inputs, ['releases'])), $clock->now(), $locked->normalizedVersion());
        $auth = ForgeAuth::withTokens(new Tokens('recorded', null));
        $targetPhp = JsonPath::stringAt($run, ['target_php']);
        $projectPhp = \is_string($run['project_php'] ?? null) ? $run['project_php'] : null;
        $analyzer = new Analyzer(
            self::metadataLoader(new MetadataBatch([$package => $metadata], [], [])),
            new ActivityClient(new FakeHttpClient(self::activityAnswers($activity)), $auth),
            new ActivityFetchPlanner($auth),
            new RepoLocator(),
            BuiltinAllowlist::load(),
            SignalSet::default($clock, new Thresholds(), $targetPhp, PhpReleaseDates::load(), $projectPhp),
            new VerdictEngine(),
            $clock,
            false,
            self::advisoryLoader($package, self::advisories(\is_array($inputs['advisories'] ?? null) ? $inputs['advisories'] : []))
        );

        return $analyzer->analyze($lock, $project, ($run['include_dev'] ?? false) === true);
    }

    /**
     * @param array<mixed, mixed>       $entries
     * @param array<mixed, mixed>|null  $activity
     */
    private static function lock(array $entries, ?array $activity): LockFile
    {
        $packages = [];
        $dev = [];
        foreach (array_values($entries) as $i => $entry) {
            self::assertArray($entry);
            $require = \is_array($entry['require'] ?? null) ? $entry['require'] : [];
            if (\is_string($entry['php'] ?? null)) {
                $require = ['php' => $entry['php']] + $require;
            }
            $locked = ['name' => $entry['name'], 'version' => $entry['version'], 'require' => $require];
            if (\is_string($entry['time'] ?? null)) {
                $locked['time'] = $entry['time'];
            }
            if (\in_array($entry['origin'] ?? 'packagist', ['packagist', 'composer'], true)) {
                $locked['notification-url'] = self::PACKAGIST;
            }
            if ($i === 0 && $activity !== null) {
                $locked['source'] = ['type' => 'git', 'url' => 'https://'.JsonPath::stringAt($activity, ['host']).'/'.JsonPath::stringAt($activity, ['repo']).'.git', 'reference' => 'case'];
            }
            if (($entry['dev'] ?? false) === true) {
                $dev[] = $locked;
            } else {
                $packages[] = $locked;
            }
        }

        return LockFile::fromArray(['packages' => $packages, 'packages-dev' => $dev]);
    }

    /**
     * @param array<mixed, mixed> $releases
     *
     * @return list<BasePackage>
     */
    private static function releases(string $package, array $releases): array
    {
        $versions = [];
        foreach ($releases as $release) {
            self::assertArray($release);
            // A null time is an undated release: Composer's loader reads one without a `time` key.
            $versions[] = (new ArrayLoader())->load([
                'name' => $package,
                'version' => JsonPath::stringAt($release, ['version']),
                'require' => \is_string($release['php'] ?? null) ? ['php' => $release['php']] : [],
            ] + (\is_string($release['time'] ?? null) ? ['time' => $release['time']] : []));
        }

        return $versions;
    }

    /**
     * @param array<mixed, mixed> $records
     *
     * @return list<Advisory>
     */
    private static function advisories(array $records): array
    {
        $parser = new VersionParser();
        $advisories = [];
        foreach ($records as $record) {
            self::assertArray($record);
            $advisories[] = new Advisory(
                JsonPath::stringAt($record, ['id']),
                self::optional($record, 'cve'),
                self::optional($record, 'title'),
                self::optional($record, 'link'),
                self::optional($record, 'severity'),
                \is_string($record['reported_at'] ?? null) ? new \DateTimeImmutable($record['reported_at']) : null,
                \is_string($record['affected_versions'] ?? null) ? $parser->parseConstraints($record['affected_versions']) : null
            );
        }

        return $advisories;
    }

    /**
     * @param array<mixed, mixed>|null $activity
     *
     * @return array<string, \Lockrot\Data\Http\HttpResult>
     */
    private static function activityAnswers(?array $activity): array
    {
        if ($activity === null || ($activity['host'] ?? null) !== 'github.com') {
            return [];
        }
        $url = 'https://api.github.com/repos/'.JsonPath::stringAt($activity, ['repo']);

        return [$url => FakeHttpClient::ok($url, (string) json_encode(['archived' => $activity['archived'] ?? false, 'pushed_at' => $activity['pushed_at'] ?? null]))];
    }

    private static function metadataLoader(MetadataBatch $batch): MetadataLoaderInterface
    {
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

    /** @param list<Advisory> $advisories */
    private static function advisoryLoader(string $package, array $advisories): AdvisoryLoaderInterface
    {
        $coverage = new AdvisoryCoverage(AdvisoryCoverage::SCOPE_ALL, AdvisoryCoverage::SOURCE_DEFAULT, [], 0, [$package => new AdvisoryNameCoverage([['composer_repository' => 'packagist.org', 'answer' => AdvisoryCoverage::ANSWERED, 'reason' => null, 'message' => null, 'records' => \count($advisories)]], \count($advisories), null)]);
        $batch = new AdvisoryBatch($advisories === [] ? [] : [$package => $advisories], [], [], [], $coverage, true);

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

    /** @param array<mixed, mixed> $row */
    private static function optional(array $row, string $key): ?string
    {
        return \is_string($row[$key] ?? null) ? $row[$key] : null;
    }

    /**
     * @param mixed $value
     *
     * @phpstan-assert array<string, mixed> $value
     */
    private static function assertArray($value): void
    {
        if (!\is_array($value)) {
            throw new \UnexpectedValueException('an inputs entry is not an object');
        }
    }
}
