<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Composer\Package\BasePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Semver\VersionParser;
use Lockrot\Allowlist\Allowlist;
use Lockrot\Allowlist\AllowlistEntry;
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
 * writes for it. The loaders answer from the case's `inputs` block, which
 * tools/schema/project_fixtures.py writes (`inputs_of`). Only the case's package and the measured
 * dependents have metadata, and a null `releases` leaves the package without it. A test can add
 * `other_advisories`, the advisories of the package's other versions, and `accept`, the flags that
 * an allowlist entry for the package accepts.
 */
final class CaseHydrator
{
    public const CASES = __DIR__.'/../fixtures/flags/cases.json';
    private const PROVENANCE = __DIR__.'/../fixtures/flags/provenance.json';
    private const PACKAGIST = 'https://packagist.org/downloads/';

    /** @var array<string, array<mixed, mixed>>|null */
    private static ?array $findings = null;
    /** @var array<mixed, mixed>|null the committed cases, read once per process */
    private static ?array $cases = null;

    /**
     * @return array<mixed, mixed> the case with that id
     */
    public static function case(string $id): array
    {
        self::$cases ??= JsonPath::arrayAt(JsonPath::decodeFile(self::CASES), ['cases']);
        foreach (self::$cases as $case) {
            if (\is_array($case) && ($case['id'] ?? null) === $id) {
                return $case;
            }
        }
        throw new \InvalidArgumentException('no case '.$id.' in '.self::CASES);
    }

    /**
     * The case package's finding as report-2 writes it, for every case with an `inputs` block, run
     * once per process.
     *
     * @return array<string, array<mixed, mixed>> by case id
     */
    public static function findings(): array
    {
        if (self::$findings !== null) {
            return self::$findings;
        }
        self::$findings = [];
        foreach (JsonPath::arrayAt(JsonPath::decodeFile(self::CASES), ['cases']) as $case) {
            if (!\is_array($case) || !\is_array($case['inputs'] ?? null)) {
                continue;
            }
            $package = JsonPath::stringAt($case, ['finding', 'package']);
            foreach (JsonPath::arrayAt(JsonPath::decoded(self::report($case)->toArray()), ['findings']) as $finding) {
                if (\is_array($finding) && $finding['package'] === $package) {
                    self::$findings[JsonPath::stringAt($case, ['id'])] = $finding;
                }
            }
        }

        return self::$findings;
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
        $marking = \is_array($inputs['abandoned'] ?? null) ? $inputs['abandoned'] : null;
        $lock = self::lock($entries, $activity, $marking);
        /** @var array<string, mixed> $root */
        $root = JsonPath::arrayAt($inputs, ['root']);
        $project = ProjectConfig::fromArray($root);
        $clock = Clock::fixed(JsonPath::stringAt(JsonPath::decodeFile(self::PROVENANCE), ['source', 'model_run_date']).'T00:00:00+00:00');
        $locked = $lock->find($package);
        if ($locked === null) {
            throw new \InvalidArgumentException('the lock has no '.$package);
        }
        $byName = \is_array($inputs['releases'] ?? null) ? [$package => PackageMetadata::fromPackages($package, self::releases($package, $inputs['releases'], $marking), $clock->now(), $locked->normalizedVersion())] : [];
        foreach (\is_array($inputs['dependents'] ?? null) ? $inputs['dependents'] : [] as $name => $releases) {
            $dependent = $lock->find((string) $name);
            self::assertArray($releases);
            if ($dependent !== null) {
                $byName[(string) $name] = PackageMetadata::fromPackages((string) $name, self::releases((string) $name, $releases, null), $clock->now(), $dependent->normalizedVersion());
            }
        }
        $auth = ForgeAuth::withTokens(new Tokens('recorded', null));
        $targetPhp = JsonPath::stringAt($run, ['target_php']);
        $projectPhp = \is_string($run['project_php'] ?? null) ? $run['project_php'] : null;
        $analyzer = new Analyzer(
            self::metadataLoader(new MetadataBatch($byName, [], [])),
            new ActivityClient(new FakeHttpClient(self::activityAnswers($activity)), $auth),
            new ActivityFetchPlanner($auth),
            new RepoLocator(),
            BuiltinAllowlist::load(),
            SignalSet::default($clock, new Thresholds(), $targetPhp, PhpReleaseDates::load(), $projectPhp),
            new VerdictEngine(),
            $clock,
            false,
            self::advisoryLoader($package, self::advisories(\is_array($inputs['advisories'] ?? null) ? $inputs['advisories'] : []), self::advisories(\is_array($inputs['other_advisories'] ?? null) ? $inputs['other_advisories'] : []), \is_string($run['advisories_unchecked'] ?? null) ? $run['advisories_unchecked'] : null)
        );

        if (\is_array($inputs['accept'] ?? null)) {
            $accept = array_values(array_filter($inputs['accept'], 'is_string'));
            $analyzer = $analyzer->withAllowlist($analyzer->allowlist()->merge(new Allowlist([new AllowlistEntry($package, null, 'accepted by the test', null, AllowlistEntry::BY_PROJECT, $accept)])));
        }

        return $analyzer->analyze($lock, $project, ($run['include_dev'] ?? false) === true);
    }

