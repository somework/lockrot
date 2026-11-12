<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Composer\Downloader\TransportException;
use Composer\Json\JsonFile;
use Composer\Package\BasePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Locker;
use Composer\Repository\PlatformRepository;
use Composer\Semver\VersionParser;
use Lockrot\Allowlist\Allowlist;
use Lockrot\Allowlist\AllowlistEntry;
use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analysis;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Analyzer\PhpWindow;
use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunNote;
use Lockrot\Analyzer\RunSettings;
use Lockrot\Clock;
use Lockrot\Data\Abandoned\AbandonedIgnore;
use Lockrot\Data\Abandoned\AbandonedIgnoreRule;
use Lockrot\Data\Advisory\Advisory;
use Lockrot\Data\Advisory\AdvisoryBatch;
use Lockrot\Data\Advisory\AdvisoryCoverage;
use Lockrot\Data\Advisory\AdvisoryFeed;
use Lockrot\Data\Advisory\AdvisoryIgnoreMatch;
use Lockrot\Data\Advisory\AdvisoryLoaderInterface;
use Lockrot\Data\Advisory\AdvisoryNameCoverage;
use Lockrot\Data\Advisory\AdvisoryRepository;
use Lockrot\Data\Advisory\IgnoredAdvisory;
use Lockrot\Data\Forge\ActivityClient;
use Lockrot\Data\Forge\ActivityFetchPlanner;
use Lockrot\Data\Forge\BitbucketApi;
use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\GitHubApi;
use Lockrot\Data\Forge\GitLabApi;
use Lockrot\Data\Forge\RepoLocator;
use Lockrot\Data\Forge\RepoRef;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Data\Http\HttpResult;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\MetadataBatch;
use Lockrot\Data\Repository\MetadataLoaderInterface;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Explain\Explanation;
use Lockrot\Json\JsonReader;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Signal\Rule\NotCheckedRule;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Solver\MoveCheck;
use Lockrot\Solver\RepositoryVersions;
use Lockrot\Tests\Support\Contract\CaseRecording;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\MoveChecks;

/**
 * Turns a case of tests/fixtures/flags/cases.json into the report that the real {@see Analyzer}
 * writes for it. The loaders answer from the case's `inputs` block, which
 * tools/schema/project_fixtures.py writes (`inputs_of`). Only the case's package and the measured
 * dependents have metadata, and a null `releases` leaves the package without it. `accept` holds the
 * flags that a project allowlist entry for the package accepts, null for every flag, and
 * `allowlist` the entry's pattern, version, reason and expiry date. `records` counts
 * the feeds' advisories of the name on every version. A test can add `other_advisories`, the
 * advisories of the package's other versions.
 */
final class CaseHydrator
{
    public const CASES = __DIR__.'/../fixtures/flags/cases.json';
    private const DOCUMENTS = __DIR__.'/../fixtures/schema/documents/cases/';
    private const COMPOSITE = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
    private const COMPOSITE_FILE = __DIR__.'/../fixtures/flags/composite.json';
    private const PACKAGIST = 'https://packagist.org/downloads/';