    /**
     * @param array<mixed, mixed>      $entries
     * @param array<mixed, mixed>|null $activity
     * @param array<mixed, mixed>|null $marking  S1's marking: `by` the repository or the lock, and the replacement
     */
    private static function lock(array $entries, ?array $activity, ?array $marking): LockFile
    {
        $packages = [];
        $dev = [];
        foreach (array_values($entries) as $i => $entry) {
            self::assertArray($entry);
            $require = \is_array($entry['require'] ?? null) ? $entry['require'] : [];
            if (\is_string($entry['php'] ?? null)) {
                $require = ['php' => $entry['php']] + $require;
            }
            $locked = ['name' => $entry['name'], 'version' => $entry['version'], 'require' => $require] + array_intersect_key($entry, ['conflict' => true]);
            if ($i === 0 && $marking !== null && ($marking['by'] ?? null) === 'lock') {
                $locked['abandoned'] = $marking['replacement'] ?? true;
            }
            if (\is_string($entry['time'] ?? null)) {
                $locked['time'] = $entry['time'];
            }
            if (\in_array($entry['origin'] ?? 'packagist', ['packagist', 'composer'], true)) {
                $locked['notification-url'] = self::PACKAGIST;
            }
            if (($entry['origin'] ?? null) === 'path') {
                $locked['dist'] = ['type' => 'path', 'url' => '../'.JsonPath::stringAt($entry, ['name'])];
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
     * @param array<mixed, mixed>      $releases
     * @param array<mixed, mixed>|null $marking  S1's marking: a registry marking marks every release
     *
     * @return list<BasePackage>
     */
    private static function releases(string $package, array $releases, ?array $marking): array
    {
        $versions = [];
        foreach ($releases as $release) {
            self::assertArray($release);
            // A null time is an undated release: Composer's loader reads one without a `time` key.
            $versions[] = (new ArrayLoader())->load([
                'name' => $package,
                'version' => JsonPath::stringAt($release, ['version']),
                'require' => (\is_string($release['php'] ?? null) ? ['php' => $release['php']] : []) + (\is_array($release['require'] ?? null) ? $release['require'] : []),
            ] + (\is_string($release['time'] ?? null) ? ['time' => $release['time']] : [])
              + ($marking !== null && ($marking['by'] ?? null) === 'repository' ? ['abandoned' => $marking['replacement'] ?? true] : []));
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

    /**
     * @param list<Advisory> $advisories the counted ones
     * @param list<Advisory> $others     those on other versions of the package
     * @param ?string        $unchecked  why the lookup did not run (`offline`), null when it answered
     */
    private static function advisoryLoader(string $package, array $advisories, array $others, ?string $unchecked): AdvisoryLoaderInterface
    {
        $feed = $unchecked === null
            ? ['composer_repository' => 'packagist.org', 'answer' => AdvisoryCoverage::ANSWERED, 'reason' => null, 'message' => null, 'records' => \count($advisories)]
            : ['composer_repository' => 'packagist.org', 'answer' => 'not_asked', 'reason' => $unchecked, 'message' => null, 'records' => null];
        $coverage = new AdvisoryCoverage(AdvisoryCoverage::SCOPE_ALL, AdvisoryCoverage::SOURCE_DEFAULT, [], 0, [$package => new AdvisoryNameCoverage([$feed], $feed['records'], $unchecked)]);
        $every = array_merge($advisories, $others);
        $batch = new AdvisoryBatch($advisories === [] ? [] : [$package => $advisories], [], [], $every === [] ? [] : [$package => $every], $coverage, $unchecked === null);

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