    /** @var array<string, array<mixed, mixed>>|null */
    private static ?array $findings = null;
    /** @var array<string, list<array<mixed, mixed>>> the branch rows of each finding, by case id */
    private static array $rows = [];
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
            $id = JsonPath::stringAt($case, ['id']);
            $package = JsonPath::stringAt($case, ['finding', 'package']);
            $analysis = self::analysis($case);
            foreach (JsonPath::arrayAt(JsonPath::decoded($analysis->report()->toArray()), ['findings']) as $finding) {
                if (\is_array($finding) && $finding['package'] === $package) {
                    self::$findings[$id] = $finding;
                }
            }
            self::$rows[$id] = self::branchRowsOf($analysis, $package, JsonPath::arrayAt($case, ['inputs', 'run']));
        }

        return self::$findings;
    }

    /**
     * The branch rows that `--explain` writes for the case's package, for every case with an
     * `inputs` block.
     *
     * @return array<string, list<array<mixed, mixed>>> by case id
     */
    public static function branchRows(): array
    {
        self::findings();

        return self::$rows;
    }

    /**
     * @param array<mixed, mixed> $run
     *
     * @return list<array<mixed, mixed>>
     */
    private static function branchRowsOf(Analysis $analysis, string $package, array $run): array
    {
        $finding = $analysis->finding($package);
        $facts = $analysis->facts($package);
        if ($finding === null || $facts === null) {
            return [];
        }
        $projectPhp = \is_string($run['project_php'] ?? null) ? $run['project_php'] : null;
        $explanation = JsonPath::decoded((new Explanation($finding, $facts, new Thresholds(), JsonPath::stringAt($run, ['target_php']), $analysis->report(), $projectPhp))->toArray());
        $metadata = $explanation['metadata'] ?? null;
        $rows = [];
        foreach (\is_array($metadata) && \is_array($metadata['branches'] ?? null) ? $metadata['branches'] : [] as $row) {
            self::assertArray($row);
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The composite of tests/fixtures/flags/composite.json in one report, at `--fail-on=high` against
     * target PHP 8.4 (`--target-php`). It holds the case finding of each of the cases A to G and every
     * other finding of the composite. Each finding comes from the lock of the first case that reaches
     * it. The advisory lookup sums the records of each repository.
     */
    public static function composite(): Report
    {
        $packages = [];
        foreach (JsonPath::arrayAt(JsonPath::decodeFile(self::COMPOSITE_FILE), ['findings']) as $finding) {
            $packages[] = JsonPath::stringAt(JsonPath::decoded($finding), ['package']);
        }

        return self::ofCases(self::COMPOSITE, $packages);
    }

    /**
     * The composer.lock that the lock entries of the cases make, laid out as Composer writes it. A
     * package that an earlier case already holds is left out. The SARIF and annotation formats read
     * each package's line from it.
     *
     * @param list<string> $ids the case ids, the composite's when empty
     */
    public static function lockJson(array $ids = []): string
    {
        $lock = ['packages' => [], 'packages-dev' => []];
        $seen = [];
        foreach ($ids === [] ? self::COMPOSITE : $ids as $id) {
            $inputs = JsonPath::arrayAt(self::case($id), ['inputs']);
            $packages = self::packages($inputs);
            $data = self::lockData(self::entries($inputs, $packages), $packages, $inputs);
            foreach (['packages' => $data['packages'], 'packages-dev' => $data['packages-dev'] ?? []] as $section => $packages) {
                foreach ($packages as $package) {
                    $name = JsonPath::stringAt($package, ['name']);
                    if (!isset($seen[$name])) {
                        $seen[$name] = true;
                        $lock[$section][] = $package;
                    }
                }
            }
        }

        return JsonFile::encode($lock)."\n";
    }

    /**
     * The case finding of every case with an `inputs` block in one report, as {@see composite()}
     * builds it. A package that an earlier case already holds is left out.
     */
    public static function everyCase(): Report
    {
        return self::ofCases(array_map('strval', array_keys(self::findings())));
    }

    /**
     * @param list<string> $ids
     * @param list<string> $others the other packages that the report holds, each taken from the
     *                             first case whose lock gives it a finding
     */
    private static function ofCases(array $ids, array $others = []): Report
    {
        $findings = [];
        $records = [];
        $oldest = null;
        $generatedAt = self::report(self::case('A'))->generatedAt();
        foreach ($ids as $id) {
            $case = self::case($id);
            $report = self::report($case);
            $package = JsonPath::stringAt($case, ['finding', 'package']);
            $held = [];
            foreach ($report->findings() as $finding) {
                $name = $finding->package();
                if (!isset($findings[$name]) && ($name === $package || \in_array($name, $others, true))) {
                    $findings[$name] = $finding;
                    $held[] = $finding;
                }
            }
            if ($held === []) {
                continue;
            }
            // The report counts the records of the findings it holds, not those of the case lock's other packages.
            foreach ($held as $finding) {
                $named = $finding->details()->advisoryLookup();
                $count = $named === null ? 0 : (int) $named->records();
                foreach ($report->advisoryLookup()->repositories() as $repository) {
                    $name = $repository->composerRepository();
                    $records[$name] = [($records[$name][0] ?? 0) + $count, ($records[$name][1] ?? 0) + ($count === 0 ? 0 : 1)];
                }
            }
            $at = $report->activityCacheOldestAt();
            $oldest = $at !== null && ($oldest === null || $at < $oldest) ? $at : $oldest;
        }
        $repositories = [];
        foreach ($records as $name => [$count, $packages]) {
            $repositories[] = AdvisoryRepository::of($name, AdvisoryRepository::ANSWERED)->withRecords($count, $packages);
        }
        $coverage = new AdvisoryCoverage('all', 'default', $repositories, 0, []);
        $run = new RunSettings(null, null, '8.4', RunSettings::SOURCE_OPTION, null, FailOn::parse('high'), RunSettings::SOURCE_OPTION, null);
        // The window reads the lock that the cases make together, as lockJson() writes it.
        $lock = json_decode(self::lockJson($ids), true, 512, \JSON_THROW_ON_ERROR);
        self::assertArray($lock);
        $window = PhpWindow::of(LockFile::fromArray(JsonReader::stringKeyed($lock)), ProjectConfig::fromArray([]), '8.4', PhpReleaseDates::load()->minors(new Clock($generatedAt)));

        return (new Report(array_values($findings), [], $generatedAt, \count($findings), 0, null, $oldest, false, $coverage))->withRun($run)->withPhpWindow($window === null ? null : $window->withTargetFix(null));
    }

    /**
     * @param array<mixed, mixed> $case
     *
     * @throws \InvalidArgumentException for a case without an `inputs` block
     */
    public static function report(array $case): Report
    {
        return self::analysis($case)->report();
    }

    /**
     * The analysis of the case, its facts included, for a test that explains its package.
     *
     * @param array<mixed, mixed> $case
     *
     * @throws \InvalidArgumentException for a case without an `inputs` block
     */
    public static function analysisOf(array $case): Analysis
    {
        return self::analysis($case);
    }

    /**
     * The move checks that a case's `inputs.checks` records, or null when it records none.
     *
     * @param array<mixed, mixed> $inputs a case's `inputs` block
     * @param list<string>|null   $locked the names that the case's lock holds, null to keep every holder
     */
    public static function moveChecksOf(array $inputs, ?array $locked = null): ?MoveChecks
    {
        if (!\is_array($inputs['checks'] ?? null)) {
            return null;
        }
        /** @var array<string, array<mixed, mixed>> $checks */
        $checks = $inputs['checks'];

        $run = \is_array($inputs['move_check'] ?? null) ? $inputs['move_check'] : [];
        $world = \is_array($inputs['solver_world'] ?? null) && \is_array($inputs['solver_world']['world'] ?? null) ? $inputs['solver_world']['world'] : null;

        // A case with a `solver_world` records every request, so another request fails the test. A world whose vcs
        // repository serves a name of the lock, or a name that a release needs and no Composer repository lists, records
        // no request of a move: lockrot reads that move as unloaded_name.
        $unloaded = $world !== null && (self::shadowsALockedName($world) || self::needsAnUnlistedName($world));
        if ($world !== null && (($checks['baseline'] ?? [])['result'] ?? null) === MoveCheck::REFUSED && self::leavesARefusedBaselineUnchecked($world)) {
            $checks['baseline'] = ['result' => MoveCheck::NOT_CHECKED, 'form' => null, 'reason' => MoveCheck::UNLOADED_NAME, 'installs' => null, 'holds' => [], 'problem' => null];
        }
        $checker = new RecordedMoveChecker($checks, $run, $world !== null && !$unloaded);

        return MoveChecks::of($locked === null ? $checker : $checker->withLocked($locked));
    }

    /**
     * The oracle records Composer's refusal of the baseline. The model keeps it only when the world's
     * lock holds every root requirement, else a name that stays unloaded leaves the baseline
     * `not_checked` with `unloaded_name`. This reading uses Composer's loader, not lockrot's solver.
     *
     * @param array<mixed, mixed> $world a case's `inputs.solver_world.world`
     */
    public static function leavesARefusedBaselineUnchecked(array $world): bool
    {
        return (self::shadowsALockedName($world) || self::needsAnUnlistedName($world)) && !self::locksEveryRootRequirement($world);
    }

    /**
     * A non-dev locked package or the root holds a `require` name, and any locked package or the root
     * a `require-dev` name: by its own name or a `replace` at any version, by a `provide` that the
     * link admits.
     *
     * @param array<mixed, mixed> $world a case's `inputs.solver_world.world`
     */
    private static function locksEveryRootRequirement(array $world): bool
    {
        $loader = new ArrayLoader();
        $root = $loader->load((\is_array($world['root'] ?? null) ? $world['root'] : []) + ['name' => '__root__', 'version' => '1.0.0']);
        $lock = \is_array($world['lock'] ?? null) ? $world['lock'] : [];
        $nonDev = [$root];
        foreach (\is_array($lock['packages'] ?? null) ? $lock['packages'] : [] as $entry) {
            $nonDev[] = $loader->load(\is_array($entry) ? $entry : []);
        }
        $all = $nonDev;
        foreach (\is_array($lock['packages-dev'] ?? null) ? $lock['packages-dev'] : [] as $entry) {
            $all[] = $loader->load(\is_array($entry) ? $entry : []);
        }
        foreach ([[$root->getRequires(), $nonDev], [$root->getDevRequires(), $all]] as [$links, $holders]) {
            foreach ($links as $link) {
                if (PlatformRepository::isPlatformPackage($link->getTarget())) {
                    continue;
                }
                $held = false;
                foreach ($holders as $holder) {
                    $provided = $holder->getProvides()[$link->getTarget()] ?? null;
                    $held = $held || $holder->getName() === $link->getTarget() || isset($holder->getReplaces()[$link->getTarget()])
                        || ($provided !== null && $provided->getConstraint()->matches($link->getConstraint()));
                }
                if (!$held) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param array<mixed, mixed> $world a case's `inputs.solver_world.world` */
    private static function needsAnUnlistedName(array $world): bool
    {
        if (!\is_array($world['vcs_repos'] ?? null) || $world['vcs_repos'] === []) {
            return false;
        }
        $repo = \is_array($world['repo'] ?? null) ? $world['repo'] : [];
        $listed = array_keys($repo);
        $lock = \is_array($world['lock'] ?? null) ? $world['lock'] : [];
        foreach (\is_array($lock['packages'] ?? null) ? $lock['packages'] : [] as $entry) {
            $listed[] = \is_array($entry) ? ($entry['name'] ?? null) : null;
        }
        foreach ($repo as $releases) {
            foreach (\is_array($releases) ? $releases : [] as $release) {
                foreach (array_keys(\is_array($release) && \is_array($release['require'] ?? null) ? $release['require'] : []) as $name) {
                    if (strpos((string) $name, '/') !== false && !\in_array($name, $listed, true)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** @param array<mixed, mixed> $world a case's `inputs.solver_world.world` */
    private static function shadowsALockedName(array $world): bool
    {
        $served = [];
        foreach (\is_array($world['vcs_repos'] ?? null) ? $world['vcs_repos'] : [] as $url) {
            $served[] = RepositoryVersions::urlKey(\is_string($url) ? $url : '');
        }
        $lock = \is_array($world['lock'] ?? null) ? $world['lock'] : [];
        foreach (['packages', 'packages-dev'] as $section) {
            foreach (\is_array($lock[$section] ?? null) ? $lock[$section] : [] as $entry) {
                foreach (['source', 'dist'] as $origin) {
                    $url = \is_array($entry) && \is_array($entry[$origin] ?? null) ? ($entry[$origin]['url'] ?? null) : null;
                    if (\is_string($url) && \in_array(RepositoryVersions::urlKey($url), $served, true)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param array<mixed, mixed> $case
     *
     * @throws \InvalidArgumentException for a case without an `inputs` block
     */
    private static function analysis(array $case): Analysis
    {
        $inputs = $case['inputs'] ?? null;
        if (!\is_array($inputs)) {
            throw new \InvalidArgumentException('case '.JsonPath::stringAt($case, ['id']).' has no inputs block: check it for invariants only');
        }
        $run = JsonPath::arrayAt($inputs, ['run']);
        $packages = self::packages($inputs);
        $package = (string) array_key_first($packages);
        $activity = $packages[$package]['activity'];
        $lockData = self::lockData(self::entries($inputs, $packages), $packages, $inputs);
        $root = self::root($inputs, $packages);
        $world = \is_array($inputs['solver_world'] ?? null) && \is_array($inputs['solver_world']['world'] ?? null) ? $inputs['solver_world']['world'] : null;
        // A solver world states its lock's hash: `lock_hash` `stale`, or no content-hash without the key.
        // A stale world reads the world's root as composer.json and hashes an empty manifest.
        $stale = $world !== null && ($world['lock_hash'] ?? null) === 'stale' && \is_array($world['root'] ?? null);
        $unlocked = $root === null || ($world !== null && !$stale) ? null : ($stale ? [] : self::unlockedRoot($root, $lockData));
        $project = $root === null ? ProjectConfig::empty() : ($unlocked === null ? ProjectConfig::fromArray($root) : self::manifest($stale ? $world['root'] : $root));
        $lock = LockFile::fromArray($unlocked === null ? $lockData : $lockData + ['content-hash' => Locker::getContentHash(JsonFile::encode($unlocked))]);
        $clock = self::clock($case);
        if ($lock->find($package) === null) {
            throw new \InvalidArgumentException('the lock has no '.$package);
        }
        $byName = [];
        $dependents = \is_array($inputs['dependents'] ?? null) ? $inputs['dependents'] : [];
        foreach ($packages as $name => $facts) {
            $locked = $lock->find($name);
            if ($facts['releases'] === null || $locked === null) {
                continue;
            }
            // A related package that is also a measured dependent keeps what each of its releases requires.
            $facts['releases'] = self::withRequires($facts['releases'], \is_array($dependents[$name] ?? null) ? $dependents[$name] : []);
            // The releases come from the registry the lock entry reports to, so a marking names it as lockrot's metadata would.
            $registry = \in_array($facts['lock']['origin'] ?? 'packagist', ['packagist', 'composer'], true) ? self::PACKAGIST : null;
            $source = $facts['activity'] === null ? null : self::sourceUrl($facts['activity']);
            $byName[$name] = PackageMetadata::fromPackages($name, self::releases($name, $facts['releases'], $facts['abandoned'], $registry, $source), $clock->now(), $locked->normalizedVersion());
            if ($facts['parent'] !== null) {
                // The monorepo's tags replace the split package, so MonorepoParents dates the split tags by them.
                $parent = JsonPath::stringAt($facts['parent'], ['name']);
                $tags = [];
                foreach (JsonPath::arrayAt($facts['parent'], ['releases']) as $tag) {
                    self::assertArray($tag);
                    $tags[] = ['replace' => [$name => 'self.version'] + (\is_array($tag['replace'] ?? null) ? $tag['replace'] : [])] + $tag;
                }
                $byName[$parent] = PackageMetadata::fromPackages($parent, self::releases($parent, $tags, null, $registry), $clock->now());
            }
        }
        foreach ($dependents as $name => $releases) {
            $dependent = $lock->find((string) $name);
            self::assertArray($releases);
            if ($dependent !== null && !isset($byName[(string) $name])) {
                $byName[(string) $name] = PackageMetadata::fromPackages((string) $name, self::releases((string) $name, $releases, null), $clock->now(), $dependent->normalizedVersion());
            }
        }
        $auth = self::auth($activity);
        $locator = self::locator(array_column($packages, 'activity'));
        $targetPhp = JsonPath::stringAt($run, ['target_php']);
        $projectPhp = \is_string($run['project_php'] ?? null) ? $run['project_php'] : null;
        $analyzer = new Analyzer(
            self::metadataLoader(new MetadataBatch($byName, [], [])),
            new ActivityClient(new FakeHttpClient(self::activityAnswers(array_column($packages, 'activity'), $locator, $auth)), $auth),
            new ActivityFetchPlanner($auth),
            $locator,
            BuiltinAllowlist::load(),
            SignalSet::default($clock, new Thresholds(), $targetPhp, PhpReleaseDates::load(), $projectPhp, $lock->platform()['php'] ?? null),
            $clock,
            ($activity['reason'] ?? null) === NotCheckedRule::OFFLINE,
            self::advisoryLoader($packages, self::advisories(\is_array($inputs['other_advisories'] ?? null) ? $inputs['other_advisories'] : []), \is_string($run['advisories_unchecked'] ?? null) ? $run['advisories_unchecked'] : null, self::advisoryFailures($run), self::advisoryAnswers($run)),
            null,
            self::abandonedIgnore(\is_array($inputs['abandoned_ignore'] ?? null) ? $inputs['abandoned_ignore'] : null)
        );

        if (\array_key_exists('accept', $inputs)) {
            $accept = \is_array($inputs['accept']) ? array_values(array_filter($inputs['accept'], 'is_string')) : null;
            $entry = \is_array($inputs['allowlist'] ?? null) ? $inputs['allowlist'] : [];
            $expires = self::optional($entry, 'expires');
            $analyzer = $analyzer->withAllowlist($analyzer->allowlist()->merge(new Allowlist([new AllowlistEntry(self::optional($entry, 'pattern') ?? $package, self::optional($entry, 'version'), self::optional($entry, 'reason') ?? 'accepted by the test', $expires === null ? null : new \DateTimeImmutable($expires), AllowlistEntry::BY_PROJECT, $accept)])));
        }

        $checks = self::moveChecksOf($inputs, array_values(array_map(static fn (BasePackage $locked): string => $locked->getName(), $lock->lockedRepository()->getPackages())));
        if ($checks !== null) {
            $passes = $inputs['check_passes'] ?? Analyzer::CHECK_PASSES;
            $analyzer = $analyzer->withMoveChecks($checks, \is_int($passes) && $passes > 0 ? $passes : Analyzer::CHECK_PASSES);
        }

        $includeDev = ($run['include_dev'] ?? false) === true;

        return $analyzer->analyzeWithFacts($lock->packages($includeDev), $lock, $project, $includeDev);
    }

    /** @param array<mixed, mixed>|null $ignore the case's `abandoned_ignore` input: Composer's list, `{by, rules[{pattern, reason, constraints}]}` */
    private static function abandonedIgnore(?array $ignore): AbandonedIgnore
    {
        if ($ignore === null) {
            return AbandonedIgnore::none();
        }
        $rules = [];
        foreach (JsonPath::arrayAt($ignore, ['rules']) as $rule) {
            self::assertArray($rule);
            $rules[] = new AbandonedIgnoreRule(JsonPath::stringAt($rule, ['pattern']), \is_string($rule['reason'] ?? null) ? $rule['reason'] : null, array_values(array_filter(JsonPath::arrayAt($rule, ['constraints']), 'is_string')));
        }

        return AbandonedIgnore::of(JsonPath::stringAt($ignore, ['by']), $rules);
    }

    /**
     * The model's clock for $case: the `generated_at` of its case document. PHP then measures every
     * years value on the model's day, and a new model run on another day moves no value. A case that
     * a test builds names its own `clock`.
     *
     * @param array<mixed, mixed> $case
     */
    public static function clock(array $case): Clock
    {
        if (\is_string($case['clock'] ?? null)) {
            return Clock::fixed($case['clock']);
        }

        return Clock::fixed(JsonPath::stringAt(JsonPath::decodeFile(self::DOCUMENTS.CaseRecording::fileName(JsonPath::stringAt($case, ['id'])).'.json'), ['generated_at']));
    }

    /**
     * The case's package first, then each package of `packages`, by name: its lock entry, its
     * releases (null when lockrot reads none), its advisories, its marking, its repository's answer,
     * and the chains that link it into the lock.
     *
     * @param array<mixed, mixed> $inputs
     *
     * @return array<string, array{lock: array<mixed, mixed>, releases: array<mixed, mixed>|null, advisories: list<Advisory>, records: ?int, ignored: list<IgnoredAdvisory>, abandoned: array<mixed, mixed>|null, activity: array<mixed, mixed>|null, parent: array<mixed, mixed>|null, chains: list<list<string>>, dev: bool}>
     */
    private static function packages(array $inputs): array
    {
        $case = JsonPath::arrayAt($inputs, ['lock', 0]);
        $packages = [JsonPath::stringAt($case, ['name']) => self::factsOf($inputs, $case)];
        foreach (\is_array($inputs['packages'] ?? null) ? $inputs['packages'] : [] as $name => $facts) {
            self::assertArray($facts);
            $packages[(string) $name] = self::factsOf($facts, JsonPath::arrayAt($facts, ['lock']));
        }

        return $packages;
    }

    /**
     * @param array<mixed, mixed> $facts
     * @param array<mixed, mixed> $entry
     *
     * @return array{lock: array<mixed, mixed>, releases: array<mixed, mixed>|null, advisories: list<Advisory>, records: ?int, ignored: list<IgnoredAdvisory>, abandoned: array<mixed, mixed>|null, activity: array<mixed, mixed>|null, parent: array<mixed, mixed>|null, chains: list<list<string>>, dev: bool}
     */
    private static function factsOf(array $facts, array $entry): array
    {
        $chains = [];
        foreach (['chain', 'below'] as $key) {
            if (\is_array($facts[$key] ?? null)) {
                $chains[] = array_values(array_filter($facts[$key], 'is_string'));
            }
        }

        return [
            'lock' => $entry,
            'releases' => \is_array($facts['releases'] ?? null) ? $facts['releases'] : null,
            'advisories' => self::advisories(\is_array($facts['advisories'] ?? null) ? $facts['advisories'] : []),
            'records' => \is_int($facts['records'] ?? null) ? $facts['records'] : null,
            'ignored' => self::ignored(\is_array($facts['ignored'] ?? null) ? $facts['ignored'] : []),
            'abandoned' => \is_array($facts['abandoned'] ?? null) ? $facts['abandoned'] : null,
            'activity' => \is_array($facts['activity'] ?? null) ? $facts['activity'] : null,
            'parent' => \is_array($facts['parent'] ?? null) ? $facts['parent'] : null,
            'chains' => $chains,
            'dev' => ($entry['dev'] ?? false) === true,
        ];
    }

    /**
     * The lock entries: the case's, each related package's, and an entry for each link of a chain
     * that reaches a related package. A chain links each package to the next unless a link exists.
     *
     * @param array<mixed, mixed>                                                                $inputs
     * @param array<string, array{lock: array<mixed, mixed>, chains: list<list<string>>, dev: bool}> $packages
     *
     * @return array<string, array<mixed, mixed>> by name, the case's package first
     */
    private static function entries(array $inputs, array $packages): array
    {
        $entries = [];
        foreach (JsonPath::arrayAt($inputs, ['lock']) as $entry) {
            self::assertArray($entry);
            $entries[JsonPath::stringAt($entry, ['name'])] = $entry;
        }
        foreach ($packages as $name => $facts) {
            $entries[$name] = $facts['lock'] + ($entries[$name] ?? []);
            foreach ($facts['chains'] as $chain) {
                foreach (\array_slice($chain, 0, -1) as $i => $parent) {
                    $entries[$parent] ??= ['name' => $parent, 'version' => '1.0.0', 'dev' => $facts['dev'], 'origin' => 'packagist'];
                    $require = \is_array($entries[$parent]['require'] ?? null) ? $entries[$parent]['require'] : [];
                    $entries[$parent]['require'] = $require + [$chain[$i + 1] => '*'];
                }
            }
        }

        return $entries;
    }

    /**
     * The root composer.json, with the head of each chain that reaches a related package, null for
     * a run that read none.
     *
     * @param array<mixed, mixed>                                                 $inputs
     * @param array<string, array{chains: list<list<string>>, dev: bool}> $packages
     *
     * @return array<string, mixed>|null
     */
    private static function root(array $inputs, array $packages): ?array
    {
        /** @var array<string, mixed>|null $root */
        $root = \is_array($inputs['root'] ?? null) ? $inputs['root'] : null;
        if ($root === null) {
            return null;
        }
        foreach (\array_slice($packages, 1) as $facts) {
            $head = $facts['chains'][0][0] ?? null;
            $section = $facts['dev'] ? 'require-dev' : 'require';
            $require = \is_array($root[$section] ?? null) ? $root[$section] : [];
            if ($head !== null && !isset($require[$head])) {
                $root[$section] = $require + [$head => '*'];
            }
        }
        $phpLock = \is_array($inputs['php_lock'] ?? null) ? $inputs['php_lock'] : [];
        foreach (\is_array($phpLock['root'] ?? null) ? $phpLock['root'] : [] as $section => $links) {
            $root[(string) $section] = (\is_array($links) ? $links : []) + (\is_array($root[$section] ?? null) ? $root[$section] : []);
        }
        if (\is_string($inputs['config_platform_php'] ?? null)) {
            $config = \is_array($root['config'] ?? null) ? $root['config'] : [];
            $platform = \is_array($config['platform'] ?? null) ? $config['platform'] : [];
            $root['config'] = ['platform' => ['php' => $inputs['config_platform_php']] + $platform] + $config;
        }

        return $root;
    }

    /**
     * The root as the lock was written for it, when the root requires a package that the lock does
     * not hold: that require came after the lock (case lock-out-of-sync‡). Null when the lock
     * holds every package that the root requires, so the lock has no content-hash to compare.
     *
     * @param array<string, mixed>              $root
     * @param array{packages: list<array<mixed, mixed>>, packages-dev?: list<array<mixed, mixed>>} $lock
     *
     * @return array<string, mixed>|null
     */
    private static function unlockedRoot(array $root, array $lock): ?array
    {
        $locked = array_map('strtolower', array_filter(array_column(array_merge($lock['packages'], $lock['packages-dev'] ?? []), 'name'), 'is_string'));
        $before = $root;
        foreach (['require', 'require-dev'] as $section) {
            $links = \is_array($root[$section] ?? null) ? $root[$section] : [];
            $before[$section] = array_filter($links, static fn ($name): bool => PlatformRepository::isPlatformPackage((string) $name) || \in_array(strtolower((string) $name), $locked, true), \ARRAY_FILTER_USE_KEY);
            if ($before[$section] === [] && !isset($root[$section])) {
                unset($before[$section]);
            }
        }

        return $before === $root ? null : $before;
    }

    /**
     * The project that reads $root as its composer.json, so that lockrot compares the lock's
     * content-hash with it.
     *
     * @param array<mixed> $root
     */
    private static function manifest(array $root): ProjectConfig
    {
        $path = tempnam(sys_get_temp_dir(), 'lockrot-case-root');
        if ($path === false) {
            throw new \RuntimeException('no temporary file for the case root');
        }
        try {
            file_put_contents($path, JsonFile::encode($root));

            return ProjectConfig::fromFile($path);
        } finally {
            unlink($path);
        }
    }

    /**
     * @param array<string, array<mixed, mixed>>                                                          $entries  by name
     * @param array<string, array{abandoned: array<mixed, mixed>|null, activity: array<mixed, mixed>|null}> $packages S1's marking (`by` the repository or the lock, and the replacement) and the repository of each package that has facts
     *
     * @param array<mixed, mixed>                                                                         $inputs   the case's `inputs` block: `php_lock` adds the lock facts of `_php_lock`
     *
     * @return array{packages: list<array<mixed, mixed>>, packages-dev?: list<array<mixed, mixed>>, platform: array<mixed, mixed>, platform-dev: array<mixed, mixed>, platform-overrides: array<mixed, mixed>}
     */
    private static function lockData(array $entries, array $packages, array $inputs): array
    {
        $out = [];
        $dev = [];
        // Composer writes each section sorted by name.
        ksort($entries, \SORT_STRING);
        foreach ($entries as $name => $entry) {
            $require = \is_array($entry['require'] ?? null) ? $entry['require'] : [];
            if (\is_string($entry['php'] ?? null)) {
                $require = ['php' => $entry['php']] + $require;
            }
            $locked = ['name' => $entry['name'], 'version' => $entry['version'], 'require' => $require] + array_intersect_key($entry, ['version_normalized' => true, 'conflict' => true, 'provide' => true, 'replace' => true]);
            $marking = $packages[$name]['abandoned'] ?? null;
            if ($marking !== null && ($marking['by'] ?? null) === 'lock') {
                $locked['abandoned'] = $marking['replacement'] ?? true;
            }
            if (\is_string($entry['time'] ?? null)) {
                $locked['time'] = $entry['time'];
            }
            if (\in_array($entry['origin'] ?? 'packagist', ['packagist', 'composer'], true)) {
                $locked['notification-url'] = self::PACKAGIST;
            }
            if (($entry['origin'] ?? null) === 'path') {
                $locked['dist'] = ['type' => 'path', 'url' => '../'.$name];
            }
            $activity = $packages[$name]['activity'] ?? null;
            if ($activity !== null) {
                $locked['source'] = ['type' => 'git', 'url' => self::sourceUrl($activity), 'reference' => 'case'];
            }
            if (($entry['dev'] ?? false) === true) {
                $dev[] = $locked;
            } else {
                $out[] = $locked;
            }
        }

        $phpLock = \is_array($inputs['php_lock'] ?? null) ? $inputs['php_lock'] : [];
        $overrides = ['packages' => self::sectionEntries($phpLock['packages'] ?? null), 'packages-dev' => self::sectionEntries($phpLock['packages-dev'] ?? null)];
        $names = array_column(array_merge(...array_values($overrides)), 'name');
        $existing = array_column(array_merge($out, $dev), null, 'name');
        $lock = ['packages' => self::byName($out, $overrides['packages'], $names, $existing), 'packages-dev' => self::byName($dev, $overrides['packages-dev'], $names, $existing)];
        if (($phpLock['has_dev_key'] ?? true) === false) {
            unset($lock['packages-dev']);
        }
        $run = \is_array($inputs['run'] ?? null) ? $inputs['run'] : [];
        // A run with no composer.json reads the root's php from the lock's `platform`, as Composer wrote it.
        $platform = !\is_array($inputs['root'] ?? null) && \is_string($run['project_php'] ?? null) ? ['php' => $run['project_php']] : [];
        foreach (['platform' => $platform, 'platform-dev' => [], 'platform-overrides' => []] as $key => $own) {
            $extra = \is_array($phpLock[$key] ?? null) ? $phpLock[$key] : [];
            $lock[$key] = $extra + $own;
        }

        return $lock;
    }

    /**
     * @param mixed $section
     *
     * @return list<array<mixed, mixed>>
     */
    private static function sectionEntries($section): array
    {
        return \is_array($section) ? array_values(array_filter($section, 'is_array')) : [];
    }

    /**
     * The entries of one lock section, with the `_php_lock` entries of that section added. An entry
     * that `_php_lock` overrides in either section leaves its own section, so a moved package is
     * listed once. The `_php_lock` entry replaces only the keys it gives: the package keeps its
     * repository and its time.
     *
     * @param list<array<mixed, mixed>>          $entries
     * @param list<array<mixed, mixed>>          $extra
     * @param list<mixed>                        $overridden the names of every `_php_lock` entry
     * @param array<array-key, array<mixed, mixed>> $existing   every entry of the lock, by name
     *
     * @return list<array<mixed, mixed>>
     */
    private static function byName(array $entries, array $extra, array $overridden, array $existing): array
    {
        $kept = array_values(array_filter($entries, static fn (array $entry): bool => !\in_array($entry['name'] ?? null, $overridden, true)));

        return array_merge($kept, array_map(static fn (array $entry): array => $entry + ($existing[\is_string($entry['name'] ?? null) ? $entry['name'] : ''] ?? []), $extra));
    }

    /**
     * @param array<mixed, mixed>      $releases
     * @param array<mixed, mixed>|null $marking  S1's marking: a registry marking marks every release
     * @param ?string                  $source   the repository of the package's lock entry
     *
     * @return list<BasePackage>
     */
    private static function releases(string $package, array $releases, ?array $marking, ?string $registry = null, ?string $source = null): array
    {
        $versions = [];
        $aliases = [];
        foreach ($releases as $release) {
            self::assertArray($release);
            $version = JsonPath::stringAt($release, ['version']);
            // A null time is an undated release: Composer's loader reads one without a `time` key.
            $data = [
                'name' => $package,
                'version' => $version,
                'require' => (\is_string($release['php'] ?? null) ? ['php' => $release['php']] : []) + (\is_array($release['require'] ?? null) ? $release['require'] : []),
            ] + (\is_string($release['time'] ?? null) ? ['time' => $release['time']] : [])
              + (\is_array($release['replace'] ?? null) ? ['replace' => $release['replace']] : [])
              + ($marking !== null && ($marking['by'] ?? null) === 'repository' ? ['abandoned' => $marking['replacement'] ?? true] : [])
              + ($registry !== null ? ['notification-url' => $registry] : []);
            if (($release['shared_commit'] ?? false) === true) {
                // The shared-commit releases of one date sit on one commit, as a subtree split tags them. Only stable
                // tags count towards PackageMetadata::SHARED_COMMIT_TAGS, so a pre-release needs stable tags of its date.
                $data['source'] = ['type' => 'git', 'url' => $source ?? 'https://github.com/'.$package.'.git', 'reference' => 'shared-'.(\is_string($release['time'] ?? null) ? $release['time'] : $version)];
                $bare = ltrim($version, 'v');
                // Two more spellings of a stable release on its commit reach SHARED_COMMIT_TAGS alone.
                foreach (VersionParser::parseStability($version) === 'stable' ? ['v' === $version[0] ? $bare : 'v'.$bare, $bare.'.0'] : [] as $alias) {
                    $aliases[] = (new ArrayLoader())->load(['version' => $alias] + $data);
                }
            }
            $versions[] = (new ArrayLoader())->load($data);
        }

        return array_merge($versions, $aliases);
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
     * @param array<mixed, mixed> $records the case's `security.ignored[]`: `{id, cve, title, severity, by, matched, reason}`
     *
     * @return list<IgnoredAdvisory>
     */
    private static function ignored(array $records): array
    {
        $ignored = [];
        foreach ($records as $record) {
            self::assertArray($record);
            $advisory = new Advisory(JsonPath::stringAt($record, ['id']), self::optional($record, 'cve'), self::optional($record, 'title'), null, self::optional($record, 'severity'), null, null);
            $matched = JsonPath::stringAt($record, ['matched']);
            $key = $matched === AdvisoryIgnoreMatch::CVE ? self::optional($record, 'cve') : JsonPath::stringAt($record, ['id']);
            $ignored[] = new IgnoredAdvisory($advisory, new AdvisoryIgnoreMatch($matched, (string) $key, self::optional($record, 'reason'), JsonPath::stringAt($record, ['by'])));
        }

        return $ignored;
    }

    /**
     * @param array<mixed, mixed> $releases
     * @param array<mixed, mixed> $dependent the dependent's releases, each with its `require`
     *
     * @return array<mixed, mixed> $releases, each with the `require` of the same version of $dependent, then its other versions
     */
    private static function withRequires(array $releases, array $dependent): array
    {
        $out = [];
        foreach ($releases as $release) {
            self::assertArray($release);
            foreach ($dependent as $i => $other) {
                self::assertArray($other);
                if (($other['version'] ?? null) === $release['version']) {
                    $release['require'] = $other['require'] ?? [];
                    unset($dependent[$i]);
                }
            }
            $out[] = $release;
        }

        return array_merge($out, array_values($dependent));
    }

    /** @param array<mixed, mixed> $activity */
    private static function sourceUrl(array $activity): string
    {
        return 'https://'.JsonPath::stringAt($activity, ['host']).'/'.JsonPath::stringAt($activity, ['repo']).'.git';
    }

    /**
     * The credentials that the forge's answer needs. A GitHub repository that lockrot did not ask
     * for want of a token gets none. lockrot asks Bitbucket only with credentials, and GitLab
     * writes the archived flag only to an authenticated request, so such a read has Composer's
     * credentials for the host.
     *
     * @param array<mixed, mixed>|null $activity
     */
    private static function auth(?array $activity): ForgeAuth
    {
        $github = ($activity['reason'] ?? null) === NotCheckedRule::NO_TOKEN ? null : 'recorded';
        $forge = $activity['forge'] ?? null;
        $credentials = ($activity['status'] ?? null) === 'read' && ($forge === RepoRef::BITBUCKET || ($forge === RepoRef::GITLAB && ($activity['archived'] ?? null) !== null));
        $host = $activity['host'] ?? null;

        return new ForgeAuth(new Tokens($github, null), static fn (string $asked): bool => $credentials && $asked === $host, static fn (): bool => $credentials && $forge === RepoRef::BITBUCKET);
    }

    /** @param list<array<mixed, mixed>|null> $activities a GitLab host other than gitlab.com is a configured `gitlab-domains` entry */
    private static function locator(array $activities): RepoLocator
    {
        $domains = ['gitlab.com'];
        foreach ($activities as $activity) {
            $host = $activity['host'] ?? null;
            if (($activity['forge'] ?? null) === RepoRef::GITLAB && \is_string($host) && !\in_array($host, $domains, true)) {
                $domains[] = $host;
            }
        }

        return new RepoLocator($domains);
    }

    /**
     * The forge's answer to each request that lockrot makes: the activity of a read, a 404 for a
     * repository not found, a 429 for a rate limit, and nothing for a repository not asked.
     *
     * @param list<array<mixed, mixed>|null> $activities
     *
     * @return array<string, HttpResult>
     */
    private static function activityAnswers(array $activities, RepoLocator $locator, ForgeAuth $auth): array
    {
        $answers = [];
        foreach ($activities as $activity) {
            $answers += $activity === null ? [] : self::answersOf($activity, $locator, $auth);
        }

        return $answers;
    }

    /**
     * @param array<mixed, mixed> $activity
     *
     * @return array<string, HttpResult>
     */
    private static function answersOf(array $activity, RepoLocator $locator, ForgeAuth $auth): array
    {
        $repo = $locator->locate(self::sourceUrl($activity));
        if ($repo === null) {
            return [];
        }
        $api = [RepoRef::GITHUB => new GitHubApi(), RepoRef::GITLAB => new GitLabApi(), RepoRef::BITBUCKET => new BitbucketApi()][$repo->forge()];
        $pushedAt = $activity['pushed_at'] ?? null;
        $bodies = [
            'repo' => ['archived' => $activity['archived'] ?? null, 'pushed_at' => $pushedAt],
            'commits' => $repo->forge() === RepoRef::BITBUCKET ? ['values' => [['date' => $pushedAt]]] : [['committed_date' => $pushedAt]],
            'project' => ['archived' => $activity['archived'] ?? null],
        ];
        $answers = [];
        foreach ($api->requests($repo, $auth->isAuthenticated($repo)) as $role => $url) {
            if ($activity['status'] === 'not_found') {
                $cachedAt = $activity['cached_at'] ?? null;
                $answers[$url] = \is_string($cachedAt)
                    ? (new HttpResult($url, 404, null, new \DateTimeImmutable($cachedAt)))->asCached()
                    : FakeHttpClient::status($url, 404);
            } elseif (($activity['reason'] ?? null) === NotCheckedRule::RATE_LIMIT) {
                $answers[$url] = FakeHttpClient::status($url, 429);
            } elseif ($activity['status'] === 'read') {
                $answers[$url] = FakeHttpClient::ok($url, (string) json_encode($bodies[$role]));
            }
        }

        return $answers;
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
     * @param array<mixed, mixed> $run
     *
     * @return list<array{composer_repository: string, reason: string, message: ?string}>
     */
    private static function advisoryFailures(array $run): array
    {
        $failures = [];
        foreach (\is_array($run['advisory_failures'] ?? null) ? $run['advisory_failures'] : [] as $failure) {
            self::assertArray($failure);
            $failures[] = ['composer_repository' => JsonPath::stringAt($failure, ['composer_repository']), 'reason' => JsonPath::stringAt($failure, ['reason']), 'message' => self::optional($failure, 'message')];
        }

        return $failures;
    }

    /**
     * @param array<mixed, mixed> $run
     *
     * @return array<string, int> the records of the case package in each answered repository beside packagist, by repository
     */
    private static function advisoryAnswers(array $run): array
    {
        $answers = [];
        foreach (\is_array($run['advisory_answers'] ?? null) ? $run['advisory_answers'] : [] as $answer) {
            self::assertArray($answer);
            $answers[JsonPath::stringAt($answer, ['composer_repository'])] = JsonPath::intAt($answer, ['records']);
        }

        return $answers;
    }

    /**
     * Each package's counted advisories, the ignored ones and the feeds' records: `records` counts
     * every advisory of the name on any version, else the advisories given count.
     *
     * @param array<string, array{advisories: list<Advisory>, records: ?int, ignored: list<IgnoredAdvisory>}> $packages
     * @param list<Advisory>                                                                                 $others    those on other versions of the case's package
     * @param ?string                                                                                        $unchecked why the lookup did not complete, null when it answered. Packagist answers a `lookup_failed` lookup, because another repository failed
     * @param list<array{composer_repository: string, reason: string, message: ?string}>                   $failures  the repositories that failed beside the answered packagist one, as the model's run lists them
     * @param array<string, int>                                                                             $answers   the case package's records in each answered repository beside packagist, which packagist does not hold
     */
    private static function advisoryLoader(array $packages, array $others, ?string $unchecked, array $failures, array $answers = []): AdvisoryLoaderInterface
    {
        $repository = 'composer repo (https://repo.packagist.org)';
        $answered = $unchecked === null || $unchecked === AdvisoryCoverage::LOOKUP_FAILED;
        $own = $answers;
        $failedFeeds = array_map(static fn (array $failure): AdvisoryFeed => AdvisoryFeed::failed($failure['composer_repository'], $failure['message']), $failures);
        $counted = [];
        $ignored = [];
        $every = [];
        $names = [];
        $total = 0;
        $withRecords = 0;
        foreach ($packages as $name => $facts) {
            $all = array_merge($facts['advisories'], $others);
            $others = [];
            $records = $facts['records'] ?? \count(array_unique(array_map(static fn (Advisory $advisory): string => $advisory->id(), $all)));
            $answeredFeeds = [];
            foreach (array_keys($answers) as $other) {
                $answeredFeeds[] = AdvisoryFeed::answered($other, $own[$other] ?? 0);
            }
            $packagist = $records - array_sum($own);
            $own = [];
            $total += $packagist;
            $withRecords += $packagist === 0 ? 0 : 1;
            $names[$name] = new AdvisoryNameCoverage($answered ? array_merge($failedFeeds, $answeredFeeds, [AdvisoryFeed::answered($repository, $packagist)]) : [], $answered ? $records : null, $unchecked);
            $counted += $facts['advisories'] === [] ? [] : [$name => $facts['advisories']];
            $ignored += $facts['ignored'] === [] ? [] : [$name => $facts['ignored']];
            $every += $all === [] ? [] : [$name => $all];
        }
        $failed = array_map(static fn (array $failure): AdvisoryRepository => AdvisoryRepository::of($failure['composer_repository'], AdvisoryRepository::FAILED, $failure['reason'], $failure['message']), $failures);
        $besides = [];
        foreach ($answers as $other => $count) {
            $besides[] = AdvisoryRepository::of($other, AdvisoryRepository::ANSWERED)->withRecords($count, $count === 0 ? 0 : 1);
        }
        $repositories = array_merge($failed, $besides, [$answered ? AdvisoryRepository::of($repository, AdvisoryRepository::ANSWERED)->withRecords($total, $withRecords) : AdvisoryRepository::of($repository, AdvisoryRepository::NOT_ASKED, $unchecked)]);
        $coverage = new AdvisoryCoverage(AdvisoryCoverage::SCOPE_ALL, AdvisoryCoverage::SOURCE_DEFAULT, $repositories, 0, $names);
        $notes = array_map(static fn (array $failure): RunNote => RunNote::advisoriesUnavailable(
            $failure['composer_repository'],
            $failure['reason'] === AdvisoryCoverage::TRANSPORT ? new TransportException((string) $failure['message']) : new \RuntimeException((string) $failure['message'])
        ), $failures);
        $batch = new AdvisoryBatch($counted, $notes, $ignored, $every, $coverage, $unchecked === null);

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
